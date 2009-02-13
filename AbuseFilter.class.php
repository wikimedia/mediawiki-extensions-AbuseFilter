<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();

class AbuseFilter {

	public static $statsStoragePeriod = 86400;
	public static $tokenCache = array();
	public static $modifyCache = array();
	public static $condLimitEnabled = true;
	public static $condCount = 0;
	public static $filters = array();
	public static $tagsToSet = array();
	public static $history_mappings = array(
		'af_pattern' => 'afh_pattern',
		'af_user' => 'afh_user',
		'af_user_text' => 'afh_user_text',
		'af_timestamp' => 'afh_timestamp',
		'af_comments' => 'afh_comments',
		'af_public_comments' => 'afh_public_comments',
		'af_deleted' => 'afh_deleted',
		'af_id' => 'afh_filter'
	);
	public static $builderValues = array(
		'op-arithmetic' => array(
			'+' => 'addition',
			'-' => 'subtraction',
			'*' => 'multiplication',
			'/' => 'divide',
			'%' => 'modulo',
			'**' => 'pow'
		),
		'op-comparison' => array(
			'==' => 'equal',
			'!=' => 'notequal',
			'<' => 'lt',
			'>' => 'gt',
			'<=' => 'lte',
			'>=' => 'gte'
		),
		'op-bool' => array(
			'!' => 'not',
			'&' => 'and',
			'|' => 'or',
			'^' => 'xor'
		),
		'misc' => array(
			'in' => 'in',
			'like' => 'like',
			'""' => 'stringlit',
			'rlike' => 'rlike'
		),
		'funcs' => array(
			'length(string)' => 'length',
			'lcase(string)' => 'lcase',
			'ccnorm(string)' => 'ccnorm',
			'rmdoubles(string)' => 'rmdoubles',
			'specialratio(string)' => 'specialratio',
			'norm(string)' => 'norm',
			'count(needle,haystack)' => 'count'
		),
		'vars' => array(
			'accountname' => 'accountname',
			'action' => 'action',
			'added_lines' => 'addedlines',
			'edit_delta' => 'delta',
			'edit_diff' => 'diff',
			'new_size' => 'newsize',
			'old_size' => 'oldsize',
			'removed_lines' => 'removedlines',
			'summary' => 'summary',
			'article_articleid' => 'article-id',
			'article_namespace' => 'article-ns',
			'article_text' => 'article-text',
			'article_prefixedtext' => 'article-prefixedtext',
			'moved_from_articleid' => 'movedfrom-id',
			'moved_from_namespace' => 'movedfrom-ns',
			'moved_from_text' => 'movedfrom-text',
			'moved_from_prefixedtext' => 'movedfrom-prefixedtext',
			'moved_to_articleid' => 'movedto-id',
			'moved_to_namespace' => 'movedto-ns',
			'moved_to_text' => 'movedto-text',
			'moved_to_prefixedtext' => 'movedto-prefixedtext',
			'user_editcount' =>  'user-editcount',
			'user_age' => 'user-age',
			'user_name' => 'user-name',
			'user_groups' => 'user-groups',
			'user_emailconfirm' => 'user-emailconfirm',
			'old_wikitext' => 'old-text',
			'new_wikitext' => 'new-text',
			'added_links' => 'added-links',
			'removed_links' => 'removed-links',
			'all_links' => 'all-links',
			'new_text' => 'new-text-stripped',
			'new_html' => 'new-html',
			'article_restrictions_edit' => 'restrictions-edit',
			'article_restrictions_move' => 'restrictions-move',
			'article_recent_contributors' => 'recent-contributors',
		),
	);

	public static function generateUserVars( $user ) {
		$vars = array();
		
		// Load all the data we want.
		$user->load();
		
		$vars['USER_EDITCOUNT'] = $user->getEditCount();
		$vars['USER_AGE'] = time() - wfTimestampOrNull( TS_UNIX, $user->getRegistration() );
		$vars['USER_NAME'] = $user->getName();
		$vars['USER_GROUPS'] = implode(',', $user->getEffectiveGroups() );
		$vars['USER_EMAILCONFIRM'] = $user->getEmailAuthenticationTimestamp();
		
		// More to come
		
		return $vars;
	}
	
	public static function ajaxCheckSyntax( $filter ) {
		wfLoadExtensionMessages( 'AbuseFilter' );
		
		$result = self::checkSyntax( $filter );
		
		$ok = ($result === true);
		
		if ($ok) {
			return "OK";
		} else {
			return "ERR: ".json_encode( $result );
		}
	}

	public static function ajaxGetFilter( $filter ) {
		global $wgUser;
		if ( !$wgUser->isAllowed( 'abusefilter-view' ) ) {
			return false;
		}

		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow( 'abuse_filter', '*', array( 'af_id' => $filter ), __METHOD__ );

		if ( $row->af_hidden && !$wgUser->isAllowed( 'abusefilter-modify' ) ) {
			return false;
		}

		return strval($row->af_pattern);
	}

	public static function ajaxCheckFilterWithVars( $filter, $vars ) {
		global $wgUser;

		// Anti-DoS
		if ( !$wgUser->isAllowed( 'abusefilter-view' ) ) {
			return false;
		}

		// If we have a syntax error.
		if ( self::checkSyntax( $filter ) !== true ) {
			return 'SYNTAXERROR';
		}
		
		$vars = json_decode( $vars, true );
		$result = self::checkConditions( $filter, $vars );

		return $result ? 'MATCH' : 'NOMATCH';
	}

	public static function triggerLimiter( $val = 1 ) {
		self::$condCount += $val;

		global $wgAbuseFilterConditionLimit;

		if (self::$condCount > $wgAbuseFilterConditionLimit) {
			throw new MWException( "Condition limit reached." );
		}
	}

	public static function disableConditionLimit() {
		// For use in batch scripts and the like
		self::$condLimitEnabled = false;
	}
	
	public static function generateTitleVars( $title, $prefix ) {
		$vars = array();

		if (!$title)
			return array();
		
		$vars[$prefix."_ARTICLEID"] = $title->getArticleId();
		$vars[$prefix."_NAMESPACE"] = $title->getNamespace();
		$vars[$prefix."_TEXT"] = $title->getText();
		$vars[$prefix."_PREFIXEDTEXT"] = $title->getPrefixedText();

		// Use restrictions.
		if ($title->mRestrictionsLoaded) {
			// Don't bother if they're unloaded
			foreach( $title->mRestrictions as $action => $rights ) {
				$rights = count($rights) ? $rights : array();
				$vars[$prefix."_RESTRICTIONS_".$action] = implode(',', $rights );
			}
		}
		
		// Find last 5 authors.
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'revision', 'distinct rev_user_text',
			array( 'rev_page' => $title->getArticleId() ), __METHOD__,
			array( 'ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 10 ) );
		$users = array();
		while ($user = $dbr->fetchRow($res)) {
			$users[] = $user[0];
		}
		$vars[$prefix."_RECENT_CONTRIBUTORS"] = implode(',', $users);
		
		return $vars;
	}
	
	public static function checkSyntax( $filter ) {
		global $wgAbuseFilterParserClass;
		
		$parser = new $wgAbuseFilterParserClass;
		
		return $parser->checkSyntax( $filter );
	}
	
	public static function evaluateExpression( $expr, $vars = array() ) {
		wfLoadExtensionMessages( 'AbuseFilter' );
		global $wgAbuseFilterParserClass;

		if (self::checkSyntax( $expr ) !== true) {
			return '';
		}
		
		$parser = new $wgAbuseFilterParserClass;
		
		$parser->setVars( $vars );
		
		return $parser->evaluateExpression( $expr );
	}
	
	public static function ajaxReAutoconfirm( $username ) {
	
		if (!$wgUser->isAllowed('abusefilter-modify')) {
			// Don't allow it.
			return wfMsg( 'abusefilter-reautoconfirm-notallowed' );
		}
	
		$u = User::newFromName( $username );
		
		global $wgMemc;
		$k = AbuseFilter::autoPromoteBlockKey($u);
		
		if (!$wgMemc->get( $k ) ) {
			return wfMsg( 'abusefilter-reautoconfirm-none' );
		}
		
		$wgMemc->delete( $k );
	}
	
	public static function ajaxEvaluateExpression( $expr ) {
		return self::evaluateExpression( $expr );
	}

	public static function checkConditions( $conds, $vars, $ignoreError = true ) {
		global $wgAbuseFilterParserClass;
		
		wfProfileIn( __METHOD__ );
		
		try {
			$parser = new $wgAbuseFilterParserClass;
			
			$parser->setVars( $vars );
			$result = $parser->parse( $conds, self::$condCount );
		} catch (Exception $excep) {
			// Sigh.
			$result = false;

			if (!$ignoreError) {
				throw $excep;
			}
		}
		
		wfProfileOut( __METHOD__ );
		
		return $result;
	}

	/** Returns an associative array of filters which were tripped */
	public static function checkAllFilters( $vars ) {
		// Fetch from the database.
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'abuse_filter', '*', array( 'af_enabled' => 1, 'af_deleted' => 0 ) );

		$filter_matched = array();

		while ( $row = $dbr->fetchObject( $res ) ) {
			// Store the row somewhere convenient
			self::$filters[$row->af_id] = $row;

			// Check conditions...
			$pattern = trim($row->af_pattern);
			if ( self::checkConditions( $pattern, $vars ) ) {
				// Record match.
				$filter_matched[$row->af_id] = true;
			} else {
				// Record non-match.
				$filter_matched[$row->af_id] = false;
			}
		}

		// Update statistics, and disable filters which are over-blocking.
		self::recordStats( $filter_matched );

		return $filter_matched;
	}

	/** Returns an array [ list of actions taken by filter, error message to display, if any ] */
	public static function executeFilterActions( $filters, $title, $vars ) {
		$dbr = wfGetDB( DB_SLAVE );
		// Retrieve the consequences.
		$res = $dbr->select( array('abuse_filter_action', 'abuse_filter'), '*',
			array( 'af_id' => $filters ), __METHOD__, array(),
			array( 'abuse_filter_action' => array('LEFT JOIN', 'afa_filter=af_id') ) );

		$actionsByFilter = array_fill_keys( $filters, array() );
		$actionsTaken = array_fill_keys( $filters, array() );

		// Categorise consequences by filter.
		global $wgAbuseFilterRestrictedActions;
		while ( $row = $dbr->fetchObject( $res ) ) {
			if ( $row->af_throttled
				&& in_array( $row->afa_consequence, $wgAbuseFilterRestrictedActions ) )
			{
				## Don't do the action
			} elseif ( $row->afa_filter != $row->af_id ) {
				// We probably got a NULL, as it's a LEFT JOIN.
				//  Don't add it anyway.
			} else {
				$actionsByFilter[$row->afa_filter][$row->afa_consequence] = array(
					'action' => $row->afa_consequence,
					'parameters' => explode( "\n", $row->afa_parameters )
				);
			}
		}

		wfLoadExtensionMessages( 'AbuseFilter' );

		$messages = array();

		foreach( $actionsByFilter as $filter => $actions ) {
			// Special-case handling for warnings.
			global $wgOut;
			$parsed_public_comments = $wgOut->parseInline(
				self::$filters[$filter]->af_public_comments ); 

			if ( !empty( $actions['throttle'] ) ) {
				$parameters = $actions['throttle']['parameters'];
				$throttleId = array_shift( $parameters );
				list( $rateCount, $ratePeriod ) = explode( ',', array_shift( $parameters ) );

				$hitThrottle = false;

				// The rest are throttle-types.
				foreach( $parameters as $throttleType ) {
					$hitThrottle = $hitThrottle || self::isThrottled(
						$throttleId, $throttleType, $title, $rateCount, $ratePeriod );
				}

				unset( $actions['throttle'] );
				if (!$hitThrottle) {
					$actionsTaken[$filter][] = 'throttle';
					continue;
				}
			}
			
			if ( !empty( $actions['warn'] ) ) {
				$parameters = $actions['warn']['parameters'];
				$warnKey = 'abusefilter-warned-'.$title->getPrefixedText();
				if (!isset($_SESSION[$warnKey]) || !$_SESSION[$warnKey]) {
					$_SESSION[$warnKey] = true;

					// Threaten them a little bit
					if ( !empty($parameters[0]) && strlen($parameters[0]) ) {
						$msg = $parameters[0];
					} else {
						$msg = 'abusefilter-warning';
					}
					$messages[] = wfMsgExt( $msg, 'parseinline',
						array( $parsed_public_comments) ) . "<br />\n";

					$actionsTaken[$filter][] = 'warn';

					continue; // Don't do anything else.
				} else {
					// We already warned them
					$_SESSION[$warnKey] = false;
				}
				
				unset( $actions['warn'] );
			}

			if ( count($actions)>1 && !empty( $actions['disallow'] ) ) {
				unset( $actions['disallow'] );
			}

			// Do the rest of the actions
			foreach( $actions as $action => $info ) {
				$newMsg = self::takeConsequenceAction(
					$action, $info['parameters'], $title, $vars,
					self::$filters[$filter]->af_public_comments);

				if ($newMsg)
					$messages[] = $newMsg;
				$actionsTaken[$filter][] = $action;
			}
		}

		return array( $actionsTaken, implode( "\n", $messages ) );
	}
	
	public static function filterAction( $vars, $title ) {
		global $wgUser,$wgMemc;

		$dbr = wfGetDB( DB_SLAVE );

		$filter_matched = self::checkAllFilters( $vars );

		// Short-cut any remaining code if no filters were hit.
		if ( count( array_filter( $filter_matched) ) == 0 ) {
			return true;
		}

		list( $actions_taken, $error_msg ) = self::executeFilterActions(
			array_keys( array_filter( $filter_matched ) ), $title, $vars );

		// Create a template
		$log_template = array(
			'afl_user' => $wgUser->getId(),
			'afl_user_text' => $wgUser->getName(),
			'afl_var_dump' => serialize( $vars ),
			'afl_timestamp' => $dbr->timestamp(wfTimestampNow()),
			'afl_namespace' => $title->getNamespace(),
			'afl_title' => $title->getDBKey(),
			'afl_ip' => wfGetIp() );

		self::addLogEntries( $actions_taken, $log_template, $vars['ACTION'] );

		$error_msg = $error_msg == '' ? true : $error_msg;
		
		return $error_msg;
	}

	public static function addLogEntries( $actions_taken, $log_template, $action ) {
		$dbw = wfGetDB( DB_MASTER );

		$log_rows = array();
		$logged_filters = array();

		foreach( $actions_taken as $filter => $actions ) {
			$thisLog = $log_template;
			$thisLog['afl_filter'] = $filter;
			$thisLog['afl_action'] = $action;
			$thisLog['afl_actions'] = implode( ',', $actions );

			// Don't log if we were only throttling.
			if ( $thisLog['afl_actions'] != 'throttle' ) {
				$log_rows[] = $thisLog;
				$logged_filters[] = $filter;
			}
		}

		if ( !count($log_rows) ) {
			return;
		}
		
		global $wgMemc;
		
		// Increment trigger counter
		$wgMemc->incr( self::filterMatchesKey() );

		$dbw->insert( 'abuse_filter_log', $log_rows, __METHOD__ );

		// Update hit-counter.
		$dbw->update( 'abuse_filter', array( 'af_hit_count=af_hit_count+1' ),
			array( 'af_id' => $logged_filters ),
			__METHOD__ );
			
		// Check for emergency disabling.
		$total = $wgMemc->get( AbuseFilter::filterUsedKey() );
		self::checkEmergencyDisable( $logged_filters, $total );
	}
	
	public static function takeConsequenceAction( $action, $parameters, $title,
		$vars, $rule_desc )
	{
		wfLoadExtensionMessages( 'AbuseFilter' );
		$display = '';
		switch ($action) {
			case 'disallow':
				if (strlen($parameters[0])) {
					$display .= wfMsgExt( $parameters[0], 'parseinline', array($rule_desc) ) . "\n";
				} else {
					// Generic message.
					$display .= wfMsgExt( 'abusefilter-disallowed', 'parseinline',
						array($rule_desc) ) ."<br />\n";
				}
				break;
				
			case 'block':
				global $wgUser;
				$filterUser = AbuseFilter::getFilterUser();

				// Create a block.
				$block = new Block;
				$block->mAddress = $wgUser->getName();
				$block->mUser = $wgUser->getId();
				$block->mBy = $filterUser->getId();
				$block->mByName = $filterUser->getName();
				$block->mReason = wfMsgForContent( 'abusefilter-blockreason', $rule_desc );
				$block->mTimestamp = wfTimestampNow();
				$block->mAnonOnly = 1;
				$block->mCreateAccount = 1;
				$block->mExpiry = 'infinity';

				$block->insert();
				
				// Log it
				# Prepare log parameters
				$logParams = array();
				$logParams[] = 'indefinite';
				$logParams[] = 'nocreate, angry-autoblock';
	
				$log = new LogPage( 'block' );
				$log->addEntry( 'block',
					Title::makeTitle( NS_USER, $wgUser->getName() ),
					wfMsgForContent( 'abusefilter-blockreason', $rule_desc ),
					$logParams, self::getFilterUser() );
				
				$display .= wfMsgExt( 'abusefilter-blocked-display', 'parseinline',
					array($rule_desc) ) ."<br />\n";
				break;
			case 'rangeblock':
				global $wgUser;
				$filterUser = AbuseFilter::getFilterUser();
				
				$range = IP::toHex( wfGetIP() );
				$range = substr( $range, 0, 4 ) . '0000';
				$range = long2ip( hexdec( $range ) );
				$range .= "/16";
				$range = Block::normaliseRange( $range );

				// Create a block.
				$block = new Block;
				$block->mAddress = $range;
				$block->mUser = 0;
				$block->mBy = $filterUser->getId();
				$block->mByName = $filterUser->getName();
				$block->mReason = wfMsgForContent( 'abusefilter-blockreason', $rule_desc );
				$block->mTimestamp = wfTimestampNow();
				$block->mAnonOnly = 0;
				$block->mCreateAccount = 1;
				$block->mExpiry = Block::parseExpiryInput( '1 week' );

				$block->insert();
				
				// Log it
				# Prepare log parameters
				$logParams = array();
				$logParams[] = 'indefinite';
				$logParams[] = 'nocreate, angry-autoblock';
	
				$log = new LogPage( 'block' );
				$log->addEntry( 'block', Title::makeTitle( NS_USER, $range ),
					wfMsgForContent( 'abusefilter-blockreason', $rule_desc ),
					$logParams, self::getFilterUser() );
				
				$display .= wfMsgExt( 'abusefilter-blocked-display', 'parseinline',
					$rule_desc ) ."<br />\n";
				break;
			case 'degroup':
				global $wgUser;
				if (!$wgUser->isAnon()) {
					// Remove all groups from the user. Ouch.
					$groups = $wgUser->getGroups();

					foreach( $groups as $group ) {
						$wgUser->removeGroup( $group );
					}

					$display .= wfMsgExt( 'abusefilter-degrouped', 'parseinline',
						array($rule_desc) ) ."<br />\n";

					// Don't log it if there aren't any groups being removed!
					if (!count($groups)) {
						break;
					}
					
					// Log it.
					$log = new LogPage( 'rights' );

					$log->addEntry( 'rights',
						$wgUser->getUserPage(),
						wfMsgForContent( 'abusefilter-degroupreason', $rule_desc ),
						array(
							implode( ', ', $groups ),
							wfMsgForContent( 'rightsnone' )
						)
					, self::getFilterUser() );
				}

				break;
			case 'blockautopromote':
				global $wgUser, $wgMemc;
				if (!$wgUser->isAnon()) {
					$blockPeriod = (int)mt_rand( 3*86400, 7*86400 ); // Block for 3-7 days.
					$wgMemc->set( self::autoPromoteBlockKey( $wgUser ), true, $blockPeriod );

					$display .= wfMsgExt( 'abusefilter-autopromote-blocked', 'parseinline',
						array($rule_desc) ) ."<br />\n";
				}
				break;

			case 'flag':
				// Do nothing. Here for completeness.
				break;

			case 'tag':
				// Mark with a tag on recentchanges.
				global $wgUser;

				$actionID = implode( '-', array(
						$title->getPrefixedText(), $wgUser->getName(), $vars['ACTION']
					) );

				AbuseFilter::$tagsToSet[$actionID] = $parameters;
				break;
			default:
				wfDebugLog( 'AbuseFilter', "Unrecognised action $action" );
		}
		
		return $display;
	}
	
	public static function isThrottled( $throttleId, $types, $title, $rateCount, $ratePeriod ) {
		global $wgMemc;
		
		$key = self::throttleKey( $throttleId, $types, $title );
		$count = intval( $wgMemc->get( $key ) );

		wfDebugLog( 'AbuseFilter', "Got value $count for throttle key $key\n" );
		
		if ($count > 0) {
			$wgMemc->incr( $key );
			$count++;
			wfDebugLog( 'AbuseFilter', "Incremented throttle key $key" );
		} else {
			wfDebugLog( 'AbuseFilter', "Added throttle key $key with value 1" );
			$wgMemc->add( $key, 1, $ratePeriod );
			$count = 1;
		}

		if ($count > $rateCount) {
			wfDebugLog( 'AbuseFilter', "Throttle $key hit value $count -- maximum is $rateCount." );
			return true; // THROTTLED
		}

		wfDebugLog( 'AbuseFilter', "Throttle $key not hit!" );
		
		return false; // NOT THROTTLED
	}
	
	public static function throttleIdentifier( $type, $title ) {
		global $wgUser;
		
		switch ($type) {
			case 'ip':
				$identifier = wfGetIp();
				break;
			case 'user':
				$identifier = $wgUser->getId();
				break;
			case 'range':
				$identifier = substr(IP::toHex(wfGetIp()),0,4);
				break;
			case 'creationdate':
				$reg = $wgUser->getRegistration();
				$identifier = $reg - ($reg % 86400);
				break;
			case 'editcount':
				// Hack for detecting different single-purpose accounts.
				$identifier = $wgUser->getEditCount();
				break;
			case 'site':
				return 1;
				break;
			case 'page':
				return $title->getPrefixedText();
				break;
		}
		
		return $identifier;
	}
	
	public static function throttleKey( $throttleId, $type, $title ) {
		$identifier = '';

		$types = explode(',', $type);
		
		$identifiers = array();
		
		foreach( $types as $subtype ) {
			$identifiers[] = self::throttleIdentifier( $subtype, $title );
		}
		
		$identifier = implode( ':', $identifiers );
	
		return wfMemcKey( 'abusefilter', 'throttle', $throttleId, $type, $identifier );
	}
	
	public static function autoPromoteBlockKey( $user ) {
		return wfMemcKey( 'abusefilter', 'block-autopromote', $user->getId() );
	}
	
	public static function recordStats( $filters ) {
		global $wgAbuseFilterConditionLimit,$wgMemc;
		
		$blocking_filters = array_keys( array_filter( $filters ) );

		// Figure out if we've triggered overflows and blocks.
		$overflow_triggered = (self::$condCount > $wgAbuseFilterConditionLimit);
		$filter_triggered = count( $blocking_filters ) > 0;

		// Store some keys...
		$overflow_key = self::filterLimitReachedKey();
		$total_key = self::filterUsedKey();
		
		$total = $wgMemc->get( $total_key );

		$storage_period = self::$statsStoragePeriod;
		
		if (!$total || $total > 1000) {
			// This is for if the total doesn't exist, or has gone past 1000.
			//  Recreate all the keys at the same time, so they expire together.
			$wgMemc->set( $total_key, 0, $storage_period );
			$wgMemc->set( $overflow_key, 0, $storage_period );

			foreach( $filters as $filter => $matched ) {
				$wgMemc->set( self::filterMatchesKey( $filter ), 0, $storage_period );
			}
			$wgMemc->set( self::filterMatchesKey(), 0, $storage_period );
		}

		// Increment total
		$wgMemc->incr( $total_key );

		// Increment overflow counter, if our condition limit overflowed
		if ($overflow_triggered) {
			$wgMemc->incr( $overflow_key );
		}
	}

	public static function checkEmergencyDisable( $filters, $total ) {
		global $wgAbuseFilterEmergencyDisableThreshold, $wgAbuseFilterEmergencyDisableCount,
			$wgAbuseFilterEmergencyDisableAge, $wgMemc;

		foreach( $filters as $filter => $matched ) {
			if ($matched) {
				// Increment counter
				$matchCount = $wgMemc->get( self::filterMatchesKey( $filter ) );

				// Handle missing keys...
				if (!$matchCount) {
					$wgMemc->set( self::filterMatchesKey( $filter ), 1, self::$statsStoragePeriod );
				} else {
					$wgMemc->incr( self::filterMatchesKey( $filter ) );
				}
				$matchCount++;
			
				// Figure out if the filter is subject to being deleted.
				$filter_age = wfTimestamp( TS_UNIX, self::$filters[$filter]->af_timestamp );
				$throttle_exempt_time = $filter_age + $wgAbuseFilterEmergencyDisableAge;

				if ($total && $throttle_exempt_time > time()
					&& $matchCount > $wgAbuseFilterEmergencyDisableCount
					&& ($matchCount / $total) > $wgAbuseFilterEmergencyDisableThreshold)
				{
					// More than $wgAbuseFilterEmergencyDisableCount matches,
					// constituting more than $wgAbuseFilterEmergencyDisableThreshold
					// (a fraction) of last few edits. Disable it.
					$dbw = wfGetDB( DB_MASTER );
					$dbw->update( 'abuse_filter', array( 'af_throttled' => 1 ),
						array( 'af_id' => $filter ), __METHOD__ );
				}
			}
		}
	}
	
	public static function filterLimitReachedKey() {
		return wfMemcKey( 'abusefilter', 'stats', 'overflow' );
	}
	
	public static function filterUsedKey() {
		return wfMemcKey( 'abusefilter', 'stats', 'total' );
	}
	
	public static function filterMatchesKey( $filter = null ) {
		return wfMemcKey( 'abusefilter', 'stats', 'matches', $filter );
	}
	
	public static function getFilterUser() {
		wfLoadExtensionMessages( 'AbuseFilter' );
		
		$user = User::newFromName( wfMsgForContent( 'abusefilter-blocker' ) );
		$user->load();
		if ($user->getId() && $user->mPassword == '') {
			// Already set up.
			return $user;
		}
		
		// Not set up. Create it.
		
		if (!$user->getId()) {
			print "Trying to create account -- user id is " . $user->getId();
			$user->addToDatabase();
			$user->saveSettings();
			# Increment site_stats.ss_users
			$ssu = new SiteStatsUpdate( 0, 0, 0, 0, 1 );
			$ssu->doUpdate();
		} else {
			// Take over the account
			$user->setPassword( null );
			$user->setEmail( null );
			$user->saveSettings();
		}
		
		# Promote user so it doesn't look too crazy.
		$user->addGroup( 'sysop' );
		
		return $user;
	}

	static function buildEditBox( $rules, $textName = 'wpFilterRules', $addResultDiv = true ) {
		global $wgOut;

		$rules = Xml::textarea( $textName, ( isset( $rules ) ? $rules."\n" : "\n" ) );

		$dropDown = self::$builderValues;

		// Generate builder drop-down
		$builder = '';

		$builder .= Xml::option( wfMsg( "abusefilter-edit-builder-select") );

		foreach( $dropDown as $group => $values ) {
			$builder .=
				Xml::openElement(
					'optgroup',
					array( 'label' => wfMsg( "abusefilter-edit-builder-group-$group" ) )
				) . "\n";

			foreach( $values as $content => $name ) {
				$builder .=
					Xml::option(
						wfMsg( "abusefilter-edit-builder-$group-$name" ),
						$content
					) . "\n";
			}

			$builder .= Xml::closeElement( 'optgroup' ) . "\n";
		}

		$rules .= 
			Xml::tags( 
				'select', 
				array( 'id' => 'wpFilterBuilder', 'onchange' => 'addText();' ), 
				$builder 
			) . ' ';

		// Add syntax checking
		$rules .= Xml::element( 'input', 
			array( 
				'type' => 'button', 
				'onclick' => 'doSyntaxCheck()', 
				'value' => wfMsg( 'abusefilter-edit-check' ), 
				'id' => 'mw-abusefilter-syntaxcheck' 
			) );

		if ($addResultDiv)
			$rules .= Xml::element( 'div', 
				array( 'id' => 'mw-abusefilter-syntaxresult', 'style' => 'display: none;' ), 
				'&nbsp;' );

		// Add script
		$editScript = file_get_contents(dirname(__FILE__)."/edit.js");
		$editScript = "var wgFilterBoxName = ".Xml::encodeJSVar( $textName ).";\n$editScript";
		$wgOut->addInlineScript( $editScript );

		return $rules;
	}

	/** Each version is expected to be an array( $row, $actions )
	    Returns an array of fields that are different.*/
	static function compareVersions( $version_1, $version_2 ) {
		$compareFields = array( 
			'af_public_comments', 
			'af_pattern', 
			'af_comments', 
			'af_deleted', 
			'af_enabled', 
			'af_hidden' 
		);
		$differences = array();

		list($row1, $actions1) = $version_1;
		list($row2, $actions2) = $version_2;

		foreach( $compareFields as $field ) {
			if ($row1->$field != $row2->$field) {
				$differences[] = $field;
			}
		}

		global $wgAbuseFilterAvailableActions;
		foreach( $wgAbuseFilterAvailableActions as $action ) {
			if ( !isset($actions1[$action]) && !isset( $actions2[$action] ) ) {
				// They're both unset
			} elseif ( isset($actions1[$action]) && isset( $actions2[$action] ) ) {
				// They're both set.
				if ( array_diff( $actions1[$action]['parameters'], 
					$actions2[$action]['parameters'] ) ) 
				{
					// Different parameters
					$differences[] = 'actions';
				}
			} else {
				// One's unset, one's set.
				$differences[] = 'actions';
			}
		}

		return array_unique( $differences );
	}

	static function translateFromHistory( $row ) {
		## Translate into an abuse_filter row with some black magic. 
		## This is ever so slightly evil!
		$af_row = new StdClass;

		foreach ( self::$history_mappings as $af_col => $afh_col ) {
			$af_row->$af_col = $row->$afh_col;
		}

		## Process flags

		$af_row->af_deleted = 0;
		$af_row->af_hidden = 0;
		$af_row->af_enabled = 0;

		$flags = explode(',', $row->afh_flags );
		foreach( $flags as $flag ) {
			$col_name = "af_$flag";
			$af_row->$col_name = 1;
		}

		## Process actions
		$actions_raw = unserialize($row->afh_actions);
		$actions_output = array();

		foreach( $actions_raw as $action => $parameters ) {
			$actions_output[$action] = array( 'action' => $action, 'parameters' => $parameters );
		}

		return array( $af_row, $actions_output );
	}

	static function getActionDisplay( $action ) {
		$display = wfMsg( "abusefilter-action-$action" );
		$display = wfEmptyMsg( "abusefilter-action-$action", $display ) ? $action : $display;
		return $display;
	}

	public static function getVarsFromRCRow( $row ) {
		if ($row->rc_this_oldid) {
			// It's an edit.
			return self::getEditVarsFromRCRow( $row );
		} elseif ( $row->rc_log_type == 'move' ) {
			return self::getMoveVarsFromRCRow( $row );
		} elseif ( $row->rc_log_type == 'newusers' ) {
			return self::getCreateVarsFromRCRow( $row );
		}
	}

	public static function getCreateVarsFromRCRow( $row ) {
		$vars = array('ACTION' => 'createaccount');
		$vars['USER_NAME'] = $vars['ACCOUNTNAME'] = 
			Title::makeTitle( $row->rc_namespace, $row->rc_title )->getText();
		return $vars;
	}

	public static function getEditVarsFromRCRow( $row ) {
		$vars = array();
		$title = Title::makeTitle( $row->rc_namespace, $row->rc_title );
		
		$vars = array_merge( $vars, self::generateUserVars( User::newFromId( $row->rc_user ) ) );
		$vars = array_merge( $vars, self::generateTitleVars( $title, 'ARTICLE' ) );
		$vars['ACTION'] = 'edit';
		$vars['SUMMARY'] = $row->rc_comment;

		$newRev = Revision::newFromId( $row->rc_this_oldid );
		$new_text = $newRev->getText();

		if ($row->rc_last_oldid) {
			$oldRev = Revision::newFromId( $row->rc_last_oldid );
			$old_text = $oldRev->getText();
		} else {
			$old_text = '';
		}

		$vars = array_merge( $vars, self::getEditVars( 
			$title, $old_text, $new_text, null, $row->rc_this_oldid, $row->rc_last_oldid ) );

		return $vars;
	}

	public static function getMoveVarsFromRCRow( $row ) {
		$vars = array();
		
		$user = User::newFromId( $row->rc_user );
		$oldTitle = Title::makeTitle( $row->rc_namespace, $row->rc_title );
		$newTitle = Title::newFromText( trim($row->rc_params) );
		
		$vars = array_merge( $vars, AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $oldTitle, 'MOVED_FROM' ),
			AbuseFilter::generateTitleVars( $newTitle, 'MOVED_TO' ) );

		$vars['SUMMARY'] = $row->rc_comment;
		$vars['ACTION'] = 'move';

		return $vars;
	}

	public static function getEditVars( $title, $old_text, $new_text, $oldLinks = null, 
		$revid=null, $oldid=null ) 
	{
		$vars = array();
		$article = new Article( $title );

		$vars['EDIT_DELTA'] = strlen($new_text) - strlen($old_text);
		$vars['OLD_SIZE'] = strlen($old_text);
		$diff = wfDiff( $old_text, $new_text );
		$diff = trim( str_replace( '\No newline at end of file', '', $diff ) );
		$vars['EDIT_DIFF'] = $diff;
		$vars['NEW_SIZE'] = strlen($new_text);

		$vars['OLD_WIKITEXT'] = $old_text;
		$vars['NEW_WIKITEXT'] = $new_text;

		// Some more specific/useful details about the changes.
		$diff_lines = explode( "\n", $diff );
		$added_lines = array();
		$removed_lines = array();
		foreach( $diff_lines as $line ) {
			if (strpos( $line, '-' )===0) {
				$removed_lines[] = substr($line,1);
			} elseif (strpos( $line, '+' )===0) {
				$added_lines[] = substr($line,1);
			}
		}
		$vars['ADDED_LINES'] = implode( "\n", $added_lines );
		$vars['REMOVED_LINES'] = implode( "\n", $removed_lines );

		if ($oldLinks === null && $oldid) {
			$oldInfo = $article->prepareTextForEdit( $old_text, $oldid );
			$oldLinks = $oldInfo->output->getExternalLinks();
		} elseif ($oldLinks === null) {
			$oldLinks = array();
		}

		// Added links...
		$editInfo = $article->prepareTextForEdit( $new_text, $revid );
		$newLinks = array_keys( $editInfo->output->getExternalLinks() );
		$vars['ALL_LINKS'] = implode( "\n", $newLinks );
		$vars['ADDED_LINKS'] = implode( "\n", 
			array_diff( $newLinks, array_intersect( $newLinks, $oldLinks ) ) );
		$vars['REMOVED_LINKS'] = implode( "\n", 
			array_diff( $oldLinks, array_intersect( $newLinks, $oldLinks ) ) );

		// Pull other useful stuff from $editInfo.
		$newHTML = $editInfo->output->getText();

		// Kill the PP limit comments. Ideally we'd just remove these by not setting the 
		// parser option, but then we can't share a parse operation with the edit, which is bad.
		$newHTML = preg_replace( '/<!--\s*NewPP limit report[^>]*-->\s*$/si', '', $newHTML );
		$vars['NEW_HTML'] = $newHTML;
		
		$newText = $vars['NEW_TEXT'] = preg_replace( '/<[^>]+>/', '', $newHTML );

		return $vars;
	}

	public static function buildVarDumpTable( $vars ) {
		$output = '';

		// I don't want to change the names of the pre-existing messages
		// describing the variables, nor do I want to rewrite them, so I'm just
		// mapping the variable names to builder messages with a pre-existing array.
		$variableMessageMappings = self::$builderValues['vars'];
		
		$output .= 
			Xml::openElement( 'table', array( 'class' => 'mw-abuselog-details' ) ) . 
			Xml::openElement( 'tbody' ) . 
			"\n";

		$header = 
			Xml::element( 'th', null, wfMsg( 'abusefilter-log-details-var' ) ) . 
			Xml::element( 'th', null, wfMsg( 'abusefilter-log-details-val' ) );
		$output .= Xml::tags( 'tr', null, $header ) . "\n";

		// Now, build the body of the table.
		foreach( $vars as $key => $value ) {
			$key = strtolower($key);
			
			if ( !empty($variableMessageMappings[$key]) ) {
				$mapping = $variableMessageMappings[$key];
				$keyDisplay = wfMsgExt( "abusefilter-edit-builder-vars-$mapping", 'parseinline' ) .
				' (' . Xml::element( 'tt', null, $key ) . ')';
			} else {
				$keyDisplay = Xml::element( 'tt', null, $key );
			}

			if( is_null( $value ) )
				$value = '';
			$value = Xml::element( 'div', array( 'class' => 'mw-abuselog-var-value' ), $value );

			$trow = 
				Xml::tags( 'td', array( 'class' => 'mw-abuselog-var' ), $keyDisplay ) . 
				Xml::tags( 'td', array( 'class' => 'mw-abuselog-var-value' ), $value );
			$output .= 
				Xml::tags( 'tr', 
					array( 'class' => "mw-abuselog-details-$key mw-abuselog-value" ), $trow
				) . "\n";
		}

		$output .= Xml::closeElement( 'tbody' ) . Xml::closeElement( 'table' );
		return $output;
	}
}
