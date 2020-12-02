<?php

namespace MediaWiki\Extension\AbuseFilter\Pager;

use AbuseFilter;
use HtmlArmor;
use IContextSource;
use Linker;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\CentralDBNotAvailableException;
use MediaWiki\Extension\AbuseFilter\GlobalNameUtils;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Permissions\PermissionManager;
use ReverseChronologicalPager;
use Sanitizer;
use SpecialAbuseLog;
use SpecialPage;
use stdClass;
use Title;
use WikiMap;
use Wikimedia\Rdbms\IResultWrapper;
use Xml;

class AbuseLogPager extends ReverseChronologicalPager {
	/**
	 * @var array
	 */
	public $mConds;

	/** @var LinkBatchFactory */
	private $linkBatchFactory;

	/** @var PermissionManager */
	private $permissionManager;

	/** @var AbuseFilterPermissionManager */
	private $afPermissionManager;

	/** @var string */
	private $basePageName;

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param array $conds
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param PermissionManager $permManager
	 * @param AbuseFilterPermissionManager $afPermissionManager
	 * @param string $basePageName
	 */
	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		array $conds,
		LinkBatchFactory $linkBatchFactory,
		PermissionManager $permManager,
		AbuseFilterPermissionManager $afPermissionManager,
		string $basePageName
	) {
		parent::__construct( $context, $linkRenderer );
		$this->mConds = $conds;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->permissionManager = $permManager;
		$this->afPermissionManager = $afPermissionManager;
		$this->basePageName = $basePageName;
	}

	/**
	 * @param stdClass $row
	 * @return string
	 */
	public function formatRow( $row ) {
		return $this->doFormatRow( $row );
	}

	/**
	 * @param stdClass $row
	 * @param bool $isListItem
	 * @return string
	 */
	public function doFormatRow( stdClass $row, bool $isListItem = true ) : string {
		$aflFilterMigrationStage = $this->getConfig()->get( 'AbuseFilterAflFilterMigrationStage' );
		$user = $this->getUser();
		$lang = $this->getLanguage();

		$title = Title::makeTitle( $row->afl_namespace, $row->afl_title );

		$diffLink = false;
		$isHidden = SpecialAbuseLog::isHidden( $row );

		// @todo T224203 Try to show the details if the revision is deleted but the AbuseLog entry
		// is not. However, watch out to avoid showing too much stuff.
		if ( !$this->afPermissionManager->canSeeHiddenLogEntries( $user ) && $isHidden ) {
			return '';
		}

		$linkRenderer = $this->getLinkRenderer();

		if ( !$row->afl_wiki ) {
			$pageLink = $linkRenderer->makeLink(
				$title,
				null,
				[],
				[ 'redirect' => 'no' ]
			);
			if ( $row->rev_id ) {
				$diffLink = $linkRenderer->makeKnownLink(
					$title,
					new HtmlArmor( $this->msg( 'abusefilter-log-diff' )->parse() ),
					[],
					[ 'diff' => 'prev', 'oldid' => $row->rev_id ]
				);
			} elseif (
				isset( $row->ar_timestamp ) && $row->ar_timestamp
				&& $this->canSeeUndeleteDiffForPage( $title )
			) {
				$diffLink = $linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( 'Undelete' ),
					new HtmlArmor( $this->msg( 'abusefilter-log-diff' )->parse() ),
					[],
					[
						'diff' => 'prev',
						'target' => $title->getPrefixedText(),
						'timestamp' => $row->ar_timestamp,
					]
				);
			}
		} else {
			$pageLink = WikiMap::makeForeignLink( $row->afl_wiki, $row->afl_title );

			if ( $row->afl_rev_id ) {
				$diffUrl = WikiMap::getForeignURL( $row->afl_wiki, $row->afl_title );
				$diffUrl = wfAppendQuery( $diffUrl,
					[ 'diff' => 'prev', 'oldid' => $row->afl_rev_id ] );

				$diffLink = Linker::makeExternalLink( $diffUrl,
					$this->msg( 'abusefilter-log-diff' )->text() );
			}
		}

		if ( !$row->afl_wiki ) {
			// Local user
			$userLink = SpecialAbuseLog::getUserLinks( $row->afl_user, $row->afl_user_text );
		} else {
			$userLink = WikiMap::foreignUserLink( $row->afl_wiki, $row->afl_user_text );
			$userLink .= ' (' . WikiMap::getWikiName( $row->afl_wiki ) . ')';
		}

		$timestamp = htmlspecialchars( $lang->timeanddate( $row->afl_timestamp, true ) );

		$actions_takenRaw = $row->afl_actions;
		if ( !strlen( trim( $actions_takenRaw ) ) ) {
			$actions_taken = $this->msg( 'abusefilter-log-noactions' )->escaped();
		} else {
			$actions = explode( ',', $actions_takenRaw );
			$displayActions = [];

			$context = $this->getContext();
			foreach ( $actions as $action ) {
				$displayActions[] = AbuseFilter::getActionDisplay( $action, $context );
			}
			$actions_taken = $lang->commaList( $displayActions );
		}

		if ( $aflFilterMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
			$filterID = $row->afl_filter_id;
			$global = $row->afl_global;
		} else {
			// SCHEMA_COMPAT_READ_OLD
			list( $filterID, $global ) = GlobalNameUtils::splitGlobalName( $row->afl_filter );
		}

		if ( $global ) {
			// Pull global filter description
			$lookup = AbuseFilterServices::getFilterLookup();
			try {
				$filterObj = $lookup->getFilter( $filterID, true );
				$globalDesc = $filterObj->getName();
				$escaped_comments = Sanitizer::escapeHtmlAllowEntities( $globalDesc );
				$filter_hidden = $filterObj->isHidden();
			} catch ( CentralDBNotAvailableException $_ ) {
				$escaped_comments = $this->msg( 'abusefilter-log-description-not-available' )->escaped();
				// either hide all filters, including not hidden, or show all, including hidden
				// we choose the former
				$filter_hidden = true;
			}
		} else {
			$escaped_comments = Sanitizer::escapeHtmlAllowEntities(
				$row->af_public_comments );
			$filter_hidden = $row->af_hidden;
		}

		if ( $this->afPermissionManager->canSeeLogDetailsForFilter( $user, $filter_hidden ) ) {
			$actionLinks = [];

			if ( $isListItem ) {
				$detailsLink = $linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( $this->basePageName, $row->afl_id ),
					$this->msg( 'abusefilter-log-detailslink' )->text()
				);
				$actionLinks[] = $detailsLink;
			}

			$examineTitle = SpecialPage::getTitleFor( 'AbuseFilter', 'examine/log/' . $row->afl_id );
			$examineLink = $linkRenderer->makeKnownLink(
				$examineTitle,
				new HtmlArmor( $this->msg( 'abusefilter-changeslist-examine' )->parse() )
			);
			$actionLinks[] = $examineLink;

			if ( $diffLink ) {
				$actionLinks[] = $diffLink;
			}

			if ( $this->afPermissionManager->canHideAbuseLog( $user ) ) {
				$hideLink = $linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( $this->basePageName, 'hide' ),
					$this->msg( 'abusefilter-log-hidelink' )->text(),
					[],
					[ 'id' => $row->afl_id ]
				);

				$actionLinks[] = $hideLink;
			}

			if ( $global ) {
				$centralDb = $this->getConfig()->get( 'AbuseFilterCentralDB' );
				$linkMsg = $this->msg( 'abusefilter-log-detailedentry-global' )
					->numParams( $filterID );
				if ( $centralDb !== null ) {
					$globalURL = WikiMap::getForeignURL(
						$centralDb,
						'Special:AbuseFilter/' . $filterID
					);
					$filterLink = Linker::makeExternalLink( $globalURL, $linkMsg->text() );
				} else {
					$filterLink = $linkMsg->escaped();
				}
			} else {
				$title = SpecialPage::getTitleFor( 'AbuseFilter', (string)$filterID );
				$linkText = $this->msg( 'abusefilter-log-detailedentry-local' )
					->numParams( $filterID )->text();
				$filterLink = $linkRenderer->makeKnownLink( $title, $linkText );
			}
			$description = $this->msg( 'abusefilter-log-detailedentry-meta' )->rawParams(
				$timestamp,
				$userLink,
				$filterLink,
				htmlspecialchars( $row->afl_action ),
				$pageLink,
				$actions_taken,
				$escaped_comments,
				$lang->pipeList( $actionLinks )
			)->params( $row->afl_user_text )->parse();
		} else {
			if ( $diffLink ) {
				$msg = 'abusefilter-log-entry-withdiff';
			} else {
				$msg = 'abusefilter-log-entry';
			}
			$description = $this->msg( $msg )->rawParams(
				$timestamp,
				$userLink,
				htmlspecialchars( $row->afl_action ),
				$pageLink,
				$actions_taken,
				$escaped_comments,
				// Passing $7 to 'abusefilter-log-entry' will do nothing, as it's not used.
				$diffLink
			)->params( $row->afl_user_text )->parse();
		}

		$attribs = null;
		if ( $isHidden === true ) {
			$attribs = [ 'class' => 'mw-abusefilter-log-hidden-entry' ];
		} elseif ( $isHidden === 'implicit' ) {
			$description .= ' ' .
				$this->msg( 'abusefilter-log-hidden-implicit' )->parse();
		}

		if ( $isListItem ) {
			return Xml::tags( 'li', $attribs, $description );
		} else {
			return Xml::tags( 'span', $attribs, $description );
		}
	}

	/**
	 * Can this user see diffs generated by Special:Undelete for the page?
	 * @see \SpecialUndelete
	 * @param LinkTarget $page
	 *
	 * @return bool
	 */
	private function canSeeUndeleteDiffForPage( LinkTarget $page ) : bool {
		if ( !$this->canSeeUndeleteDiffs() ) {
			return false;
		}

		foreach ( [ 'deletedtext', 'undelete' ] as $action ) {
			if ( $this->permissionManager->userCan(
				$action, $this->getUser(), $page, PermissionManager::RIGOR_QUICK
			) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Can this user see diffs generated by Special:Undelete?
	 * @see \SpecialUndelete
	 *
	 * @return bool
	 */
	private function canSeeUndeleteDiffs() : bool {
		if ( !$this->permissionManager->userHasRight( $this->getUser(), 'deletedhistory' ) ) {
			return false;
		}

		return $this->permissionManager->userHasAnyRight(
			$this->getUser(), 'deletedtext', 'undelete' );
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$afPermManager = AbuseFilterServices::getPermissionManager();
		$aflFilterMigrationStage = $this->getConfig()->get( 'AbuseFilterAflFilterMigrationStage' );

		$conds = $this->mConds;

		if ( $aflFilterMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
			$join = [ 'af_id=afl_filter_id', 'afl_global' => 0 ];
		} else {
			// SCHEMA_COMPAT_READ_OLD
			$join = 'af_id=afl_filter';
		}

		$info = [
			'tables' => [ 'abuse_filter_log', 'abuse_filter', 'revision' ],
			'fields' => [
				$this->mDb->tableName( 'abuse_filter_log' ) . '.*',
				$this->mDb->tableName( 'abuse_filter' ) . '.*',
				'rev_id',
			],
			'conds' => $conds,
			'join_conds' => [
				'abuse_filter' => [
					'LEFT JOIN',
					$join,
				],
				'revision' => [
					'LEFT JOIN',
					[
						'afl_wiki IS NULL',
						'afl_rev_id IS NOT NULL',
						'rev_id=afl_rev_id',
					]
				],
			],
		];

		if ( $this->canSeeUndeleteDiffs() ) {
			$info['tables'][] = 'archive';
			$info['fields'][] = 'ar_timestamp';
			$info['join_conds']['archive'] = [
				'LEFT JOIN',
				[
					'afl_wiki IS NULL',
					'afl_rev_id IS NOT NULL',
					'rev_id IS NULL',
					'ar_rev_id=afl_rev_id',
				]
			];
		}

		if ( !$afPermManager->canSeeHiddenLogEntries( $this->getUser() ) ) {
			$info['conds']['afl_deleted'] = 0;
		}

		return $info;
	}

	/**
	 * @param IResultWrapper $result
	 */
	protected function preprocessResults( $result ) {
		if ( $this->getNumRows() === 0 ) {
			return;
		}

		$lb = $this->linkBatchFactory->newLinkBatch();
		$lb->setCaller( __METHOD__ );
		foreach ( $result as $row ) {
			// Only for local wiki results
			if ( !$row->afl_wiki ) {
				$lb->add( $row->afl_namespace, $row->afl_title );
				$lb->add( NS_USER, $row->afl_user );
				$lb->add( NS_USER_TALK, $row->afl_user_text );
			}
		}
		$lb->execute();
		$result->seek( 0 );
	}

	/**
	 * @return string
	 */
	public function getIndexField() {
		return 'afl_timestamp';
	}
}
