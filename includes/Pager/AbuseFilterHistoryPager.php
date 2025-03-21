<?php

namespace MediaWiki\Extension\AbuseFilter\Pager;

use HtmlArmor;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\AbuseFilter\AbuseFilter;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseFilter;
use MediaWiki\Extension\AbuseFilter\SpecsFormatter;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\TablePager;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentityValue;
use UnexpectedValueException;
use Wikimedia\Rdbms\IResultWrapper;

class AbuseFilterHistoryPager extends TablePager {

	/** @var LinkBatchFactory */
	private $linkBatchFactory;

	/** @var FilterLookup */
	private $filterLookup;

	/** @var SpecsFormatter */
	private $specsFormatter;

	/** @var int|null The filter ID */
	private $filter;

	/** @var string|null The user whose changes we're looking up for */
	private $user;

	/** @var bool */
	private $canViewPrivateFilters;

	/** @var bool */
	private $canViewProtectedVars;

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param FilterLookup $filterLookup
	 * @param SpecsFormatter $specsFormatter
	 * @param ?int $filter
	 * @param ?string $user User name
	 * @param bool $canViewPrivateFilters
	 * @param bool $canViewProtectedVars
	 */
	public function __construct(
		IContextSource $context,
		LinkRenderer $linkRenderer,
		LinkBatchFactory $linkBatchFactory,
		FilterLookup $filterLookup,
		SpecsFormatter $specsFormatter,
		?int $filter,
		?string $user,
		bool $canViewPrivateFilters = false,
		bool $canViewProtectedVars = false
	) {
		// needed by parent's constructor call
		$this->filter = $filter;
		parent::__construct( $context, $linkRenderer );
		$this->linkBatchFactory = $linkBatchFactory;
		$this->filterLookup = $filterLookup;
		$this->specsFormatter = $specsFormatter;
		$this->user = $user;
		$this->canViewPrivateFilters = $canViewPrivateFilters;
		$this->canViewProtectedVars = $canViewProtectedVars;
		$this->mDefaultDirection = true;
	}

	/**
	 * Note: this method is called by parent::__construct
	 * @return array
	 * @see MediaWiki\Pager\Pager::getFieldNames()
	 */
	public function getFieldNames() {
		static $headers = null;

		if ( $headers !== null ) {
			return $headers;
		}

		$headers = [
			'afh_timestamp' => 'abusefilter-history-timestamp',
			'afh_user_text' => 'abusefilter-history-user',
			'afh_public_comments' => 'abusefilter-history-public',
			'afh_flags' => 'abusefilter-history-flags',
			'afh_actions' => 'abusefilter-history-actions',
			'afh_id' => 'abusefilter-history-diff',
		];

		if ( !$this->filter ) {
			// awful hack
			$headers = [ 'afh_filter' => 'abusefilter-history-filterid' ] + $headers;
		}

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		return $headers;
	}

	/**
	 * @param string $name
	 * @param string|null $value
	 * @return string
	 */
	public function formatValue( $name, $value ) {
		$lang = $this->getLanguage();
		$linkRenderer = $this->getLinkRenderer();

		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'afh_filter':
				$formatted = $linkRenderer->makeLink(
					SpecialAbuseFilter::getTitleForSubpage( $row->afh_filter ),
					$lang->formatNum( $row->afh_filter )
				);
				break;
			case 'afh_timestamp':
				$title = SpecialAbuseFilter::getTitleForSubpage(
					'history/' . $row->afh_filter . '/item/' . $row->afh_id );
				$formatted = $linkRenderer->makeLink(
					$title,
					$lang->userTimeAndDate( $row->afh_timestamp, $this->getUser() )
				);
				break;
			case 'afh_user_text':
				$formatted =
					Linker::userLink( $row->afh_user ?? 0, $row->afh_user_text ) . ' ' .
					Linker::userToolLinks( $row->afh_user ?? 0, $row->afh_user_text );
				break;
			case 'afh_public_comments':
				$formatted = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false );
				break;
			case 'afh_flags':
				$formatted = $this->specsFormatter->formatFlags( $value, $lang );
				break;
			case 'afh_actions':
				$actions = unserialize( $value );

				$display_actions = '';

				foreach ( $actions as $action => $parameters ) {
					$displayAction = $this->specsFormatter->formatAction( $action, $parameters, $lang );
					$display_actions .= Html::rawElement( 'li', [], $displayAction );
				}
				$display_actions = Html::rawElement( 'ul', [], $display_actions );

				$formatted = $display_actions;
				break;
			case 'afh_id':
				// Set a link to a diff with the previous version if this isn't the first edit to the filter.
				// Like in AbuseFilterViewDiff, don't show it if:
				// - the user cannot see private filters and any of the versions is hidden
				// - the user cannot see protected variables and any of the versions is protected
				$formatted = '';
				if ( $this->filterLookup->getFirstFilterVersionID( $row->afh_filter ) !== (int)$value ) {
					// @todo Should we also hide actions?
					$prevFilter = $this->filterLookup->getClosestVersion(
						$row->afh_id, $row->afh_filter, FilterLookup::DIR_PREV );
					if (
							( $this->canViewPrivateFilters ||
							(
								!in_array( 'hidden', explode( ',', $row->afh_flags ) ) &&
								!$prevFilter->isHidden()
							)
						) &&
						(
							$this->canViewProtectedVars ||
							(
								!in_array( 'protected', explode( ',', $row->afh_flags ) ) &&
								!$prevFilter->isProtected()
							)
						)
					) {
						$title = SpecialAbuseFilter::getTitleForSubpage(
							'history/' . $row->afh_filter . "/diff/prev/$value" );
						$formatted = $linkRenderer->makeLink(
							$title,
							new HtmlArmor( $this->msg( 'abusefilter-history-diff' )->parse() )
						);
					}
				}
				break;
			default:
				throw new UnexpectedValueException( "Unknown row type $name!" );
		}

		return $formatted;
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$info = [
			'tables' => [ 'abuse_filter_history', 'abuse_filter', 'actor' ],
			// All fields but afh_deleted on abuse_filter_history
			'fields' => [
				'afh_filter',
				'afh_timestamp',
				'afh_public_comments',
				'afh_user' => 'actor_user',
				'afh_user_text' => 'actor_name',
				'afh_flags',
				'afh_comments',
				'afh_actions',
				'afh_id',
				'afh_changed_fields',
				'afh_pattern',
				'af_hidden'
			],
			'conds' => [],
			'join_conds' => [
				'abuse_filter' =>
					[
						'LEFT JOIN',
						'afh_filter=af_id',
					],
				'actor' => [ 'JOIN', 'actor_id = afh_actor' ],
			]
		];

		if ( $this->user !== null ) {
			$info['conds']['actor_name'] = $this->user;
		}

		if ( $this->filter ) {
			$info['conds']['afh_filter'] = $this->filter;
		}

		if ( !$this->canViewPrivateFilters ) {
			// Hide data the user can't see.
			$info['conds'][] = $this->mDb->bitAnd( 'af_hidden', Flags::FILTER_HIDDEN ) . ' = 0';
		}

		if ( !$this->canViewProtectedVars ) {
			// Hide data the user can't see.
			$info['conds'][] = $this->mDb->bitAnd( 'af_hidden', Flags::FILTER_USES_PROTECTED_VARS ) . ' = 0';
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
			$lb->addUser( new UserIdentityValue( $row->afh_user ?? 0, $row->afh_user_text ) );
		}
		$lb->execute();
		$result->seek( 0 );
	}

	/**
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
	 */
	public function getDefaultSort() {
		return 'afh_timestamp';
	}

	/**
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
	 */
	public function isFieldSortable( $field ) {
		return $field === 'afh_timestamp';
	}

	/**
	 * @param string $field
	 * @param string $value
	 * @return array
	 * @see TablePager::getCellAttrs
	 */
	public function getCellAttrs( $field, $value ) {
		$row = $this->mCurrentRow;
		$mappings = array_flip( AbuseFilter::HISTORY_MAPPINGS ) +
			[ 'afh_actions' => 'actions', 'afh_id' => 'id' ];
		$changed = explode( ',', $row->afh_changed_fields );

		$fieldChanged = false;
		if ( $field === 'afh_flags' ) {
			// The field is changed if any of these filters are in the $changed array.
			$filters = [ 'af_enabled', 'af_hidden', 'af_deleted', 'af_global' ];
			if ( count( array_intersect( $filters, $changed ) ) ) {
				$fieldChanged = true;
			}
		} elseif ( in_array( $mappings[$field], $changed ) ) {
			$fieldChanged = true;
		}

		$class = $fieldChanged ? ' mw-abusefilter-history-changed' : '';
		$attrs = parent::getCellAttrs( $field, $value );
		$attrs['class'] .= $class;
		return $attrs;
	}

	/** @inheritDoc */
	protected function getRowClass( $row ) {
		return 'mw-abusefilter-history-id-' . $row->afh_id;
	}

	/**
	 * Title used for self-links.
	 *
	 * @return Title
	 */
	public function getTitle() {
		$subpage = $this->filter ? ( 'history/' . $this->filter ) : 'history';
		return SpecialAbuseFilter::getTitleForSubpage( $subpage );
	}
}
