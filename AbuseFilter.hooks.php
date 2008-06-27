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
		
		// TODO:
// 		// Include added/removed lines in the vars.

		$filter_result = AbuseFilter::filterAction( $vars, $editor->mTitle );
		
		$error = $filter_result;
		
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
		
		$error = "BLAH\n$filter_result";
		
		return $filter_result == '';
	}
	
	function onArticleDelete( &$article, &$user, &$reason, &$error ) {
		$vars = array();
		
		global $wgUser;
		$vars = array_merge( $vars, AbuseFilter::generateUserVars( $wgUser ),
					AbuseFilter::generateTitleVars( $article->mTitle, 'ARTICLE' ) );
		$vars['SUMMARY'] = $reason;
		$vars['ACTION'] = 'delete';
		
		$filter_result = AbuseFilter::filterAction( $vars, $article->mTitle );
		
		$error = "BLAH\n$filter_result";
		
		return $filter_result == '';
	}
	
	function onAbortNewAccount( $username, &$message ) {
		$vars = array();
		
		$vars['ACTION'] = 'createaccount';
		$vars['ACCOUNTNAME'] = $username;
		
		$filter_result = AbuseFilter::filterAction( $vars, SpecialPage::getTitleFor( 'Userlogin' ) );
		
		$error = "BLAH\n$filter_result";
		
		return $filter_result == '';
	}
}
