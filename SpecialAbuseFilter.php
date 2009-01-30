<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();

class SpecialAbuseFilter extends SpecialPage {

	var $mSkin;

	function __construct() {
		wfLoadExtensionMessages('AbuseFilter');
		parent::__construct( 'AbuseFilter', 'abusefilter-view' );
	}
	
	function execute( $subpage ) {
		global $wgUser, $wgOut, $wgRequest, $wgAbuseFilterStyleVersion, $wgScriptPath;

		$wgOut->addExtensionStyle( "{$wgScriptPath}/extensions/AbuseFilter/abusefilter.css?{$wgAbuseFilterStyleVersion}" );
		$view = 'AbuseFilterViewList';

		$this->setHeaders();

		$this->loadParameters( $subpage );
		$wgOut->setPageTitle( wfMsg( 'abusefilter-management' ) );
		$wgOut->setRobotPolicy( "noindex,nofollow" );
		$wgOut->setArticleRelated( false );
		$wgOut->enableClientCache( false );

		// Are we allowed?
		if ( !$wgUser->isAllowed( 'abusefilter-view' ) ) {
			$this->displayRestrictionError();
			return;
		}

		if ( $wgRequest->getVal( 'result' ) == 'success' ) {
			$wgOut->setSubtitle( wfMsg( 'abusefilter-edit-done-subtitle' ) );
			$wgOut->wrapWikiMsg( '<p class="success">$1</p>', array('abusefilter-edit-done', $wgRequest->getVal( 'changedfilter' ) ) );
		}
		
		$this->mSkin = $wgUser->getSkin();
		$this->mHistoryID = null;
		
		$params = array_filter( explode( '/', $subpage ) );
		
		if ($subpage == 'tools') {
			$view = 'AbuseFilterViewTools';
		}

		if ( count($params) == 2 && $params[0] == 'revert' && is_numeric( $params[1] ) ) {
			$this->mFilter = $params[1];
			$view = 'AbuseFilterViewRevert';
		}

		if ( count($params) && $params[0] == 'test' ) {
			$view = 'AbuseFilterViewTestBatch';
		}

		if ( count($params) && $params[0] == 'examine' ) {
			$view = 'AbuseFilterViewExamine';
		}
		
		if (!empty($params[0]) && ($params[0] == 'history' || $params[0] == 'log') ) {
			if (count($params) == 1) {
				$view = 'AbuseFilterViewHistory';
			} elseif (count($params) == 2) {
				## Second param is a filter ID
				$view = 'AbuseFilterViewHistory';
				$this->mFilter = $params[1];
			} elseif (count($params) == 4 && $params[2] == 'item') {
				$this->mFilter = $params[1];
				$this->mHistoryID = $params[3];
				$view = 'AbuseFilterViewEdit';
			}
		}
		
		if ( is_numeric($subpage) || $subpage == 'new' ) {
			$this->mFilter = $subpage;
			$view = 'AbuseFilterViewEdit';
		}

		$v = new $view( $this, $params );
		$v->show( );
	}
	
	function loadParameters( $subpage ) {
		global $wgRequest;
		
		$filter = $subpage;
		
		if (!is_numeric($filter) && $filter != 'new') {
			$filter = $wgRequest->getIntOrNull( 'wpFilter' );
		}
		$this->mFilter = $filter;
	}
}