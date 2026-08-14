<?php

namespace MediaWiki\Extension\AbuseFilter\Pager;

use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\FilterUtils;
use MediaWiki\Extension\AbuseFilter\SpecsFormatter;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewList;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Pager\TablePager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserIdentityValue;
use stdClass;
use UnexpectedValueException;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Class to build paginated filter list
 */
class AbuseFilterPager extends TablePager {

	/**
	 * The unique sort fields for the sort options for unique paginate
	 */
	private const INDEX_FIELDS = [
		'af_id' => [ 'af_id' ],
		'af_enabled' => [ 'af_enabled', 'af_deleted', 'af_id' ],
		'af_timestamp' => [ 'af_timestamp', 'af_id' ],
		'af_hidden' => [ 'af_hidden', 'af_id' ],
		'af_group' => [ 'af_group', 'af_id' ],
		'af_hit_count' => [ 'af_hit_count', 'af_id' ],
		'af_public_comments' => [ 'af_public_comments', 'af_id' ],
	];

	/**
	 * Mapping from filter IDs to highlighted patterns
	 * @var array<int,string>
	 */
	private array $highlightedPatterns = [];

	public function __construct(
		private readonly AbuseFilterViewList $page,
		LinkRenderer $linkRenderer,
		private readonly ?LinkBatchFactory $linkBatchFactory,
		private readonly AbuseFilterPermissionManager $afPermManager,
		protected readonly SpecsFormatter $specsFormatter,
		private readonly FilterLookup $filterLookup,
		private array $conds,
		private readonly ?PatternMatcher $patternMatcher = null,
	) {
		parent::__construct( $page->getContext(), $linkRenderer );
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$dbr = $this->getDatabase();

		// If sorting by hit count, exclude filters the user cannot see (T434372)
		if ( $this->mSort === 'af_hit_count' ) {
			$disallowedBitMask = 0;
			$performer = $this->getAuthority();

			// Keep these permission checks in sync with ::canSeeLogDetailsForFilter()
			// in AbuseFilterPermissionManager
			if ( !$this->afPermManager->canViewPrivateFilters( $performer ) ) {
				$disallowedBitMask |= Flags::FILTER_HIDDEN;
			}
			if ( !$this->afPermManager->canViewProtectedVariables( $performer, [] )->isGood() ) {
				$disallowedBitMask |= Flags::FILTER_USES_PROTECTED_VARS;
			}
			if ( !$this->afPermManager->canViewSuppressed( $performer ) ) {
				$disallowedBitMask |= Flags::FILTER_SUPPRESSED;
			}

			if ( $disallowedBitMask !== 0 ) {
				$this->conds[] = $dbr->bitAnd( 'af_hidden', $disallowedBitMask ) . ' = 0';
			}
		}

		return $this->filterLookup->getAbuseFilterQueryBuilder( $dbr )
			->andWhere( $this->conds )
			->getQueryInfo();
	}

	/**
	 * @param IResultWrapper $result
	 */
	protected function preprocessResults( $result ) {
		// LinkBatchFactory only provided and needed for local wiki results
		if ( $this->linkBatchFactory === null || $this->getNumRows() === 0 ) {
			return;
		}

		$lb = $this->linkBatchFactory->newLinkBatch();
		$lb->setCaller( __METHOD__ );
		foreach ( $result as $row ) {
			$lb->addUser( new UserIdentityValue( $row->af_user ?? 0, $row->af_user_text ) );
		}
		$lb->execute();
		$result->seek( 0 );
	}

	/**
	 * @inheritDoc
	 * This is the same as the parent implementation if no search pattern was specified.
	 * Otherwise, it does a query with no limit and stops once $limit matching rows are found.
	 */
	public function reallyDoQuery( $offset, $limit, $order ) {
		if ( $this->patternMatcher === null ) {
			return parent::reallyDoQuery( $offset, $limit, $order );
		}

		[ $tables, $fields, $conds, $fname, $options, $join_conds ] =
			$this->buildQueryInfo( $offset, $limit, $order );

		unset( $options['LIMIT'] );
		$res = $this->mDb->newSelectQueryBuilder()
			->tables( $tables )
			->fields( $fields )
			->conds( $conds )
			->caller( $fname )
			->options( $options )
			->joinConds( $join_conds )
			->fetchResultSet();

		$filtered = [];
		$count = 0;
		foreach ( $res as $row ) {
			if ( $count >= $limit ) {
				break;
			}

			// We don't need the actual $actions here.
			$filter = $this->filterLookup->filterFromRow( $row, [] );

			// Exclude filters from the search result which include variables the user cannot see to avoid
			// exposing the pattern when the user cannot see the filter.
			if (
				$filter->isProtected() &&
				!$this->afPermManager->canViewProtectedVariablesInFilter( $this->getAuthority(), $filter )->isGood()
			) {
				continue;
			}

			$highlighted = $this->patternMatcher->findMatchAndHighlight( $filter );
			if ( $highlighted === null ) {
				continue;
			}
			$this->highlightedPatterns[$filter->getId()] = $highlighted;

			$filtered[] = $row;
			$count++;
		}

		return new FakeResultWrapper( $filtered );
	}

	/**
	 * Note: this method is called by parent::__construct
	 * @return array<string,string>
	 * @see \MediaWiki\Pager\Pager::getFieldNames()
	 */
	public function getFieldNames() {
		$headers = [
			'af_id' => 'abusefilter-list-id',
			'af_public_comments' => 'abusefilter-list-public',
			'af_actions' => 'abusefilter-list-consequences',
			'af_enabled' => 'abusefilter-list-status',
			'af_timestamp' => 'abusefilter-list-lastmodified',
			'af_hidden' => 'abusefilter-list-visibility',
		];

		$performer = $this->getAuthority();
		if ( $this->afPermManager->canSeeLogDetails( $performer ) ) {
			$headers['af_hit_count'] = 'abusefilter-list-hitcount';
		}

		if (
			$this->patternMatcher !== null &&
			$this->afPermManager->canViewPrivateFilters( $performer )
		) {
			// This is also excluded in the default view. The column shows whichever field
			// the search matched, so it is not tied to af_pattern despite the key.
			$headers['af_pattern'] = 'abusefilter-list-matchedtext';
		}

		if ( count( $this->getConfig()->get( 'AbuseFilterValidGroups' ) ) > 1 ) {
			$headers['af_group'] = 'abusefilter-list-group';
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
		$user = $this->getUser();
		$linkRenderer = $this->getLinkRenderer();
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'af_id':
				return $linkRenderer->makeLink(
					SpecialPage::getTitleFor( 'AbuseFilter', $value ),
					$lang->formatNum( intval( $value ) )
				);
			case 'af_pattern':
				return $this->highlightedPatterns[$row->af_id];
			case 'af_public_comments':
				return $linkRenderer->makeLink(
					SpecialPage::getTitleFor( 'AbuseFilter', $row->af_id ),
					$value
				);
			case 'af_actions':
				$actions = explode( ',', $value );
				$displayActions = [];
				foreach ( $actions as $action ) {
					$displayActions[] = $this->specsFormatter->getActionDisplay( $action );
				}
				return $lang->commaList( $displayActions );
			case 'af_enabled':
				$statuses = [];
				if ( $row->af_deleted ) {
					$statuses[] = $this->msg( 'abusefilter-deleted' )->parse();
				} elseif ( $row->af_enabled ) {
					$statuses[] = $this->msg( 'abusefilter-enabled' )->parse();
					if ( $row->af_throttled ) {
						$statuses[] = $this->msg( 'abusefilter-throttled' )->parse();
					}
				} else {
					$statuses[] = $this->msg( 'abusefilter-disabled' )->parse();
				}

				if ( $row->af_global && $this->getConfig()->get( 'AbuseFilterIsCentral' ) ) {
					$statuses[] = $this->msg( 'abusefilter-status-global' )->parse();
				}

				return $lang->commaList( $statuses );
			case 'af_hidden':
				$flagMsgs = [];
				if ( FilterUtils::isSuppressed( (int)$value ) ) {
					$flagMsgs[] = $this->msg( 'abusefilter-suppressed' )->parse();
				}
				if ( FilterUtils::isHidden( (int)$value ) ) {
					$flagMsgs[] = $this->msg( 'abusefilter-hidden' )->parse();
				}
				if ( FilterUtils::isProtected( (int)$value ) ) {
					$flagMsgs[] = $this->msg( 'abusefilter-protected' )->parse();
				}
				if ( !$flagMsgs ) {
					return $this->msg( 'abusefilter-unhidden' )->parse();
				}
				return $lang->commaList( $flagMsgs );
			case 'af_hit_count':
				// We don't need the actual $actions here.
				$filter = $this->filterLookup->filterFromRow( $row, [] );
				if ( $this->afPermManager->canSeeLogDetailsForFilter( $this->getAuthority(), $filter ) ) {
					$count_display = $this->msg( 'abusefilter-hitcount' )
						->numParams( $value )->text();
					$link = $linkRenderer->makeKnownLink(
						SpecialPage::getTitleFor( 'AbuseLog' ),
						$count_display,
						[],
						[ 'wpSearchFilter' => $row->af_id ]
					);
				} else {
					$link = "";
				}
				return $link;
			case 'af_timestamp':
				$userLink =
					Linker::userLink(
						$row->af_user ?? 0,
						$row->af_user_text
					) .
					Linker::userToolLinks(
						$row->af_user ?? 0,
						$row->af_user_text
					);

				return $this->msg( 'abusefilter-edit-lastmod-text' )
					->rawParams(
						$this->page->getLinkToLatestDiff(
							$row->af_id,
							$lang->userTimeAndDate( $value, $user )
						),
						$userLink,
						$this->page->getLinkToLatestDiff(
							$row->af_id,
							$lang->userDate( $value, $user )
						),
						$this->page->getLinkToLatestDiff(
							$row->af_id,
							$lang->userTime( $value, $user )
						)
					)->params(
						wfEscapeWikiText( $row->af_user_text )
					)->parse();
			case 'af_group':
				return $this->specsFormatter->nameGroup( $value );
			default:
				throw new UnexpectedValueException( "Unknown row type $name!" );
		}
	}

	/**
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
	 */
	public function getDefaultSort() {
		return 'af_id';
	}

	/**
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
	 */
	public function getTableClass() {
		return parent::getTableClass() . ' mw-abusefilter-list-scrollable';
	}

	/**
	 * @param stdClass $row
	 * @return string
	 * @see TablePager::getRowClass()
	 */
	public function getRowClass( $row ) {
		if ( $row->af_enabled ) {
			return $row->af_throttled ? 'mw-abusefilter-list-throttled' : 'mw-abusefilter-list-enabled';
		} elseif ( $row->af_deleted ) {
			return 'mw-abusefilter-list-deleted';
		} else {
			return 'mw-abusefilter-list-disabled';
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getIndexField() {
		return [ self::INDEX_FIELDS[$this->mSort] ];
	}

	/**
	 * @param string $field
	 *
	 * @return bool
	 */
	public function isFieldSortable( $field ) {
		if ( ( $field === 'af_hit_count' || $field === 'af_public_comments' )
			&& !$this->afPermManager->canSeeLogDetails( $this->getAuthority() )
		) {
			return false;
		}
		return isset( self::INDEX_FIELDS[$field] );
	}
}
