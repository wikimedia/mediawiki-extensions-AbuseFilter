<?php

/**
 * Class to build paginated filter list
 */
class AbuseFilterPager extends TablePager {

	/**
	 * @var \MediaWiki\Linker\LinkRenderer
	 */
	protected $linkRenderer;

	function __construct( $page, $conds, $linkRenderer, $query ) {
		$this->mPage = $page;
		$this->mConds = $conds;
		$this->linkRenderer = $linkRenderer;
		$this->mQuery = $query;
		parent::__construct( $this->mPage->getContext() );
	}

	function getQueryInfo() {
		return [
			'tables' => [ 'abuse_filter' ],
			'fields' => [
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
			],
			'conds' => $this->mConds,
		];
	}

	function getFieldNames() {
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

		global $wgAbuseFilterValidGroups;
		if ( count( $wgAbuseFilterValidGroups ) > 1 ) {
			$headers['af_group'] = 'abusefilter-list-group';
		}

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		return $headers;
	}

	function formatValue( $name, $value ) {
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
					$position = mb_strpos(
						strtolower( $row->af_pattern ),
						strtolower( $this->mQuery[0] ),
						0,
						'UTF8'
					);
					if ( $position === false ) {
						// This may happen due to problems with character encoding
						// which aren't easy to solve
						return htmlspecialchars( mb_substr( $row->af_pattern, 0, 50, 'UTF8' ) );
					}
					$length = mb_strlen( $this->mQuery[0], 'UTF8' );
				} elseif ( $this->mQuery[1] === 'RLIKE' ) {
					Wikimedia\suppressWarnings();
					$check = preg_match(
						'/' . $this->mQuery[0] . '/',
						$row->af_pattern,
						$matches,
						PREG_OFFSET_CAPTURE
					);
					Wikimedia\restoreWarnings();
					// This may happen in case of catastrophic backtracking
					if ( $check === false ) {
						return htmlspecialchars( mb_substr( $row->af_pattern, 0, 50, 'UTF8' ) );
					}
					$length = mb_strlen( $matches[0][0], 'UTF8' );
					$position = $matches[0][1];
				} elseif ( $this->mQuery[1] === 'IRLIKE' ) {
					Wikimedia\suppressWarnings();
					$check = preg_match(
						'/' . $this->mQuery[0] . '/i',
						$row->af_pattern,
						$matches,
						PREG_OFFSET_CAPTURE
					);
					Wikimedia\restoreWarnings();
					// This may happen in case of catastrophic backtracking
					if ( $check === false ) {
						return htmlspecialchars( mb_substr( $row->af_pattern, 0, 50, 'UTF8' ) );
					}
					$length = mb_strlen( $matches[0][0], 'UTF8' );
					$position = $matches[0][1];
				}
				$remaining = 50 - $length;
				if ( $remaining <= 0 ) {
					$pattern = '<b>' .
						htmlspecialchars( mb_substr( $row->af_pattern, 0, 50, 'UTF8' ) ) .
						'</b>';
				} else {
					$minoffset = max( $position - round( $remaining / 2 ), 0 );
					$pattern = mb_substr( $row->af_pattern, $minoffset, 50, 'UTF8' );
					$pattern =
						htmlspecialchars( mb_substr( $pattern, 0, $position - $minoffset, 'UTF8' ) ) .
						'<b>' .
						htmlspecialchars( mb_substr( $pattern, $position - $minoffset, $length, 'UTF8' ) ) .
						'</b>' .
						htmlspecialchars( mb_substr(
							$pattern,
							$position - $minoffset + $length,
							$remaining - ( $position - $minoffset + $length ),
							'UTF8'
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
				return htmlspecialchars( $lang->commaList( $displayActions ) );
			case 'af_enabled':
				$statuses = [];
				if ( $row->af_deleted ) {
					$statuses[] = $this->msg( 'abusefilter-deleted' )->parse();
				} elseif ( $row->af_enabled ) {
					$statuses[] = $this->msg( 'abusefilter-enabled' )->parse();
				} else {
					$statuses[] = $this->msg( 'abusefilter-disabled' )->parse();
				}

				global $wgAbuseFilterIsCentral;
				if ( $row->af_global && $wgAbuseFilterIsCentral ) {
					$statuses[] = $this->msg( 'abusefilter-status-global' )->parse();
				}

				return $lang->commaList( $statuses );
			case 'af_hidden':
				$msg = $value ? 'abusefilter-hidden' : 'abusefilter-unhidden';
				return $this->msg( $msg )->parse();
			case 'af_hit_count':
				if ( SpecialAbuseLog::canSeeDetails( $row->af_id, $row->af_hidden ) ) {
					$count_display = $this->msg( 'abusefilter-hitcount' )
						->numParams( $value )->parse();
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
				$user = $row->af_user_text;
				return $this->msg( 'abusefilter-edit-lastmod-text' )
					->rawParams( $lang->timeanddate( $value, true ),
						$userLink,
						$lang->date( $value, true ),
						$lang->time( $value, true ),
						$user
				)->parse();
			case 'af_group':
				return AbuseFilter::nameGroup( $value );
				break;
			default:
				throw new MWException( "Unknown row type $name!" );
		}
	}

	function getDefaultSort() {
		return 'af_id';
	}

	function getTableClass() {
		return 'TablePager mw-abusefilter-list-scrollable';
	}

	function getRowClass( $row ) {
		if ( $row->af_enabled ) {
			return 'mw-abusefilter-list-enabled';
		} elseif ( $row->af_deleted ) {
			return 'mw-abusefilter-list-deleted';
		} else {
			return 'mw-abusefilter-list-disabled';
		}
	}

	function isFieldSortable( $name ) {
		$sortable_fields = [
			'af_id',
			'af_enabled',
			'af_throttled',
			'af_user_text',
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
