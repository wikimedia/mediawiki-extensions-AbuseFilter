<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();

class AbuseFilterHooks {

// So far, all of the error message out-params for these hooks accept HTML.
// Hooray!

	public static function onEditFilter($editor, $text, $section, &$error, $summary) {
		// Load vars
		$vars = array();
		
		global $wgUser;
		$vars = array_merge( $vars, AbuseFilter::generateUserVars( $wgUser ) );
		$vars = array_merge( $vars, AbuseFilter::generateTitleVars( $editor->mTitle , 'ARTICLE' ));
		$vars['ACTION'] = 'edit';
		$vars['SUMMARY'] = $summary;
		
		$old_text = $editor->getBaseRevision() ? $editor->getBaseRevision()->getText() : '';
		$new_text = $editor->textbox1;
		$oldLinks = self::getOldLinks( $editor->mTitle );

		$vars = array_merge( $vars, 
			AbuseFilter::getEditVars( $editor->mTitle, $old_text, $new_text, $oldLinks ) );

		$filter_result = AbuseFilter::filterAction( $vars, $editor->mTitle, $oldLinks );

		if( $filter_result !== true ){
			global $wgOut;
			$wgOut->addHTML( $filter_result );
			$editor->showEditForm();
			return false;
		}
		return true;
	}
	
	/**
	 * Load external links from the externallinks table
	 * Stolen from ConfirmEdit
	 */
	static function getOldLinks( $title ) {
		$dbr = wfGetDB( DB_SLAVE );
		$id = $title->getArticleId(); // should be zero queries
		$res = $dbr->select( 'externallinks', array( 'el_to' ), 
			array( 'el_from' => $id ), __METHOD__ );
		$links = array();
		while ( $row = $dbr->fetchObject( $res ) ) {
			$links[] = $row->el_to;
		}
		return $links;
	}
	
	public static function onGetAutoPromoteGroups( $user, &$promote ) {
		global $wgMemc;
		
		$key = AbuseFilter::autoPromoteBlockKey( $user );
		
		if ($wgMemc->get( $key ) ) {
			$promote = array();
		}
		
		return true;
	}
	
	public static function onAbortMove( $oldTitle, $newTitle, $user, &$error, $reason ) {
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
	
	public static function onArticleDelete( &$article, &$user, &$reason, &$error ) {
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
	
	public static function onAbortNewAccount( $user, &$message ) {
		wfLoadExtensionMessages( 'AbuseFilter' );
		if ($user->getName() == wfMsgForContent( 'abusefilter-blocker' )) {
			$message = wfMsg( 'abusefilter-accountreserved' );
			return false;
		}
		$vars = array();
		
		$vars['ACTION'] = 'createaccount';
		$vars['ACCOUNTNAME'] = $vars['USER_NAME'] = $user->getName();
		
		$filter_result = AbuseFilter::filterAction( 
			$vars, SpecialPage::getTitleFor( 'Userlogin' ) );
		
		$message = $filter_result;
		
		return $filter_result == '' || $filter_result === true;
	}
	
	public static function onAbortDeleteQueueNominate( $user, $article, $queue, $reason, &$error ) {
		$vars = array();
		
		$vars = array_merge( $vars, 
			AbuseFilter::generateUserVars( $user ), 
			AbuseFilter::generateTitleVars( $article->mTitle, 'ARTICLE' ) );
		$vars['SUMMARY'] = $reason;
		$vars['ACTION'] = 'delnom';
		$vars['QUEUE'] = $queue;
		
		$filter_result = AbuseFilter::filterAction( $vars, $article->mTitle );
		$error = $filter_result;
		
		return $filter_result == '' || $filter_result === true;
	}

	public static function onRecentChangeSave( $recentChange ) {
		$title = Title::makeTitle( 
			$recentChange->mAttribs['rc_namespace'], 
			$recentChange->mAttribs['rc_title'] );
		$action = $recentChange->mAttribs['rc_log_type'] ? 
			$recentChange->mAttribs['rc_log_type'] : 'edit';
		$actionID = implode( '-', array(
				$title->getPrefixedText(), $recentChange->mAttribs['rc_user_text'], $action
			) );

		if ( !empty( AbuseFilter::$tagsToSet[$actionID] ) 
			&& count( $tags = AbuseFilter::$tagsToSet[$actionID]) ) 
		{
			ChangeTags::addTags( 
				$tags, 
				$recentChange->mAttribs['rc_id'], 
				$recentChange->mAttribs['rc_this_oldid'],
				$recentChange->mAttribs['rc_logid'] );
		}

		return true;
	}

	public static function onListDefinedTags( &$emptyTags ) {
		## This is a pretty awful hack.
		$dbr = wfGetDB( DB_SLAVE );

		$res = $dbr->select( 'abuse_filter_action', 'afa_parameters', 
			array( 'afa_consequence' => 'tag' ), __METHOD__ );

		while( $row = $res->fetchObject() ) {
			$emptyTags = array_filter( 
				array_merge( explode( "\n", $row->afa_parameters ), $emptyTags ) 
			);
		}

		return true;
	}

	public static function onLoadExtensionSchemaUpdates() {
		global $wgExtNewTables, $wgExtNewFields;

		$dir = dirname( __FILE__ );
		
		// DB updates
		$wgExtNewTables = array_merge( $wgExtNewTables,
			array(
				array( 'abuse_filter', "$dir/abusefilter.tables.sql" ),
				array( 'abuse_filter_history', "$dir/db_patches/patch-abuse_filter_history.sql" ),
				array( 'abuse_filter_history', 'afh_changed_fields', "$dir/db_patches/patch-afh_changed_fields.sql" ),
				array( 'abuse_filter', 'af_deleted', "$dir/db_patches/patch-af_deleted.sql" ),
				array( 'abuse_filter', 'af_actions', "$dir/db_patches/patch-af_actions.sql" ),
			) );

		return true;
	}
}
