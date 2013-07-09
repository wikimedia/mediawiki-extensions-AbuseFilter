<?php

abstract class AbuseFilterView extends ContextSource {
	/**
	 * @param $page SpecialPage
	 * @param $params array
	 */
	function __construct( $page, $params ) {
		$this->mPage = $page;
		$this->mParams = $params;
		$this->setContext( $this->mPage->getContext() );
	}

	/**
	 * @param string $subpage
	 * @return Title
	 */
	function getTitle( $subpage = '' ) {
		return $this->mPage->getTitle( $subpage );
	}

	abstract function show();

	/**
	 * @return bool
	 */
	public function canEdit() {
		return $this->getUser()->isAllowed( 'abusefilter-modify' );
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
		$examineParams = empty( $rc->examineParams ) ? array() : $rc->examineParams;

		$title = SpecialPage::getTitleFor( 'AbuseFilter', 'examine/' . $rc->mAttribs['rc_id'] );
		$examineLink = Linker::link(
			$title,
			$this->msg( 'abusefilter-changeslist-examine' )->parse(),
			array(),
			$examineParams
		);

		$s .= " ($examineLink)";

		# If we have a match..
		if ( isset( $rc->filterResult ) ) {
			$class = $rc->filterResult ?
				'mw-abusefilter-changeslist-match' :
				'mw-abusefilter-changeslist-nomatch';

			$classes[] = $class;
		}
	}

	// Kill rollback links.
	public function insertRollback( &$s, &$rc ) { }
}
