<?php

class SpecialAbuseLog extends SpecialPage {
	/**
	 * @var User
	 */
	protected $mSearchUser;

	/**
	 * @var Title
	 */
	protected $mSearchTitle;

	protected $mSearchWiki;

	protected $mSearchFilter;

	public function __construct() {
		parent::__construct( 'AbuseLog', 'abusefilter-log' );
	}

	public function execute( $parameter ) {
		$out = $this->getOutput();
		$request = $this->getRequest();

		AbuseFilter::addNavigationLinks( $this->getContext(), 'log' );

		$this->setHeaders();
		$this->outputHeader( 'abusefilter-log-summary' );
		$this->loadParameters();

		$out->setPageTitle( $this->msg( 'abusefilter-log' ) );
		$out->setRobotPolicy( "noindex,nofollow" );
		$out->setArticleRelated( false );
		$out->enableClientCache( false );

		$out->addModuleStyles( 'ext.abuseFilter' );

		// Are we allowed?
		$errors = $this->getTitle()->getUserPermissionsErrors(
			'abusefilter-log', $this->getUser(), true, array( 'ns-specialprotected' ) );
		if ( count( $errors ) ) {
			// Go away.
			$out->showPermissionsErrorPage( $errors, 'abusefilter-log' );
			return;
		}

		$detailsid = $request->getIntOrNull( 'details' );
		$hideid = $request->getIntOrNull( 'hide' );

		if ( $parameter ) {
			$detailsid = $parameter;
		}

		if ( $detailsid ) {
			$this->showDetails( $detailsid );
		} elseif ( $hideid ) {
			$this->showHideForm( $hideid );
		} else {
			// Show the search form.
			$this->searchForm();

			// Show the log itself.
			$this->showList();
		}
	}

	function loadParameters() {
		global $wgAbuseFilterIsCentral;

		$request = $this->getRequest();

		$this->mSearchUser = $request->getText( 'wpSearchUser' );
		if ( $wgAbuseFilterIsCentral ) {
			$this->mSearchWiki = $request->getText( 'wpSearchWiki' );
		}

		$t = Title::newFromText( trim( $this->mSearchUser ) );
		if ( $t ) {
			$this->mSearchUser = $t->getText(); // Username normalisation
		} else {
			$this->mSearchUser = null;
		}

		$this->mSearchTitle = $request->getText( 'wpSearchTitle' );
		$this->mSearchFilter = null;
		if ( self::canSeeDetails() ) {
			$this->mSearchFilter = $request->getIntOrNull( 'wpSearchFilter' );
		}
	}

	function searchForm() {
		global $wgAbuseFilterIsCentral;

		$output = Xml::element( 'legend', null, $this->msg( 'abusefilter-log-search' )->text() );
		$fields = array();

		// Search conditions
		$fields['abusefilter-log-search-user'] =
			Xml::input( 'wpSearchUser', 45, $this->mSearchUser );
		if ( self::canSeeDetails() ) {
			$fields['abusefilter-log-search-filter'] =
				Xml::input( 'wpSearchFilter', 45, $this->mSearchFilter );
		}
		$fields['abusefilter-log-search-title'] =
			Xml::input( 'wpSearchTitle', 45, $this->mSearchTitle );

		if ( $wgAbuseFilterIsCentral ) {
			// Add free form input for wiki name. Would be nice to generate
			// a select with unique names in the db at some point.
			$fields['abusefilter-log-search-wiki'] =
				Xml::input( 'wpSearchWiki', 45, $this->mSearchWiki );
		}

		$output .= Xml::tags( 'form',
			array( 'method' => 'get', 'action' => $this->getTitle()->getLocalURL() ),
			Xml::buildForm( $fields, 'abusefilter-log-search-submit' )
		);
		$output = Xml::tags( 'fieldset', null, $output );

		$this->getOutput()->addHTML( $output );
	}

	/**
	 * @param $id
	 * @return mixed
	 */
	function showHideForm( $id ) {
		if ( !$this->getUser()->isAllowed( 'abusefilter-hide-log' ) ) {
			$this->getOutput()->addWikiMsg( 'abusefilter-log-hide-forbidden' );
			return;
		}

		$dbr = wfGetDB( DB_SLAVE );

		$row = $dbr->selectRow(
			array( 'abuse_filter_log', 'abuse_filter' ),
			'*',
			array( 'afl_id' => $id ),
			__METHOD__,
			array(),
			array( 'abuse_filter' => array( 'LEFT JOIN', 'af_id=afl_filter' ) )
		);

		if ( !$row ) {
			return;
		}

		$formInfo = array(
			'logid' => array(
				'type' => 'info',
				'default' => $id,
				'label-message' => 'abusefilter-log-hide-id',
			),
			'reason' => array(
				'type' => 'text',
				'label-message' => 'abusefilter-log-hide-reason',
			),
			'hidden' => array(
				'type' => 'toggle',
				'default' => $row->afl_deleted,
				'label-message' => 'abusefilter-log-hide-hidden',
			),
		);

		$form = new HTMLForm( $formInfo, $this->getContext() );
		$form->setTitle( $this->getTitle() );
		$form->setWrapperLegend( $this->msg( 'abusefilter-log-hide-legend' )->text() );
		$form->addHiddenField( 'hide', $id );
		$form->setSubmitCallback( array( $this, 'saveHideForm' ) );
		$form->show();
	}

	/**
	 * @param $fields
	 * @return bool
	 */
	function saveHideForm( $fields ) {
		$logid = $this->getRequest()->getVal( 'hide' );

		$dbw = wfGetDB( DB_MASTER );

		$dbw->update(
			'abuse_filter_log',
			array( 'afl_deleted' => $fields['hidden'] ),
			array( 'afl_id' => $logid ),
			__METHOD__
		);

		$logPage = new LogPage( 'suppress' );
		$action = $fields['hidden'] ? 'hide-afl' : 'unhide-afl';

		$logPage->addEntry( $action, $this->getTitle( $logid ), $fields['reason'] );

		$this->getOutput()->redirect( SpecialPage::getTitleFor( 'AbuseLog' )->getFullURL() );

		return true;
	}

	function showList() {
		$out = $this->getOutput();

		// Generate conditions list.
		$conds = array();

		if ( $this->mSearchUser ) {
			$user = User::newFromName( $this->mSearchUser );

			if ( !$user ) {
				$conds[] = 'afl_ip=afl_user_text';
				$conds['afl_user'] = 0;
				$conds['afl_user_text'] = $this->mSearchUser;
			} else {
				$conds['afl_user'] = $user->getId();
				$conds['afl_user_text'] = $user->getName();
			}
		}

		if ( $this->mSearchWiki ) {
			$conds['afl_wiki'] = $this->mSearchWiki;
		}

		if ( $this->mSearchFilter ) {
			// if the filter is hidden, users who can't view private filters should not be able to find log entries generated by it
			if ( !AbuseFilter::filterHidden( $this->mSearchFilter )
				|| AbuseFilterView::canViewPrivate()
				|| $this->getUser()->isAllowed( 'abusefilter-log-private' )
			) {
				$conds['afl_filter'] = $this->mSearchFilter;
			}
		}

		$searchTitle = Title::newFromText( $this->mSearchTitle );
		if ( $this->mSearchTitle && $searchTitle ) {
			$conds['afl_namespace'] = $searchTitle->getNamespace();
			$conds['afl_title'] = $searchTitle->getDBkey();
		}

		$pager = new AbuseLogPager( $this, $conds );
		$pager->doQuery();
		$result = $pager->getResult();
		if( $result && $result->numRows() !== 0 ) {
			$out->addHTML( $pager->getNavigationBar() .
					Xml::tags( 'ul', null, $pager->getBody() ) .
					$pager->getNavigationBar() );
		} else {
			$out->addWikiMsg( 'abusefilter-log-noresults' );
		}
	}

	/**
	 * @param $id
	 * @return mixed
	 */
	function showDetails( $id ) {
		$out = $this->getOutput();

		$dbr = wfGetDB( DB_SLAVE );

		$row = $dbr->selectRow(
			array( 'abuse_filter_log', 'abuse_filter' ),
			'*',
			array( 'afl_id' => $id ),
			__METHOD__,
			array(),
			array( 'abuse_filter' => array( 'LEFT JOIN', 'af_id=afl_filter' ) )
		);

		if ( !$row ) {
			return;
		}

		if ( AbuseFilter::decodeGlobalName( $row->afl_filter ) ) {
			$filter_hidden = null;
		} else {
			$filter_hidden = $row->af_hidden;
		}

		if ( !self::canSeeDetails( $row->afl_filter, $filter_hidden ) ) {
			$out->addWikiMsg( 'abusefilter-log-cannot-see-details' );
			return;
		}

		if ( self::isHidden( $row ) && !self::canSeeHidden() ) {
			$out->addWikiMsg( 'abusefilter-log-details-hidden' );
			return;
		}

		$output = '';

		$output .= Xml::element(
			'legend',
			null,
			$this->msg( 'abusefilter-log-details-legend', $id )->text()
		);
		$output .= Xml::tags( 'p', null, $this->formatRow( $row, false ) );

		// Load data
		$vars = AbuseFilter::loadVarDump( $row->afl_var_dump );

		// Diff, if available
		if ( $vars && $vars->getVar( 'action' )->toString() == 'edit' ) {
			$old_wikitext = $vars->getVar( 'old_wikitext' )->toString();
			$new_wikitext = $vars->getVar( 'new_wikitext' )->toString();

			$diffEngine = new DifferenceEngine;

			$diffEngine->showDiffStyle();
			$formattedDiff = $diffEngine->generateDiffBody( $old_wikitext, $new_wikitext );

			static $colDescriptions = "<col class='diff-marker' />
				<col class='diff-content' />
				<col class='diff-marker' />
				<col class='diff-content' />";

			$formattedDiff =
				"<table class='diff'>$colDescriptions<tbody>$formattedDiff</tbody></table>";

			$output .=
				Xml::tags(
					'h3',
					null,
					$this->msg( 'abusefilter-log-details-diff' )->parse()
				);

			$output .= $formattedDiff;
		}

		$output .= Xml::element( 'h3', null, $this->msg( 'abusefilter-log-details-vars' )->text() );

		// Build a table.
		$output .= AbuseFilter::buildVarDumpTable( $vars );

		if ( self::canSeePrivate() ) {
			// Private stuff, like IPs.
			$header =
				Xml::element( 'th', null, $this->msg( 'abusefilter-log-details-var' )->text() ) .
				Xml::element( 'th', null, $this->msg( 'abusefilter-log-details-val' )->text() );
			$output .= Xml::element( 'h3', null, $this->msg( 'abusefilter-log-details-private' )->text() );
			$output .=
				Xml::openElement( 'table',
					array(
						'class' => 'wikitable mw-abuselog-private',
						'style' => 'width: 80%;'
					)
				) .
				Xml::openElement( 'tbody' );
			$output .= $header;

			// IP address
			$output .=
				Xml::tags( 'tr', null,
					Xml::element( 'td',
						array( 'style' => 'width: 30%;' ),
						$this->msg( 'abusefilter-log-details-ip' )->text()
					) .
					Xml::element( 'td', null, $row->afl_ip )
				);

			$output .= Xml::closeElement( 'tbody' ) . Xml::closeElement( 'table' );
		}

		$output = Xml::tags( 'fieldset', null, $output );

		$out->addHTML( $output );
	}

	/**
	 * @param $filter_id null
	 * @param $filter_hidden null
	 * @return bool
	 */
	static function canSeeDetails( $filter_id = null, $filter_hidden = null ) {
		global $wgUser;

		if ( $filter_id !== null ) {
			if ( $filter_hidden === null ) {
				$filter_hidden = AbuseFilter::filterHidden( $filter_id );
			}
			if ( $filter_hidden ) {
				return $wgUser->isAllowed( 'abusefilter-log-detail' ) && (
					AbuseFilterView::canViewPrivate() || $wgUser->isAllowed( 'abusefilter-log-private' )
				);
			}
		}

		return $wgUser->isAllowed( 'abusefilter-log-detail' );
	}

	/**
	 * @return bool
	 */
	static function canSeePrivate() {
		global $wgUser;
		return $wgUser->isAllowed( 'abusefilter-private' );
	}

	/**
	 * @return bool
	 */
	static function canSeeHidden() {
		global $wgUser;
		return $wgUser->isAllowed( 'abusefilter-hidden-log' );
	}

	/**
	 * @param $row
	 * @param $li bool
	 * @return String
	 */
	function formatRow( $row, $li = true ) {
		$user = $this->getUser();
		$lang = $this->getLanguage();

		$actionLinks = array();

		$title = Title::makeTitle( $row->afl_namespace, $row->afl_title );

		$diffLink = false;

		if ( self::isHidden($row) && ! $this->canSeeHidden() ) {
			return '';
		}

		if ( !$row->afl_wiki ) {
			$pageLink = Linker::link( $title );
			if ( $row->afl_rev_id ) {
				$diffLink = Linker::link( $title,
					wfMessage('abusefilter-log-diff')->parse(), array(),
					array( 'diff' => 'prev', 'oldid' => $row->afl_rev_id ) );
			}
		} else {
			$pageLink = WikiMap::makeForeignLink( $row->afl_wiki, $row->afl_title );

			if ( $row->afl_rev_id ) {
				$diffUrl = WikiMap::getForeignURL( $row->afl_wiki, $row->afl_title );
				$diffUrl = wfAppendQuery( $diffUrl,
					array( 'diff' => 'prev', 'oldid' => $row->afl_rev_id ) );

				$diffLink = Linker::makeExternalLink( $diffUrl,
					wfMessage('abusefilter-log-diff')->parse() );
			}
		}

		if ( !$row->afl_wiki ) {
			// Local user
			$userLink = Linker::userLink( $row->afl_user, $row->afl_user_text ) .
					Linker::userToolLinks( $row->afl_user, $row->afl_user_text, true );
		} else {
			$userLink = WikiMap::foreignUserLink( $row->afl_wiki, $row->afl_user_text );
			$userLink .= ' (' . WikiMap::getWikiName( $row->afl_wiki ) . ')';
		}

		$timestamp = $lang->timeanddate( $row->afl_timestamp, true );

		$actions_taken = $row->afl_actions;
		if ( !strlen( trim( $actions_taken ) ) ) {
			$actions_taken = $this->msg( 'abusefilter-log-noactions' )->text();
		} else {
			$actions = explode( ',', $actions_taken );
			$displayActions = array();

			foreach ( $actions as $action ) {
				$displayActions[] = AbuseFilter::getActionDisplay( $action );
			}
			$actions_taken = $lang->commaList( $displayActions );
		}

		$globalIndex = AbuseFilter::decodeGlobalName( $row->afl_filter );

		if ( $globalIndex ) {
			// Pull global filter description
			$parsed_comments =
				$this->getOutput()->parseInline( AbuseFilter::getGlobalFilterDescription( $globalIndex ) );
			$filter_hidden = null;
		} else {
			$parsed_comments = $this->getOutput()->parseInline( $row->af_public_comments );
			$filter_hidden = $row->af_hidden;
		}

		if ( self::canSeeDetails( $row->afl_filter, $filter_hidden ) ) {
			$examineTitle = SpecialPage::getTitleFor( 'AbuseFilter', 'examine/log/' . $row->afl_id );
			$detailsLink = Linker::linkKnown(
				$this->getTitle($row->afl_id),
				$this->msg( 'abusefilter-log-detailslink' )->escaped()
			);
			$examineLink = Linker::link(
				$examineTitle,
				$this->msg( 'abusefilter-changeslist-examine' )->parse(),
				array()
			);

			$actionLinks[] = $detailsLink;
			$actionLinks[] = $examineLink;

			if ($diffLink)
				$actionLinks[] = $diffLink;

			if ( $user->isAllowed( 'abusefilter-hide-log' ) ) {
				$hideLink = Linker::link(
					$this->getTitle(),
					$this->msg( 'abusefilter-log-hidelink' )->text(),
					array(),
					array( 'hide' => $row->afl_id )
				);

				$actionLinks[] = $hideLink;
			}

			if ( $globalIndex ) {
				global $wgAbuseFilterCentralDB;
				$globalURL =
					WikiMap::getForeignURL( $wgAbuseFilterCentralDB,
											'Special:AbuseFilter/' . $globalIndex );

				$linkText = wfMessage( 'abusefilter-log-detailedentry-global' )->numParams( $globalIndex )->escaped();
				$filterLink = Linker::makeExternalLink( $globalURL, $linkText );
			} else {
				$title = SpecialPage::getTitleFor( 'AbuseFilter', $row->afl_filter );
				$linkText = wfMessage( 'abusefilter-log-detailedentry-local' )->numParams( $row->afl_filter )->escaped();
				$filterLink = Linker::link( $title, $linkText );
			}
			$description = $this->msg( 'abusefilter-log-detailedentry-meta' )->rawParams(
				$timestamp,
				$userLink,
				$filterLink,
				$row->afl_action,
				$pageLink,
				$actions_taken,
				$parsed_comments,
				$lang->pipeList( $actionLinks ),
				$row->afl_user_text
			)->parse();
		} else {
			$description = $this->msg( 'abusefilter-log-entry' )->rawParams(
				$timestamp,
				$userLink,
				$row->afl_action,
				Linker::link( $title ),
				$actions_taken,
				$parsed_comments
			)->parse();
		}

		if ( self::isHidden( $row ) === true ) {
			$description .= ' '.
				$this->msg( 'abusefilter-log-hidden' )->parse();
		} elseif ( self::isHidden($row) === 'implicit' ) {
			$description .= ' '.
				$this->msg( 'abusefilter-log-hidden-implicit' )->parse();
		}

		return $li ? Xml::tags( 'li', null, $description ) : $description;
	}

	/**
	 * @param $db DatabaseBase
	 * @return string
	 */
	public static function getNotDeletedCond( $db ) {
		$deletedZeroCond = $db->makeList(
				array( 'afl_deleted' => 0 ), LIST_AND );
		$deletedNullCond = $db->makeList(
				array( 'afl_deleted' => null ), LIST_AND );
		$notDeletedCond = $db->makeList(
			array( $deletedZeroCond, $deletedNullCond ), LIST_OR );

		return $notDeletedCond;
	}

	/**
	 * Given a log entry row, decides whether or not it can be viewed by the public.
	 *
	 * @param $row stdClass The abuse_filter_log row object.
	 *
	 * @return Mixed true if the item is explicitly hidden, false if it is not.
	 * 	The string 'implicit' if it is hidden because the corresponding revision is hidden.
	 */
	public static function isHidden( $row ) {
		if ( $row->afl_rev_id ) {
			$revision = Revision::newFromId( $row->afl_rev_id );
			if ( $revision && $revision->getVisibility() != 0 ) {
				return 'implicit';
			}
		}

		return (bool)$row->afl_deleted;
	}
}

class AbuseLogPager extends ReverseChronologicalPager {

	/**
	 * @var HtmlForm
	 */
	public $mForm;

	/**
	 * @var array
	 */
	public $mConds;

	/**
	 * @param $form
	 * @param array $conds
	 * @param bool $details
	 */
	function __construct( $form, $conds = array(), $details = false ) {
		$this->mForm = $form;
		$this->mConds = $conds;
		parent::__construct();
	}

	function formatRow( $row ) {
		return $this->mForm->formatRow( $row );
	}

	function getQueryInfo() {
		$conds = $this->mConds;

		$info = array(
			'tables' => array( 'abuse_filter_log', 'abuse_filter' ),
			'fields' => '*',
			'conds' => $conds,
			'join_conds' =>
				array( 'abuse_filter' =>
					array(
						'LEFT JOIN',
						'af_id=afl_filter',
					),
				),
		);

		if ( !$this->mForm->canSeeHidden() ) {
			$db = $this->mDb;
			$info['conds'][] = SpecialAbuseLog::getNotDeletedCond($db);
		}

		return $info;
	}

	function getIndexField() {
		return 'afl_timestamp';
	}
}
