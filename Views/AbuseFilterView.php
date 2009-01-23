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