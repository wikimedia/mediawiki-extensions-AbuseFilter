<?php

namespace MediaWiki\Extension\AbuseFilter\Pager;

use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\FilterUtils;
use MediaWiki\Extension\AbuseFilter\SpecsFormatter;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewList;
use MediaWiki\Linker\LinkRenderer;

/**
 * Class to build paginated filter list for wikis using global abuse filters
 */
class GlobalAbuseFilterPager extends AbuseFilterPager {

	public function __construct(
		AbuseFilterViewList $page,
		LinkRenderer $linkRenderer,
		AbuseFilterPermissionManager $afPermManager,
		SpecsFormatter $specsFormatter,
		CentralDBManager $centralDBManager,
		FilterLookup $filterLookup,
		array $conds
	) {
		// Set database before parent constructor to avoid setting it there
		$this->mDb = $centralDBManager->getConnection( DB_REPLICA );
		parent::__construct(
			$page, $linkRenderer, null, $afPermManager, $specsFormatter,
			$filterLookup, $conds, null, null
		);
	}

	/**
	 * @param string $name
	 * @param string|null $value
	 * @return string
	 */
	public function formatValue( $name, $value ) {
		$lang = $this->getLanguage();
		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'af_id':
				return $lang->formatNum( intval( $value ) );
			case 'af_public_comments':
				return $this->getOutput()->parseInlineAsInterface( $value );
			case 'af_enabled':
				$statuses = [];
				if ( $row->af_deleted ) {
					$statuses[] = $this->msg( 'abusefilter-deleted' )->parse();
				} elseif ( $row->af_enabled ) {
					$statuses[] = $this->msg( 'abusefilter-enabled' )->parse();
				} else {
					$statuses[] = $this->msg( 'abusefilter-disabled' )->parse();
				}
				if ( $row->af_global ) {
					$statuses[] = $this->msg( 'abusefilter-status-global' )->parse();
				}

				return $lang->commaList( $statuses );
			case 'af_hit_count':
				// If the rule is hidden or protected, don't show it, even to privileged local admins
				if ( FilterUtils::isHidden( $row->af_hidden ) || FilterUtils::isProtected( $row->af_hidden ) ) {
					return '';
				}
				return $this->msg( 'abusefilter-hitcount' )->numParams( $value )->parse();
			case 'af_timestamp':
				$user = $this->getUser();
				return $this->msg(
					'abusefilter-edit-lastmod-text',
					$lang->userTimeAndDate( $value, $user ),
					$row->af_user_text,
					$lang->userDate( $value, $user ),
					$lang->userTime( $value, $user ),
					$row->af_user_text
				)->parse();
			case 'af_group':
				// If this is global, local name probably doesn't exist, but try
				return $this->specsFormatter->nameGroup( $value );
			default:
				return parent::formatValue( $name, $value );
		}
	}
}
