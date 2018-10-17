<?php

use MediaWiki\Linker\LinkRenderer;

/**
 * Class to build paginated filter list
 */
class AbuseFilterPager extends TablePager {

	/**
	 * @var LinkRenderer
	 */
	protected $linkRenderer;

	public $mPage, $mConds, $mQuery;

	/**
	 * @param AbuseFilterViewList $page
	 * @param array $conds
	 * @param LinkRenderer $linkRenderer
	 * @param array $query
	 */
	public function __construct( $page, $conds, $linkRenderer, $query ) {
		$this->mPage = $page;
		$this->mConds = $conds;
		$this->linkRenderer = $linkRenderer;
		$this->mQuery = $query;
		parent::__construct( $this->mPage->getContext() );
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		return [
			'tables' => [ 'abuse_filter' ],
			'fields' => [
				// All columns but af_comments
				'af_id',
				'af_enabled',
				'af_deleted',
				'af_pattern',
				'af_global',
				'af_public_comments',
				'af_hidden',
				'af_hit_count',
				'af_timestamp',
				'af_user_text',
				'af_user',
				'af_actions',
				'af_group',
				'af_throttled'
			],
			'conds' => $this->mConds,
		];
	}

	/**
	 * @see Pager::getFieldNames()
	 * @return array
	 */
	public function getFieldNames() {
		static $headers = null;

		if ( !empty( $headers ) ) {
			return $headers;
		}

		$headers = [
			'af_id' => 'abusefilter-list-id',
			'af_public_comments' => 'abusefilter-list-public',
			'af_actions' => 'abusefilter-list-consequences',
			'af_enabled' => 'abusefilter-list-status',
			'af_timestamp' => 'abusefilter-list-lastmodified',
			'af_hidden' => 'abusefilter-list-visibility',
		];

		if ( $this->mPage->getUser()->isAllowed( 'abusefilter-log-detail' ) ) {
			$headers['af_hit_count'] = 'abusefilter-list-hitcount';
		}

		if ( AbuseFilterView::canViewPrivate() && !empty( $this->mQuery[0] ) ) {
			$headers['af_pattern'] = 'abusefilter-list-pattern';
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
	 * @param string $value
	 * @return string
	 */
	public function formatValue( $name, $value ) {
		$lang = $this->getLanguage();
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'af_id':
				return $this->linkRenderer->makeLink(
					SpecialPage::getTitleFor( 'AbuseFilter', intval( $value ) ),
					$lang->formatNum( intval( $value ) )
				);
			case 'af_pattern':
				if ( $this->mQuery[1] === 'LIKE' ) {
					$position = mb_stripos( $row->af_pattern, $this->mQuery[0] );
					if ( $position === false ) {
						// This may happen due to problems with character encoding
						// which aren't easy to solve
						return htmlspecialchars( mb_substr( $row->af_pattern, 0, 50 ) );
					}
					$length = mb_strlen( $this->mQuery[0] );
				} else {
					$regex = '/' . $this->mQuery[0] . '/u';
					if ( $this->mQuery[1] === 'IRLIKE' ) {
						$regex .= 'i';
					}

					$matches = [];
					Wikimedia\suppressWarnings();
					$check = preg_match(
						$regex,
						$row->af_pattern,
						$matches
					);
					Wikimedia\restoreWarnings();
					// This may happen in case of catastrophic backtracking
					if ( $check === false ) {
						return htmlspecialchars( mb_substr( $row->af_pattern, 0, 50 ) );
					}

					$length = mb_strlen( $matches[0] );
					$position = mb_strpos( $row->af_pattern, $matches[0] );
				}

				$remaining = 50 - $length;
				if ( $remaining <= 0 ) {
					// Truncate the filter pattern and only show the first 50 characters of the match
					$pattern = '<b>' .
						htmlspecialchars( mb_substr( $row->af_pattern, $position, 50 ) ) .
						'</b>';
				} else {
					// Center the snippet on the matched string
					$minoffset = max( $position - round( $remaining / 2 ), 0 );
					$pattern = mb_substr( $row->af_pattern, $minoffset, 50 );
					$pattern =
						htmlspecialchars( mb_substr( $pattern, 0, $position - $minoffset ) ) .
						'<b>' .
						htmlspecialchars( mb_substr( $pattern, $position - $minoffset, $length ) ) .
						'</b>' .
						htmlspecialchars( mb_substr(
							$pattern,
							$position - $minoffset + $length,
							$remaining - ( $position - $minoffset + $length )
							)
						);
				}
				return $pattern;
			case 'af_public_comments':
				return $this->linkRenderer->makeLink(
					SpecialPage::getTitleFor( 'AbuseFilter', intval( $row->af_id ) ),
					$value
				);
			case 'af_actions':
				$actions = explode( ',', $value );
				$displayActions = [];
				foreach ( $actions as $action ) {
					$displayActions[] = AbuseFilter::getActionDisplay( $action );
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
				$msg = $value ? 'abusefilter-hidden' : 'abusefilter-unhidden';
				return $this->msg( $msg )->parse();
			case 'af_hit_count':
				if ( SpecialAbuseLog::canSeeDetails( $row->af_id, $row->af_hidden ) ) {
					$count_display = $this->msg( 'abusefilter-hitcount' )
						->numParams( $value )->text();
					$link = $this->linkRenderer->makeKnownLink(
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
						$row->af_user,
						$row->af_user_text
					) .
					Linker::userToolLinks(
						$row->af_user,
						$row->af_user_text
					);

				return $this->msg( 'abusefilter-edit-lastmod-text' )
					->rawParams(
						$this->mPage->getLinkToLatestDiff(
							$row->af_id,
							$lang->timeanddate( $value, true )
						),
						$userLink,
						$this->mPage->getLinkToLatestDiff(
							$row->af_id,
							$lang->date( $value, true )
						),
						$this->mPage->getLinkToLatestDiff(
							$row->af_id,
							$lang->time( $value, true )
						)
					)->params(
						wfEscapeWikiText( $row->af_user_text )
				)->parse();
			case 'af_group':
				return AbuseFilter::nameGroup( $value );
			default:
				throw new MWException( "Unknown row type $name!" );
		}
	}

	/**
	 * @return string
	 */
	public function getDefaultSort() {
		return 'af_id';
	}

	/**
	 * @return string
	 */
	public function getTableClass() {
		return 'TablePager mw-abusefilter-list-scrollable';
	}

	/**
	 * @see TablePager::getRowClass()
	 * @param stdClass $row
	 * @return string
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
	 * @param string $name
	 * @return bool
	 */
	public function isFieldSortable( $name ) {
		$sortable_fields = [
			'af_id',
			'af_enabled',
			'af_timestamp',
			'af_hidden',
			'af_group',
		];
		if ( $this->mPage->getUser()->isAllowed( 'abusefilter-log-detail' ) ) {
			$sortable_fields[] = 'af_hit_count';
		}
		return in_array( $name, $sortable_fields );
	}
}
