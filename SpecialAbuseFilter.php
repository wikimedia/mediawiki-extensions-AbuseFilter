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
		global $wgUser,$wgOut,$wgRequest;

		$this->setHeaders();

		$this->loadParameters( $subpage );
		$wgOut->setPageTitle( wfMsg( 'abusefilter-management' ) );
		$wgOut->setRobotPolicy( "noindex,nofollow" );
		$wgOut->setArticleRelated( false );
		$wgOut->enableClientCache( false );
		
		// Are we allowed?
		if ( !$wgUser->isAllowed( 'abusefilter-view' ) ) {
			// Go away.
			$this->displayRestrictionError();
			return;
		}
		
		$this->mSkin = $wgUser->getSkin();
		
		if ($subpage == 'tools') {
			// Some useful tools
			$this->doTools();
			return;
		}
		
		if ($subpage == 'history' && $this->showHistory()) {
			return;
		}
		
		if ($output = $this->doEdit()) {
			$wgOut->addHtml( $output );
			return;
		}
		
		// Show list of filters.
		$this->showStatus();
		
		// Quick links
		$wgOut->addWikiMsg( 'abusefilter-links' );
		$lists = array( 'active', 'deleted', 'all', 'tools' );
		$links = '';
		$sk = $wgUser->getSkin();
		foreach( $lists as $list ) {
			$title = $this->getTitle( $list );
			
			$link = $sk->link( $title, wfMsg( "abusefilter-show-$list" ) );
			$links .= Xml::tags( 'li', null, $link ) . "\n";
		}
		$links .= Xml::tags( 'li', null, $sk->link( SpecialPage::getTitleFor( 'AbuseLog' ), wfMsg( 'abusefilter-loglink' ) ) );
		$links = Xml::tags( 'ul', null, $links );
		$wgOut->addHTML( $links );
		
		if ($subpage == 'deleted') {
			$this->showDeleted();
			return;
		}
		
		if ($subpage == 'active') {
			$this->showActive();
			return;
		}
		
		$this->showList();
	}
	
	function showDeleted() {
		$this->showList( array( 'af_deleted' => 1 ) );
	}
	
	function showActive() {
		$this->showList( array( 'af_deleted' => 0, 'af_enabled' => 1 ) );
	}
	
	function showHistory() {
		global $wgRequest,$wgOut;
		
		$filter = $wgRequest->getIntOrNull( 'filter' );
		if (!$filter) {
			return false;
		}
		
		global $wgUser;
		$sk = $wgUser->getSkin();
		$wgOut->setPageTitle( wfMsg( 'abusefilter-history', $filter ) );
		$backToFilter_label = wfMsgExt( 'abusefilter-history-backedit', array('parseinline') );
		$backToList_label = wfMsgExt( 'abusefilter-history-backlist', array('parseinline') );
		$backlinks = $sk->makeKnownLinkObj( $this->getTitle( $filter ), $backToFilter_label ) . '&nbsp;&bull;&nbsp;' .
				$sk->makeKnownLinkObj( $this->getTitle( ), $backToList_label );
		$wgOut->addHTML( Xml::tags( 'p', null, $backlinks ) );
		
		// Produce table
		$table = '';
		
		$headers = array( 'abusefilter-history-timestamp', 'abusefilter-history-user', 'abusefilter-history-public', 'abusefilter-history-flags', 'abusefilter-history-filter', 'abusefilter-history-comments', 'abusefilter-history-actions' );
		$header_row = '';
		foreach( $headers as $header ) {
			$label = wfMsgExt( $header, array( 'parseinline' ) );
			$header_row .= Xml::tags( 'th', null, $label );
		}
		$table .= Xml::tags( 'tr', null, $header_row );
		
		$pager = new AbuseFilterHistoryPager( $filter );
		$table .= $pager->getBody();
		
		$table = "<table class=\"wikitable\"><tbody>$table</table></tbody>";
		
		$wgOut->addHTML( $pager->getNavigationBar() . $table . $pager->getNavigationBar() );
		
		return true;
	}
	
	function doTools() {
		global $wgRequest,$wgOut;
		
		// Header
		$wgOut->setSubTitle( wfMsg( 'abusefilter-tools-subtitle' ) );
		$wgOut->addWikiMsg( 'abusefilter-tools-text' );

		// Expression evaluator
		$eval = '';
		$eval .= Xml::textarea( 'wpTestExpr', "" );
		$eval .= Xml::tags( 'p', null, Xml::element( 'input', array( 'type' => 'button', 'id' => 'mw-abusefilter-submitexpr', 'onclick' => 'doExprSubmit();', 'value' => wfMsg( 'abusefilter-tools-submitexpr' ) ) ) );
		$eval .= Xml::element( 'p', array( 'id' => 'mw-abusefilter-expr-result' ), ' ' );
		$eval = Xml::fieldset( wfMsg( 'abusefilter-tools-expr' ), $eval );
		$wgOut->addHtml( $eval );
		
		// Associated script
		$exprScript = "function doExprSubmit()
		{
			var expr = document.getElementById('wpTestExpr').value;
			injectSpinner( document.getElementById( 'mw-abusefilter-submitexpr' ), 'abusefilter-expr' );
			sajax_do_call( 'AbuseFilter::ajaxEvaluateExpression', [expr], processExprResult );
		}
		function processExprResult( request ) {
			var response = request.responseText;
			
			removeSpinner( 'abusefilter-expr' );
			
			var el = document.getElementById( 'mw-abusefilter-expr-result' );
			changeText( el, response );
		}";
		
		$wgOut->addInlineScript( $exprScript );
	}
	
	function showStatus() {
		global $wgMemc,$wgAbuseFilterConditionLimit,$wgOut, $wgLang;
		
		$overflow_count = (int)$wgMemc->get( AbuseFilter::filterLimitReachedKey() );
		$match_count = (int) $wgMemc->get( AbuseFilter::filterMatchesKey() );
		$total_count = (int)$wgMemc->get( AbuseFilter::filterUsedKey() );
		
		if ($total_count>0) {
			$overflow_percent = sprintf( "%.2f", 100 * $overflow_count / $total_count );
			$match_percent = sprintf( "%.2f", 100 * $match_count / $total_count );

			$status = wfMsgExt( 'abusefilter-status', array( 'parsemag', 'escape' ),
				$wgLang->formatNum($total_count),
				$wgLang->formatNum($overflow_count),
				$wgLang->formatNum($overflow_percent),
				$wgLang->formatNum($wgAbuseFilterConditionLimit),
				$wgLang->formatNum($match_count),
				$wgLang->formatNum($match_percent)
			);
			
			$status = Xml::tags( 'div', array( 'class' => 'mw-abusefilter-status' ), $status );
			$wgOut->addHTML( $status );
		}
	}
	
	function doEdit() {
		global $wgRequest, $wgUser;
		
		$filter = $this->mFilter;
		
		$editToken = $wgRequest->getVal( 'wpEditToken' );
		$didEdit = $this->canEdit() && $wgUser->matchEditToken( $editToken, array( 'abusefilter', $filter ) );
		
		if ($didEdit) {
			// Check syntax
			$syntaxerr = AbuseFilter::checkSyntax( $wgRequest->getVal( 'wpFilterRules' ) );
			if ($syntaxerr !== true ) {
				return $this->buildFilterEditor( wfMsgExt( 'abusefilter-edit-badsyntax', array( 'parseinline' ), array( $syntaxerr ) ) );
			}
		
			$dbw = wfGetDB( DB_MASTER );
			
			list ($newRow, $actions) = $this->loadRequest();
			
			$newRow = get_object_vars($newRow); // Convert from object to array
			
			// Set last modifier.
			$newRow['af_timestamp'] = $dbw->timestamp( wfTimestampNow() );
			$newRow['af_user'] = $wgUser->getId();
			$newRow['af_user_text'] = $wgUser->getName();
			
			$dbw->begin();
			
			if ($filter == 'new') {
				$new_id = $dbw->selectField( 'abuse_filter', 'max(af_id)', array(), __METHOD__ );
				$new_id++;
			} else {
				$new_id = $this->mFilter;
			}
			
			// Actions
			global $wgAbuseFilterAvailableActions;
			$deadActions = array();
			$actionsRows = array();
			foreach( $wgAbuseFilterAvailableActions as $action ) {
				// Check if it's set
				$enabled = isset($actions[$action]) && (bool)$actions[$action];
				
				if ($enabled) {
					$parameters = $actions[$action]['parameters'];
					
					$thisRow = array( 'afa_filter' => $new_id, 'afa_consequence' => $action, 'afa_parameters' => implode( "\n", $parameters ) );
					$actionsRows[] = $thisRow;
				} else {
					$deadActions[] = $action;
				}
			}
			
			// Create a history row
			$history_mappings = array( 'af_pattern' => 'afh_pattern', 'af_user' => 'afh_user', 'af_user_text' => 'afh_user_text', 'af_timestamp' => 'afh_timestamp', 'af_comments' => 'afh_comments', 'af_public_comments' => 'afh_public_comments', 'af_deleted' => 'afh_deleted' );
			
			$afh_row = array();
			
			foreach( $history_mappings as $af_col => $afh_col ) {
				$afh_row[$afh_col] = $newRow[$af_col];
			}
			
			// Actions
			$displayActions = array();
			foreach( $actions as $action ) {
				$displayActions[$action['action']] = $action['parameters'];
			}
			$afh_row['afh_actions'] = serialize($displayActions);
			
			// Flags
			$flags = array();
			if ($newRow['af_hidden'])
				$flags[] = wfMsgForContent( 'abusefilter-history-hidden' );
			if ($newRow['af_enabled'])
				$flags[] = wfMsgForContent( 'abusefilter-history-enabled' );
			if ($newRow['af_deleted'])
				$flags[] = wfMsgForContent( 'abusefilter-history-deleted' );
				
			$afh_row['afh_flags'] = implode( ",", $flags );
				
			$afh_row['afh_filter'] = $new_id;
			
			// Do the update			
			$dbw->insert( 'abuse_filter_history', $afh_row, __METHOD__ );
			$dbw->replace( 'abuse_filter', array( 'af_id' ), $newRow, __METHOD__ );
			$dbw->delete( 'abuse_filter_action', array( 'afa_filter' => $filter, 'afa_consequence' => $deadActions ), __METHOD__ );
			$dbw->replace( 'abuse_filter_action', array( array( 'afa_filter', 'afa_consequence' ) ), $actionsRows, __METHOD__ );
			$dbw->commit();
			
			global $wgOut;
			
			$wgOut->setSubtitle( wfMsg('abusefilter-edit-done-subtitle' ) );
			return wfMsgExt( 'abusefilter-edit-done', array( 'parse' ) );
		} else {
			return $this->buildFilterEditor();
		}
	}
	
	function buildFilterEditor( $error = ''  ) {
		if( $this->mFilter === null ) {
			return false;
		}
		
		// Build the edit form
		global $wgOut,$wgLang,$wgUser;
		$sk = $this->mSkin;
		$wgOut->setSubtitle( wfMsg( 'abusefilter-edit-subtitle', $this->mFilter ) );
		
		list ($row, $actions) = $this->loadRequest();

		if( !$row ) {
			return false;
		}

		if (isset($row->af_hidden) && $row->af_hidden && !$this->canEdit()) {
			return wfMsg( 'abusefilter-edit-denied' );
		}
		
		$output = '';
		if ($error) {
			$wgOut->addHTML( "<span class=\"error\">$error</span>" );
		}
		
		$fields = array();
		
		$fields['abusefilter-edit-id'] = $this->mFilter == 'new' ? wfMsg( 'abusefilter-edit-new' ) : $this->mFilter;
		$fields['abusefilter-edit-description'] = Xml::input( 'wpFilterDescription', 20, isset( $row->af_public_comments ) ? $row->af_public_comments : '' );

		// Hit count display
		if( $this->mFilter !== 'new' ){
			$count = (int)$row->af_hit_count;
			$count_display = wfMsgExt( 'abusefilter-hitcount', array( 'parseinline' ),
				$wgLang->formatNum( $count )
			);
			$hitCount = $sk->makeKnownLinkObj( SpecialPage::getTitleFor( 'AbuseLog' ), $count_display, 'wpSearchFilter='.$row->af_id );
		
			$fields['abusefilter-edit-hitcount'] = $hitCount;
		} else {
			$fields['abusefilter-edit-hitcount'] = '';
		}
		
		if ($this->mFilter !== 'new') {
			// Statistics
			global $wgMemc, $wgLang;
			$matches_count = $wgMemc->get( AbuseFilter::filterMatchesKey( $this->mFilter ) );
			$total = $wgMemc->get( AbuseFilter::filterUsedKey() );
			
			if ($total > 0) {
				$matches_percent = sprintf( '%.2f', 100 * $matches_count / $total );
				$fields['abusefilter-edit-status-label'] =
					wfMsgExt( 'abusefilter-edit-status', array( 'parsemag', 'escape' ),
						$wgLang->formatNum($total),
						$wgLang->formatNum($matches_count),
						$wgLang->formatNum($matches_percent)
					);
			}
		}

		$fields['abusefilter-edit-rules'] = $this->buildEditBox($row);
		$fields['abusefilter-edit-notes'] = Xml::textarea( 'wpFilterNotes', ( isset( $row->af_comments ) ? $row->af_comments."\n" : "\n" ) );
		
		// Build checkboxen
		$checkboxes = array( 'hidden', 'enabled', 'deleted' );
		$flags = '';
		
		if (isset($row->af_throttled) && $row->af_throttled) {
			global $wgAbuseFilterEmergencyDisableThreshold;
			$threshold_percent = sprintf( '%.2f', $wgAbuseFilterEmergencyDisableThreshold * 100 );
			$flags .= $wgOut->parse( wfMsg( 'abusefilter-edit-throttled', $wgLang->formatNum( $threshold_percent ) ) );
		}
		
		foreach( $checkboxes as $checkboxId ) {
			$message = "abusefilter-edit-$checkboxId";
			$dbField = "af_$checkboxId";
			$postVar = "wpFilter".ucfirst($checkboxId);
			
			$checkbox = Xml::checkLabel( wfMsg( $message ), $postVar, $postVar, isset( $row->$dbField ) ? $row->$dbField : false );
			$checkbox = Xml::tags( 'p', null, $checkbox );
			$flags .= $checkbox;
		}
		$fields['abusefilter-edit-flags'] = $flags;
		
		if ($this->mFilter != 'new') {
			// Last modification details
			$fields['abusefilter-edit-lastmod'] = $wgLang->timeanddate( $row->af_timestamp );
			$fields['abusefilter-edit-lastuser'] = $sk->userLink( $row->af_user, $row->af_user_text ) . $sk->userToolLinks( $row->af_user, $row->af_user_text );
			$history_display = wfMsgExt( 'abusefilter-edit-viewhistory', array( 'parseinline' ) );
			$fields['abusefilter-edit-history'] = $sk->makeKnownLinkObj( $this->getTitle( 'history' ), $history_display, "filter=".$this->mFilter );
		}
		
		$form = Xml::buildForm( $fields );
		$form = Xml::fieldset( wfMsg( 'abusefilter-edit-main' ), $form );
		$form .= Xml::fieldset( wfMsg( 'abusefilter-edit-consequences' ), $this->buildConsequenceEditor( $row, $actions ) );
		
		if ($this->canEdit()) {
			$form .= Xml::submitButton( wfMsg( 'abusefilter-edit-save' ) );
			$form .= Xml::hidden( 'wpEditToken', $wgUser->editToken( array( 'abusefilter', $this->mFilter )) );
		}
		
		$form = Xml::tags( 'form', array( 'action' => $this->getTitle( $this->mFilter )->getFullURL(), 'method' => 'POST' ), $form );
		
		$output .= $form;
		
		return $output;
	}
	
	function buildEditBox( $row ) {
		global $wgOut;
		
		$rules = Xml::textarea( 'wpFilterRules', ( isset( $row->af_pattern ) ? $row->af_pattern."\n" : "\n" ) );
		
		$dropDown = array(
			'op-arithmetic' => array('+' => 'addition', '-' => 'subtraction', '*' => 'multiplication', '/' => 'divide', '%' => 'modulo', '**' => 'pow'),
			'op-comparison' => array('==' => 'equal', '!=' => 'notequal', '<' => 'lt', '>' => 'gt', '<=' => 'lte', '>=' => 'gte'),
			'op-bool' => array( '!' => 'not', '&' => 'and', '|' => 'or', '^' => 'xor' ),
			'misc' => array( 'val1 ? iftrue : iffalse' => 'ternary', 'in' => 'in', 'like' => 'like', '""' => 'stringlit', ),
			'funcs' => array( 'length(string)' => 'length', 'lcase(string)' => 'lcase', 'ccnorm(string)' => 'ccnorm', 'rmdoubles(string)' => 'rmdoubles', 'specialratio(string)' => 'specialratio', 'norm(string)' => 'norm', 'count(needle,haystack)' => 'count' ),
			'vars' => array( 'ACCOUNTNAME' => 'accountname', 'ACTION' => 'action', 'ADDED_LINES' => 'addedlines', 'EDIT_DELTA' => 'delta', 'EDIT_DIFF' => 'diff', 'NEW_SIZE' => 'newsize', 'OLD_SIZE' => 'oldsize', 'REMOVED_LINES' => 'removedlines', 'SUMMARY' => 'summary', 'ARTICLE_ARTICLEID' => 'article-id', 'ARTICLE_NAMESPACE' => 'article-ns', 'ARTICLE_TEXT' => 'article-text', 'ARTICLE_PREFIXEDTEXT' => 'article-prefixedtext', 'MOVED_FROM_ARTICLEID' => 'movedfrom-id', 'MOVED_FROM_NAMESPACE' => 'movedfrom-ns', 'MOVED_FROM_TEXT' => 'movedfrom-text', 'MOVED_FROM_PREFIXEDTEXT' => 'movedfrom-prefixedtext', 'MOVED_TO_ARTICLEID' => 'movedto-id', 'MOVED_TO_NAMESPACE' => 'movedto-ns', 'MOVED_TO_TEXT' => 'movedto-text', 'MOVED_TO_PREFIXEDTEXT' => 'movedto-prefixedtext', 'USER_EDITCOUNT' =>  'user-editcount', 'USER_AGE' => 'user-age', 'USER_NAME' => 'user-name', 'USER_GROUPS' => 'user-groups', 'USER_EMAILCONFIRM' => 'user-emailconfirm'),
		);
		
		// Generate builder drop-down
		$builder = '';
		
		$builder .= Xml::option( wfMsg( "abusefilter-edit-builder-select") );
		
		foreach( $dropDown as $group => $values ) {
			$builder .= Xml::openElement( 'optgroup', array( 'label' => wfMsg( "abusefilter-edit-builder-group-$group" ) ) ) . "\n";
			
			foreach( $values as $content => $name ) {
				$builder .= Xml::option( wfMsg( "abusefilter-edit-builder-$group-$name" ), $content ) . "\n";
			}
			
			$builder .= Xml::closeElement( 'optgroup' ) . "\n";
		}
		
		$rules .= Xml::tags( 'select', array( 'id' => 'wpFilterBuilder', 'onchange' => 'addText();' ), $builder );
		
		// Add syntax checking
		$rules .= Xml::element( 'input', array( 'type' => 'button', 'onclick' => 'doSyntaxCheck()', 'value' => wfMsg( 'abusefilter-edit-check' ), 'id' => 'mw-abusefilter-syntaxcheck' ) );
		
		// Add script
		$scScript = file_get_contents(dirname(__FILE__)."/edit.js");
		
		$wgOut->addInlineScript( $scScript );
		
		return $rules;
	}
	
	function buildConsequenceEditor( $row, $actions ) {
		global $wgAbuseFilterAvailableActions;
		$setActions = array();
		foreach( $wgAbuseFilterAvailableActions as $action ) {
			$setActions[$action] = array_key_exists( $action, $actions );
		}
		
		$output = '';
		
		// Special case: flagging - always on.
		$checkbox = Xml::checkLabel( wfMsg( 'abusefilter-edit-action-flag' ), 'wpFilterActionFlag', 'wpFilterActionFlag', true, array( 'disabled' => '1' ) );
		$output .= Xml::tags( 'p', null, $checkbox );
		
		// Special case: throttling
		$throttleSettings = Xml::checkLabel( wfMsg( 'abusefilter-edit-action-throttle' ), 'wpFilterActionThrottle', 'wpFilterActionThrottle', $setActions['throttle'] );
		$throttleFields = array();
		
		if ($setActions['throttle']) {
			$throttleRate = explode(',',$actions['throttle']['parameters'][0]);
			$throttleCount = $throttleRate[0];
			$throttlePeriod = $throttleRate[1];
			
			$throttleGroups = implode("\n", array_slice($actions['throttle']['parameters'], 1 ) );
		} else {
			$throttleCount = 3;
			$throttlePeriod = 60;
			
			$throttleGroups = "user\n";
		}
		
		$throttleFields['abusefilter-edit-throttle-count'] = Xml::input( 'wpFilterThrottleCount', 20, $throttleCount );
		$throttleFields['abusefilter-edit-throttle-period'] = wfMsgExt( 'abusefilter-edit-throttle-seconds', array( 'parseinline', 'replaceafter' ), array(Xml::input( 'wpFilterThrottlePeriod', 20, $throttlePeriod )  ) );
		$throttleFields['abusefilter-edit-throttle-groups'] = Xml::textarea( 'wpFilterThrottleGroups', $throttleGroups."\n" );
		$throttleSettings .= Xml::buildForm( $throttleFields );
		$output .= Xml::tags( 'p', null, $throttleSettings );
		
		// The remainder are just toggles
		$remainingActions = array_diff( $wgAbuseFilterAvailableActions, array( 'flag', 'throttle' ) );
		
		foreach( $remainingActions as $action ) {
			$message = 'abusefilter-edit-action-'.$action;
			$form_field = 'wpFilterAction' . ucfirst($action);
			$status = $setActions[$action];
			
			$thisAction = Xml::checkLabel( wfMsg( $message ), $form_field, $form_field, $status );
			$thisAction = Xml::tags( 'p', null, $thisAction );
			
			$output .= $thisAction;
		}
		
		return $output;
	}
	
	function loadFilterData() {
		$id = $this->mFilter;
		
		$dbr = wfGetDB( DB_SLAVE );
		
		// Load the main row
		$row = $dbr->selectRow( 'abuse_filter', '*', array( 'af_id' => $id ), __METHOD__ );
		
		if (!isset($row) || !isset($row->af_id) || !$row->af_id)
			return array( new stdClass,array() );
		
		// Load the actions
		$actions = array();
		$res = $dbr->select( 'abuse_filter_action', '*', array( 'afa_filter' => $id), __METHOD__ );
		while ( $actionRow = $dbr->fetchObject( $res ) ) {
			$thisAction = array();
			$thisAction['action'] = $actionRow->afa_consequence;
			$thisAction['parameters'] = explode( "\n", $actionRow->afa_parameters );
			
			$actions[$actionRow->afa_consequence] = $thisAction;
		}
		
		return array( $row, $actions );
	}
	
	function loadParameters( $subpage ) {
		global $wgRequest;
		
		$filter = $subpage;
		
		if (!is_numeric($filter) && $filter != 'new') {
			$filter = $wgRequest->getIntOrNull( 'wpFilter' );
		}
		$this->mFilter = $filter;
	}
	
	function loadRequest() {
		static $row = null;
		static $actions = null;
		global $wgRequest;
		
		if (!is_null($actions) && !is_null($row)) {
			return array($row,$actions);
		} elseif ( !$wgRequest->wasPosted() ) {
			return $this->loadFilterData();
		}
		
		// We need some details like last editor
		list($row) = $this->loadFilterData();
		
		$textLoads = array( 'af_public_comments' => 'wpFilterDescription', 'af_pattern' => 'wpFilterRules', 'af_comments' => 'wpFilterNotes' );
		
		foreach( $textLoads as $col => $field ) {
			$row->$col = $wgRequest->getVal( $field );
		}
		
		$row->af_deleted = $wgRequest->getBool( 'wpFilterDeleted' );
		$row->af_enabled = $wgRequest->getBool( 'wpFilterEnabled' ) && !$row->af_deleted;
		$row->af_hidden = $wgRequest->getBool( 'wpFilterHidden' );
		
		// Actions
		global $wgAbuseFilterAvailableActions;
		$actions = array();
		foreach( $wgAbuseFilterAvailableActions as $action ) {
			// Check if it's set
			$enabled = $wgRequest->getBool( 'wpFilterAction'.ucfirst($action) );
			
			if ($enabled) {
				$parameters = array();
				
				if ($action == 'throttle') {
					// Grumble grumble.
					// We need to load the parameters
					$throttleCount = $wgRequest->getIntOrNull( 'wpFilterThrottleCount' );
					$throttlePeriod = $wgRequest->getIntOrNull( 'wpFilterThrottlePeriod' );
					$throttleGroups = explode("\n", trim( $wgRequest->getText( 'wpFilterThrottleGroups' ) ) );
					
					$parameters[0] = $this->mFilter; // For now, anyway
					$parameters[1] = "$throttleCount,$throttlePeriod";
					$parameters = array_merge( $parameters, $throttleGroups );
				}
				
				$thisAction = array( 'action' => $action, 'parameters' => $parameters );
				$actions[$action] = $thisAction;
			}
		}
		
		return array( $row, $actions );
	}
	
	function canEdit() {
		global $wgUser;
		static $canEdit = 'unset';
		
		if ($canEdit == 'unset') {
			$canEdit = !count( $errors = $this->getTitle()->getUserPermissionsErrors( 'abusefilter-modify', $wgUser, true, array( 'ns-specialprotected' ) ) );
		}
		
		return $canEdit;
	}
	
	function showList( $conds = array( 'af_deleted' => 0 )) {
		global $wgOut,$wgUser;
		
		$sk = $this->mSkin = $wgUser->getSkin();
		
		$output = '';
		
		$output .= Xml::element( 'h2', null, wfMsgExt( 'abusefilter-list', array( 'parseinline' ) ) );
		
		// We shouldn't have more than 100 filters, so don't bother paging.
		$dbr = wfGetDB( DB_SLAVE );
		$abuse_filter = $dbr->tableName( 'abuse_filter' );
		$res = $dbr->select( array('abuse_filter', 'abuse_filter_action'), $abuse_filter.'.*,group_concat(afa_consequence) AS consequences', $conds, __METHOD__, array( 'LIMIT' => 100, 'GROUP BY' => 'af_id' ),
			array( 'abuse_filter_action' => array('LEFT OUTER JOIN', 'afa_filter=af_id' ) ) );
		$list = '';
		$editLabel = $this->canEdit() ? 'abusefilter-list-edit' : 'abusefilter-list-details';
		
		// Build in a table
		$headers = array( 'abusefilter-list-id', 'abusefilter-list-public', 'abusefilter-list-consequences', 'abusefilter-list-status', 'abusefilter-list-visibility', 'abusefilter-list-hitcount', $editLabel );
		$header_row = '';
		foreach( $headers as $header ) {
			$header_row .= Xml::element( 'th', null, wfMsgExt( $header, array( 'parseinline' ) ) );
		}
		
		$list .= Xml::tags( 'tr', null, $header_row );
		
		while ($row = $dbr->fetchObject( $res ) ) {
			$list .= $this->shortFormatFilter( $row );
		}
		
		$output .= Xml::tags( 'table', array( 'class' => 'wikitable' ), Xml::tags( 'tbody', null, $list ) );
		
		if ($this->canEdit()) {
			$output .= $sk->makeKnownLinkObj( $this->getTitle( 'new' ), wfMsgHtml( 'abusefilter-list-new' ) );
		}
		
		$wgOut->addHTML( $output );
	}
	
	function shortFormatFilter( $row ) {
		global $wgOut, $wgLang;
		
		$sk = $this->mSkin;
		
		$editLabel = $this->canEdit() ? 'abusefilter-list-edit' : 'abusefilter-list-details';
		
		// Build a table row
		$trow = '';
		
		if ($row->af_deleted) {
			$status = wfMsgExt( 'abusefilter-deleted', array( 'parseinline' ) );
		} else {
			$status = $row->af_enabled ? 'abusefilter-enabled' : 'abusefilter-disabled';
			$status = wfMsgExt( $status, array( 'parseinline' ) );
		}
		
		$visibility = $row->af_hidden ? 'abusefilter-hidden' : 'abusefilter-unhidden';
		$visibility = wfMsgExt( $visibility, array( 'parseinline' ) );
		
		// Hit count
		$count = $row->af_hit_count;
		$count_display = wfMsgExt( 'abusefilter-hitcount', array( 'parseinline' ), $wgLang->formatNum( $count ) );
		$hitCount = $sk->makeKnownLinkObj( SpecialPage::getTitleFor( 'AbuseLog' ), $count_display, 'wpSearchFilter='.$row->af_id );
		
		$editLink = $sk->makeKnownLinkObj( $this->getTitle( $row->af_id ), wfMsgHtml( $editLabel ) );
		
		$consequences = wfEscapeWikitext($row->consequences);
		
		$values = array( $row->af_id, $wgOut->parse($row->af_public_comments), $consequences, $status, $visibility, $hitCount, $editLink );
		
		foreach( $values as $value ) {
			$trow .= Xml::tags( 'td', null, $value );
		}
		
		$trow = Xml::tags( 'tr', null, $trow );
		
		return $trow;
	}
}

class AbuseFilterHistoryPager extends ReverseChronologicalPager {

	function __construct( $filter ) {
		$this->mFilter = $filter;
		parent::__construct();
	}

	function formatRow( $row ) {
		static $sk=null;
		
		if (is_null($sk)) {
			global $wgUser;
			$sk = $wgUser->getSkin();
		}
	
		global $wgLang;
		
		$tr = '';
		
		$tr .= Xml::element( 'td', null, $wgLang->timeanddate( $row->afh_timestamp ) );
		$tr .= Xml::tags( 'td', null, $sk->userLink( $row->afh_user, $row->afh_user_text ) . $sk->userToolLinks( $row->afh_user, $row->afh_user_text ) );
		$tr .= Xml::element( 'td', null, $row->afh_public_comments );
		$tr .= Xml::element( 'td', null, $row->afh_flags );
		$tr .= Xml::element( 'td', null, $row->afh_pattern );
		$tr .= Xml::element( 'td', null, $row->afh_comments );
		
		// Build actions
		$actions = unserialize($row->afh_actions);
		$display_actions = '';
		
		foreach( $actions as $action => $parameters ) {
			$display_actions .= Xml::tags( 'li', null, wfMsgExt( 'abusefilter-history-action', array( 'parseinline' ), array($action, implode(';', $parameters)) ) );
		}
		$display_actions = Xml::tags( 'ul', null, $display_actions );
		
		$tr .= Xml::tags( 'td', null, $display_actions );
		
		return Xml::tags( 'tr', null, $tr );
	}
	
	function getQueryInfo() {
		return array(
			'tables' => 'abuse_filter_history',
			'fields' => '*',
			'conds' => array( 'afh_filter' => $this->mFilter ),
		);
	}
	
	function getIndexField() {
		return 'afh_timestamp';
	}
}