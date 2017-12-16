<?php

use Wikimedia\Rdbms\IDatabase;

abstract class AbuseFilterView extends ContextSource {
	public $mFilter, $mHistoryID, $mSubmit;

	/**
	 * @var \MediaWiki\Linker\LinkRenderer
	 */
	protected $linkRenderer;

	/**
	 * @param SpecialAbuseFilter $page
	 * @param array $params
	 */
	function __construct( $page, $params ) {
		$this->mPage = $page;
		$this->mParams = $params;
		$this->setContext( $this->mPage->getContext() );
		$this->linkRenderer = $page->getLinkRenderer();
	}

	/**
	 * @param string $subpage
	 * @return Title
	 */
	function getTitle( $subpage = '' ) {
		return $this->mPage->getPageTitle( $subpage );
	}

	abstract function show();

	/**
	 * @return bool
	 */
	public function canEdit() {
		return (
			!$this->getUser()->isBlocked() &&
			$this->getUser()->isAllowed( 'abusefilter-modify' )
		);
	}

	/**
	 * @return bool
	 */
	public function canEditGlobal() {
		return $this->getUser()->isAllowed( 'abusefilter-modify-global' );
	}

	/**
	 * Whether the user can edit the given filter.
	 *
	 * @param object $row Filter row
	 *
	 * @return bool
	 */
	public function canEditFilter( $row ) {
		return (
			$this->canEdit() &&
			!( isset( $row->af_global ) && $row->af_global == 1 && !$this->canEditGlobal() )
		);
	}

	/**
	 * @param IDatabase $db
	 * @return string
	 */
	public function buildTestConditions( IDatabase $db ) {
		// If one of these is true, we're abusefilter compatible.
		return $db->makeList( [
			'rc_source' => [
				RecentChange::SRC_EDIT,
				RecentChange::SRC_NEW,
			],
			$db->makeList( [
				'rc_source' => RecentChange::SRC_LOG,
				$db->makeList( [
					$db->makeList( [
						'rc_log_type' => 'move',
						'rc_log_action' => 'move'
					], LIST_AND ),
					$db->makeList( [
						'rc_log_type' => 'newusers',
						'rc_log_action' => 'create'
					], LIST_AND ),
					$db->makeList( [
						'rc_log_type' => 'delete',
						'rc_log_action' => 'delete'
					], LIST_AND ),
					// @todo: add upload
				], LIST_OR ),
			], LIST_AND ),
		], LIST_OR );
	}

	/**
	 * @static
	 * @return bool
	 */
	static function canViewPrivate() {
		global $wgUser;
		static $canView = null;

		if ( is_null( $canView ) ) {
			$canView = $wgUser->isAllowedAny( 'abusefilter-modify', 'abusefilter-view-private' );
		}

		return $canView;
	}

}
