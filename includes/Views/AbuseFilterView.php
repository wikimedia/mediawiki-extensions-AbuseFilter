<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

abstract class AbuseFilterView extends ContextSource {
	public $mFilter, $mHistoryID, $mSubmit;

	/**
	 * @var \MediaWiki\Linker\LinkRenderer
	 */
	protected $linkRenderer;

	/**
	 * @param $page SpecialAbuseFilter
	 * @param $params array
	 */
	function __construct( $page, $params ) {
		$this->mPage = $page;
		$this->mParams = $params;
		$this->setContext( $this->mPage->getContext() );
		$this->linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
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
					// @todo: add upload and delete
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

class AbuseFilterChangesList extends OldChangesList {
	/**
	 * @param $s
	 * @param $rc
	 * @param $classes array
	 */
	public function insertExtra( &$s, &$rc, &$classes ) {
		$examineParams = empty( $rc->examineParams ) ? [] : $rc->examineParams;

		$title = SpecialPage::getTitleFor( 'AbuseFilter', 'examine/' . $rc->mAttribs['rc_id'] );
		$examineLink = $this->linkRenderer->makeLink(
			$title,
			new HtmlArmor( $this->msg( 'abusefilter-changeslist-examine' )->parse() ),
			[],
			$examineParams
		);

		$s .= ' '.$this->msg( 'parentheses' )->rawParams( $examineLink )->escaped();

		# If we have a match..
		if ( isset( $rc->filterResult ) ) {
			$class = $rc->filterResult ?
				'mw-abusefilter-changeslist-match' :
				'mw-abusefilter-changeslist-nomatch';

			$classes[] = $class;
		}
	}

	// Kill rollback links.
	public function insertRollback( &$s, &$rc ) {
	}
}
