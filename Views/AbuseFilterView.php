<?php

if (!defined( 'MEDIAWIKI' ))
	die();

abstract class AbuseFilterView {
	function __construct( $page, $params ) {
		$this->mPage = $page;
		$this->mParams = $params;
	}

	function getTitle( $subpage='' ) {
		return $this->mPage->getTitle( $subpage );
	}
	
	abstract function show();

	function canEdit() {
		global $wgUser;
		static $canEdit = 'unset';

		if ($canEdit == 'unset') {
			$canEdit = $wgUser->isAllowed( 'abusefilter-modify' );
		}

		return $canEdit;
	}
}

class AbuseFilterChangesList extends OldChangesList {
	protected function insertExtra( &$s, &$rc, &$classes ) {
		$sk = $this->skin;

		$title = SpecialPage::getTitleFor( 'AbuseFilter', "examine/".$rc->mAttribs['rc_id'] );
		$examineLink = $sk->link( $title, wfMsgExt( 'abusefilter-changeslist-examine', 'parseinline' ) );

		$s .= " ($examineLink)";

		## If we have a match..
		if ( isset( $rc->filterResult ) ) {
			$class = $rc->filterResult ? 'mw-abusefilter-changeslist-match' : 'mw-abusefilter-changeslist-nomatch';

			$classes[] = $class;
		}
	}
}