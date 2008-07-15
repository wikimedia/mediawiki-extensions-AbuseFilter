<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();

class AbuseFilterHooks {

	public static function onEditFilter($editor, $text, $section, &$error, $summary) {
		// Load vars
		$vars = array();
		
		global $wgUser;
		$vars = array_merge( $vars, AbuseFilter::generateUserVars( $wgUser ) );
		$vars = array_merge( $vars, AbuseFilter::generateTitleVars( $editor->mTitle , 'ARTICLE' ));
		$vars['ACTION'] = 'edit';
		$vars['SUMMARY'] = $summary;
		$vars['EDIT_DELTA'] = strlen($editor->textbox1) - strlen($editor->getBaseRevision()->getText());
		$vars['OLD_SIZE'] = strlen($editor->getBaseRevision()->getText());
		$vars['NEW_SIZE'] = strlen($editor->textbox1);

		$filter_result = AbuseFilter::filterAction( $vars, $editor->mTitle );
		if( $filter_result !== true ){
			$error = $filter_result;
		}
		return true;
	}
	
	public static function onGetAutoPromoteGroups( $user, &$promote ) {
		global $wgMemc;
		
		$key = AbuseFilter::autoPromoteBlockKey( $user );
		
		if ($wgMemc->get( $key ) ) {
			$promote = array();
		}
		
		return true;
	}
	
	function onAbortMove( $oldTitle, $newTitle, $user, &$error, $reason ) {
		$vars = array();
		
		global $wgUser;
		$vars = array_merge( $vars, AbuseFilter::generateUserVars( $wgUser ),
					AbuseFilter::generateTitleVars( $oldTitle, 'MOVED_FROM' ),
					AbuseFilter::generateTitleVars( $newTitle, 'MOVED_TO' ) );
		$vars['SUMMARY'] = $reason;
		$vars['ACTION'] = 'move';
		
		$filter_result = AbuseFilter::filterAction( $vars, $oldTitle );
		
		$error = $filter_result;
		
		return $filter_result == '' || $filter_result === true;
	}
	
	function onArticleDelete( &$article, &$user, &$reason, &$error ) {
		$vars = array();
		
		global $wgUser;
		$vars = array_merge( $vars, AbuseFilter::generateUserVars( $wgUser ),
					AbuseFilter::generateTitleVars( $article->mTitle, 'ARTICLE' ) );
		$vars['SUMMARY'] = $reason;
		$vars['ACTION'] = 'delete';
		
		$filter_result = AbuseFilter::filterAction( $vars, $article->mTitle );
		
		$error = $filter_result;
		
		return $filter_result == '' || $filter_result === true;
	}
	
	function onAbortNewAccount( $user, &$message ) {
		wfLoadExtensionMessages( 'AbuseFilter' );
		if ($username == wfMsgForContent( 'abusefilter-blocker' )) {
			$message = wfMsg( 'abusefilter-accountreserved' );
			return false;
		}
		$vars = array();
		
		$vars['ACTION'] = 'createaccount';
		$vars['ACCOUNTNAME'] = $vars['USER_NAME'] = $user->getName();
		
		$filter_result = AbuseFilter::filterAction( $vars, SpecialPage::getTitleFor( 'Userlogin' ) );
		
		$message = $filter_result;
		
		return $filter_result == '' || $filter_result === true;
	}
}
