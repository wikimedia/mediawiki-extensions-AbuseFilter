<?php

use MediaWiki\Linker\LinkRenderer;

/**
 * Class to build paginated filter list for wikis using global abuse filters
 */
class GlobalAbuseFilterPager extends AbuseFilterPager {
	/**
	 * @param AbuseFilterViewList $page
	 * @param array $conds
	 * @param LinkRenderer $linkRenderer
	 */
	public function __construct( AbuseFilterViewList $page, $conds, LinkRenderer $linkRenderer ) {
		parent::__construct( $page, $conds, $linkRenderer, [ '', 'LIKE' ] );
		$this->mDb = wfGetDB(
			DB_REPLICA, [], $this->getConfig()->get( 'AbuseFilterCentralDB' ) );
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
				return $lang->formatNum( intval( $value ) );
			case 'af_public_comments':
				return $this->getOutput()->parseInline( $value );
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
				} else {
					$statuses[] = $this->msg( 'abusefilter-disabled' )->parse();
				}
				if ( $row->af_global ) {
					$statuses[] = $this->msg( 'abusefilter-status-global' )->parse();
				}

				return $lang->commaList( $statuses );
			case 'af_hidden':
				$msg = $value ? 'abusefilter-hidden' : 'abusefilter-unhidden';
				return $this->msg( $msg )->parse();
			case 'af_hit_count':
				// If the rule is hidden, don't show it, even to priviledged local admins
				if ( $row->af_hidden ) {
					return '';
				}
				return $this->msg( 'abusefilter-hitcount' )->numParams( $value )->parse();
			case 'af_timestamp':
				$user = $row->af_user_text;
				return $this->msg(
					'abusefilter-edit-lastmod-text',
					$lang->timeanddate( $value, true ),
					$user,
					$lang->date( $value, true ),
					$lang->time( $value, true ),
					$user
				)->parse();
			case 'af_group':
				// If this is global, local name probably doesn't exist, but try
				return AbuseFilter::nameGroup( $value );
			default:
				throw new MWException( "Unknown row type $name!" );
		}
	}
}
