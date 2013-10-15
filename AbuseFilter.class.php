<?php

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
		'af_id' => 'afh_filter',
		'af_group' => 'afh_group',
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
			'contains' => 'contains',
			'like' => 'like',
			'""' => 'stringlit',
			'rlike' => 'rlike',
			'irlike' => 'irlike',
			'cond ? iftrue : iffalse' => 'tern',
			'if cond then iftrue elseiffalse end' => 'cond',
		),
		'funcs' => array(
			'length(string)' => 'length',
			'lcase(string)' => 'lcase',
			'ucase(string)' => 'ucase',
			'ccnorm(string)' => 'ccnorm',
			'rmdoubles(string)' => 'rmdoubles',
			'specialratio(string)' => 'specialratio',
			'norm(string)' => 'norm',
			'count(needle,haystack)' => 'count',
			'rcount(needle,haystack)' => 'rcount',
			'rmwhitespace(text)' => 'rmwhitespace',
			'rmspecials(text)' => 'rmspecials',
			'ip_in_range(ip, range)' => 'ip_in_range',
			'contains_any(haystack,needle1,needle2,needle3)' => 'contains-any',
			'substr(subject, offset, length)' => 'substr',
			'strpos(haystack, needle)' => 'strpos',
			'str_replace(subject, search, replace)' => 'str_replace',
			'rescape(string)' => 'rescape',
			'set_var(var,value)' => 'set_var',
		),
		'vars' => array(
			'timestamp' => 'timestamp',
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
#			'article_views' => 'article-views', # May not be enabled, defined in getBuilderValues()
			'moved_from_articleid' => 'movedfrom-id',
			'moved_from_namespace' => 'movedfrom-ns',
			'moved_from_text' => 'movedfrom-text',
			'moved_from_prefixedtext' => 'movedfrom-prefixedtext',
			'moved_to_articleid' => 'movedto-id',
			'moved_to_namespace' => 'movedto-ns',
			'moved_to_text' => 'movedto-text',
			'moved_to_prefixedtext' => 'movedto-prefixedtext',
			'user_editcount' => 'user-editcount',
			'user_age' => 'user-age',
			'user_name' => 'user-name',
			'user_groups' => 'user-groups',
			'user_blocked' => 'user-blocked',
			'user_emailconfirm' => 'user-emailconfirm',
			'old_wikitext' => 'old-text',
			'new_wikitext' => 'new-text',
			'added_links' => 'added-links',
			'removed_links' => 'removed-links',
			'all_links' => 'all-links',
			'new_pst' => 'new-pst',
			'new_text' => 'new-text-stripped',
			'new_html' => 'new-html',
			'article_restrictions_edit' => 'restrictions-edit',
			'article_restrictions_move' => 'restrictions-move',
			'article_restrictions_create' => 'restrictions-create',
			'article_restrictions_upload' => 'restrictions-upload',
			'article_recent_contributors' => 'recent-contributors',
#			'old_text' => 'old-text-stripped', # Disabled, performance
#			'old_html' => 'old-html', # Disabled, performance
			'old_links' => 'old-links',
			'minor_edit' => 'minor-edit',
			'file_sha1' => 'file-sha1',
		),
	);

	public static $editboxName = null;

	/**
	 * @param $context IContextSource
	 * @param $pageType
	 */
	public static function addNavigationLinks( IContextSource $context, $pageType ) {
		$linkDefs = array(
			'home' => 'Special:AbuseFilter',
			'recentchanges' => 'Special:AbuseFilter/history',
			'examine' => 'Special:AbuseFilter/examine',
			'log' => 'Special:AbuseLog',
		);

		if ( $context->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			$linkDefs = array_merge( $linkDefs, array(
					'test' => 'Special:AbuseFilter/test',
					'tools' => 'Special:AbuseFilter/tools',
					'import' => 'Special:AbuseFilter/import',
				) );
		}

		// Save some translator work
		$msgOverrides = array(
			'recentchanges' => 'abusefilter-filter-log',
		);

		$links = array();

		foreach ( $linkDefs as $name => $page ) {
			// Give grep a chance to find the usages:
			// abusefilter-topnav-home, abusefilter-topnav-test, abusefilter-topnav-examine
			// abusefilter-topnav-log, abusefilter-topnav-tools, abusefilter-topnav-import
			$msgName = "abusefilter-topnav-$name";

			if ( isset( $msgOverrides[$name] ) ) {
				$msgName = $msgOverrides[$name];
			}

			$msg = wfMessage( $msgName )->parse();
			$title = Title::newFromText( $page );

			if ( $name == $pageType ) {
				$links[] = Xml::tags( 'strong', null, $msg );
			} else {
				$links[] = Linker::link( $title, $msg );
			}
		}

		$linkStr = wfMessage( 'parentheses', $context->getLanguage()->pipeList( $links ) )->text();
		$linkStr = wfMessage( 'abusefilter-topnav' )->parse() . " $linkStr";

		$linkStr = Xml::tags( 'div', array( 'class' => 'mw-abusefilter-navigation' ), $linkStr );

		$context->getOutput()->setSubtitle( $linkStr );
	}

	/**
	 * @static
	 * @param  $user User
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateUserVars( $user ) {
		$vars = new AbuseFilterVariableHolder;

		$vars->setLazyLoadVar(
			'user_editcount',
			'simple-user-accessor',
			array( 'user' => $user, 'method' => 'getEditCount' )
		);

		$vars->setVar( 'user_name', $user->getName() );

		$vars->setLazyLoadVar(
			'user_emailconfirm',
			'simple-user-accessor',
			array( 'user' => $user, 'method' => 'getEmailAuthenticationTimestamp' )
		);

		$vars->setLazyLoadVar(
			'user_age',
			'user-age',
			array( 'user' => $user, 'asof' => wfTimestampNow() )
		);

		$vars->setLazyLoadVar(
			'user_groups',
			'simple-user-accessor',
			array( 'user' => $user, 'method' => 'getEffectiveGroups' )
		);

		$vars->setLazyLoadVar(
			'user_blocked',
			'simple-user-accessor',
			array( 'user' => $user, 'method' => 'isBlocked' )
		);

		wfRunHooks( 'AbuseFilter-generateUserVars', array( $vars, $user ) );

		return $vars;
	}

	/**
	 * @return array
	 */
	public static function getBuilderValues() {
		static $realValues = null;

		if ( $realValues ) {
			return $realValues;
		}

		$realValues = self::$builderValues;
		global $wgDisableCounters;
		if ( !$wgDisableCounters ) {
			$realValues['vars']['article_views'] = 'article-views';
		}
		wfRunHooks( 'AbuseFilter-builder', array( &$realValues ) );

		return $realValues;
	}

	/**
	 * @param $filter
	 * @return bool
	 */
	public static function filterHidden( $filter ) {
		$globalIndex = self::decodeGlobalName( $filter );
		if ( $globalIndex ) {
			global $wgAbuseFilterCentralDB;
			if ( !$wgAbuseFilterCentralDB ) {
				return false;
			}
			$dbr = wfGetDB( DB_SLAVE, array(), $wgAbuseFilterCentralDB );
			$filter = $globalIndex;
		} else {
			$dbr = wfGetDB( DB_SLAVE );
		}
		$hidden = $dbr->selectField(
			'abuse_filter',
			'af_hidden',
			array( 'af_id' => $filter ),
			__METHOD__
		);
		return $hidden ? true : false;
	}

	/**
	 * @param $val int
	 * @throws MWException
	 */
	public static function triggerLimiter( $val = 1 ) {
		self::$condCount += $val;

		global $wgAbuseFilterConditionLimit;

		if ( self::$condLimitEnabled && self::$condCount > $wgAbuseFilterConditionLimit ) {
			throw new MWException( 'Condition limit reached.' );
		}
	}

	public static function disableConditionLimit() {
		// For use in batch scripts and the like
		self::$condLimitEnabled = false;
	}

	/**
	 * @param $title Title
	 * @param $prefix
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateTitleVars( $title, $prefix ) {
		$vars = new AbuseFilterVariableHolder;

		if ( !$title ) {
			return new AbuseFilterVariableHolder;
		}

		$vars->setVar( $prefix . '_ARTICLEID', $title->getArticleID() );
		$vars->setVar( $prefix . '_NAMESPACE', $title->getNamespace() );
		$vars->setVar( $prefix . '_TEXT', $title->getText() );
		$vars->setVar( $prefix . '_PREFIXEDTEXT', $title->getPrefixedText() );
		global $wgDisableCounters;
		if ( !$wgDisableCounters && !$title->isSpecialPage() ) {
			$vars->setVar( $prefix . '_VIEWS', WikiPage::factory( $title )->getCount() );
		}

		// Use restrictions.
		global $wgRestrictionTypes;
		foreach ( $wgRestrictionTypes as $action ) {
			$vars->setLazyLoadVar( "{$prefix}_restrictions_$action", 'get-page-restrictions',
				array( 'title' => $title->getText(),
						'namespace' => $title->getNamespace(),
						'action' => $action
					)
				);
		}

		$vars->setLazyLoadVar( "{$prefix}_recent_contributors", 'load-recent-authors',
				array(
					'cutoff' => wfTimestampNow(),
					'title' => $title->getText(),
					'namespace' => $title->getNamespace()
				) );

		wfRunHooks( 'AbuseFilter-generateTitleVars', array( $vars, $title, $prefix ) );

		return $vars;
	}

	/**
	 * @param $filter
	 * @return mixed
	 */
	public static function checkSyntax( $filter ) {
		global $wgAbuseFilterParserClass;

		/**
		 * @var $parser AbuseFilterParser
		 */
		$parser = new $wgAbuseFilterParserClass;

		return $parser->checkSyntax( $filter );
	}

	/**
	 * @param $expr
	 * @param array $vars
	 * @return string
	 */
	public static function evaluateExpression( $expr, $vars = array() ) {
		global $wgAbuseFilterParserClass;

		if ( self::checkSyntax( $expr ) !== true ) {
			return 'BADSYNTAX';
		}

		/**
		 * @var $parser AbuseFilterParser
		 */
		$parser = new $wgAbuseFilterParserClass( $vars );

		return $parser->evaluateExpression( $expr );
	}

	/**
	 * @param $conds
	 * @param $vars
	 * @param $ignoreError bool
	 * @param $keepVars string
	 * @return bool
	 * @throws Exception
	 */
	public static function checkConditions(
		$conds, $vars, $ignoreError = true, $keepVars = 'resetvars'
	) {
		global $wgAbuseFilterParserClass;

		static $parser;

		wfProfileIn( __METHOD__ );

		if ( is_null( $parser ) || $keepVars == 'resetvars' ) {
			/**
			 * @var $parser AbuseFilterParser
			 */
			$parser = new $wgAbuseFilterParserClass( $vars );
		}

		try {
			$result = $parser->parse( $conds, self::$condCount );
		} catch ( Exception $excep ) {
			// Sigh.
			$result = false;

			wfDebugLog( 'AbuseFilter', 'AbuseFilter parser error: ' . $excep->getMessage() . "\n" );

			if ( !$ignoreError ) {
				throw $excep;
			}
		}

		wfProfileOut( __METHOD__ );

		return $result;
	}

	/**
	 * Returns an associative array of filters which were tripped
	 *
	 * @param $vars array
	 * @param $group string The filter group to check against.
	 *
	 * @return array
	 */
	public static function checkAllFilters( $vars, $group = 'default' ) {
		// Fetch from the database.
		wfProfileIn( __METHOD__ );

		$filter_matched = array();

		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			'abuse_filter',
			'*',
			array(
				'af_enabled' => 1,
				'af_deleted' => 0,
				'af_group' => $group,
			),
			__METHOD__
		);

		foreach( $res as $row ) {
			$filter_matched[$row->af_id] = self::checkFilter( $row, $vars, true );
		}

		global $wgAbuseFilterCentralDB, $wgAbuseFilterIsCentral, $wgMemc;

		if ( $wgAbuseFilterCentralDB && !$wgAbuseFilterIsCentral ) {
			// Global filters
			$globalRulesKey = self::getGlobalRulesKey( $group );
			$memcacheRules = $wgMemc->get( $globalRulesKey );

			if ( $memcacheRules ) {
				$res =  $memcacheRules;
			} else {
				$fdb = wfGetDB( DB_SLAVE, array(), $wgAbuseFilterCentralDB );
				$res = $fdb->select(
					'abuse_filter',
					'*',
					array(
						'af_enabled' => 1,
						'af_deleted' => 0,
						'af_global' => 1,
						'af_group' => $group,
					),
					__METHOD__
				);
				$memcacheRules = array();
				foreach ( $res as $row ) {
					$memcacheRules[] = $row;
				}
				$wgMemc->set( $globalRulesKey, $memcacheRules );
			}

			foreach( $res as $row ) {
				$filter_matched['global-' . $row->af_id] =
					self::checkFilter( $row, $vars, true, 'global-' );
			}
		}

		// Update statistics, and disable filters which are over-blocking.
		self::recordStats( $filter_matched, $group );

		wfProfileOut( __METHOD__ );

		return $filter_matched;
	}

	/**
	 * @static
	 * @param $row
	 * @param $vars
	 * @param $profile bool
	 * @param $prefix string
	 * @return bool
	 */
	public static function checkFilter( $row, $vars, $profile = false, $prefix = '' ) {
		$filterID = $prefix . $row->af_id;

		if ( $profile ) {
			$startConds = self::$condCount;
			$startTime = microtime( true );
		}

		// Store the row somewhere convenient
		self::$filters[$filterID] = $row;

		// Check conditions...
		$pattern = trim( $row->af_pattern );
		if ( self::checkConditions(
			$pattern,
			$vars,
			true /* ignore errors */,
			'keepvars'
		) ) {
			// Record match.
			$result = true;
		} else {
			// Record non-match.
			$result = false;
		}

		if ( $profile ) {
			$endTime = microtime( true );
			$endConds = self::$condCount;

			$timeTaken = $endTime - $startTime;
			$condsUsed = $endConds - $startConds;

			self::recordProfilingResult( $row->af_id, $timeTaken, $condsUsed );
		}

		return $result;
	}

	/**
	 * @param $filter
	 */
	public static function resetFilterProfile( $filter ) {
		global $wgMemc;
		$countKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'count' );
		$totalKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'total' );

		$wgMemc->delete( $countKey );
		$wgMemc->delete( $totalKey );
	}

	/**
	 * @param $filter
	 * @param $time
	 * @param $conds
	 */
	public static function recordProfilingResult( $filter, $time, $conds ) {
		global $wgMemc;

		$countKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'count' );
		$totalKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'total' );
		$totalCondKey = wfMemcKey( 'abusefilter', 'profile-conds', 'total' );

		$curCount = $wgMemc->get( $countKey );
		$curTotal = $wgMemc->get( $totalKey );
		$curTotalConds = $wgMemc->get( $totalCondKey );

		if ( $curCount ) {
			$wgMemc->set( $totalCondKey, $curTotalConds + $conds, 3600 );
			$wgMemc->set( $totalKey, $curTotal + $time, 3600 );
			$wgMemc->incr( $countKey );
		} else {
			$wgMemc->set( $countKey, 1, 3600 );
			$wgMemc->set( $totalKey, $time, 3600 );
			$wgMemc->set( $totalCondKey, $conds, 3600 );
		}
	}

	/**
	 * @param $filter
	 * @return array
	 */
	public static function getFilterProfile( $filter ) {
		global $wgMemc;

		$countKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'count' );
		$totalKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'total' );
		$totalCondKey = wfMemcKey( 'abusefilter', 'profile-conds', 'total' );

		$curCount = $wgMemc->get( $countKey );
		$curTotal = $wgMemc->get( $totalKey );
		$curTotalConds = $wgMemc->get( $totalCondKey );

		if ( !$curCount ) {
			return array( 0, 0 );
		}

		$timeProfile = ( $curTotal / $curCount ) * 1000; // 1000 ms in a sec
		$timeProfile = round( $timeProfile, 2 ); // Return in ms, rounded to 2dp

		$condProfile = ( $curTotalConds / $curCount );
		$condProfile = round( $condProfile, 0 );

		return array( $timeProfile, $condProfile );
	}

	/**
	 * Utility function to decode global-$index to $index. Returns false if not global
	 *
	 * @param $filter string
	 *
	 * @return string|bool
	 */
	public static function decodeGlobalName( $filter ) {
		if ( strpos( $filter, 'global-' ) == 0 ) {
			return substr( $filter, strlen( 'global-' ) );
		}

		return false;
	}

	/**
	 * @param $filters array
	 * @return array
	 */
	public static function getConsequencesForFilters( $filters ) {
		$globalFilters = array();
		$localFilters = array();

		foreach ( $filters as $filter ) {
			$globalIndex = self::decodeGlobalName( $filter );

			if ( $globalIndex ) {
				$globalFilters[] = $globalIndex;
			} else {
				$localFilters[] = $filter;
			}
		}

		global $wgAbuseFilterCentralDB;
		// Load local filter info
		$dbr = wfGetDB( DB_SLAVE );
		// Retrieve the consequences.
		$consequences = array();

		if ( count( $localFilters ) ) {
			$consequences = self::loadConsequencesFromDB( $dbr, $localFilters );
		}

		if ( count( $globalFilters ) ) {
			$fdb = wfGetDB( DB_SLAVE, array(), $wgAbuseFilterCentralDB );
			$consequences = $consequences + self::loadConsequencesFromDB( $fdb, $globalFilters, 'global-' );
		}

		return $consequences;
	}

	/**
	 * @param $dbr DatabaseBase
	 * @param $filters array
	 * @param $prefix string
	 * @return array
	 */
	public static function loadConsequencesFromDB( $dbr, $filters, $prefix = '' ) {
		$actionsByFilter = array();
		foreach ( $filters as $filter ) {
			$actionsByFilter[$prefix . $filter] = array();
		}

		$res = $dbr->select(
			array( 'abuse_filter_action', 'abuse_filter' ),
			'*',
			array( 'af_id' => $filters ),
			__METHOD__,
			array(),
			array( 'abuse_filter_action' => array( 'LEFT JOIN', 'afa_filter=af_id' ) )
		);

		// Categorise consequences by filter.
		global $wgAbuseFilterRestrictedActions;
		foreach( $res as $row ) {
			if ( $row->af_throttled
				&& in_array( $row->afa_consequence, $wgAbuseFilterRestrictedActions ) )
			{
				# Don't do the action
			} elseif ( $row->afa_filter != $row->af_id ) {
				// We probably got a NULL, as it's a LEFT JOIN.
				// Don't add it.
			} else {
				$actionsByFilter[$prefix . $row->afa_filter][$row->afa_consequence] = array(
					'action' => $row->afa_consequence,
					'parameters' => explode( "\n", $row->afa_parameters )
				);
			}
		}

		return $actionsByFilter;
	}

	/**
	 * Executes a list of actions.
	 *
	 * @param $filters array
	 * @param $title Title
	 * @param $vars array
	 * @return Status returns the operation's status. $status->isOK() will return true if
	 *         there were no actions taken, false otherwise. $status->getValue() will return
	 *         an array listing the actions taken. $status-getErrors(), etc, will provide
	 *         the errors and warnings to be shown to the user to explain the actions.
	 */
	public static function executeFilterActions( $filters, $title, $vars ) {
		wfProfileIn( __METHOD__ );

		$actionsByFilter = self::getConsequencesForFilters( $filters );
		$actionsTaken = array_fill_keys( $filters, array() );

		$messages = array();

		global $wgOut, $wgAbuseFilterDisallowGlobalLocalBlocks, $wgAbuseFilterRestrictedActions;
		foreach ( $actionsByFilter as $filter => $actions ) {
			// Special-case handling for warnings.
			$parsed_public_comments = $wgOut->parseInline(
				self::$filters[$filter]->af_public_comments );

			$global_filter = ( preg_match( '/^global-/', $filter ) == 1);

			if ( !empty( $actions['throttle'] ) ) {
				$parameters = $actions['throttle']['parameters'];
				$throttleId = array_shift( $parameters );
				list( $rateCount, $ratePeriod ) = explode( ',', array_shift( $parameters ) );

				$hitThrottle = false;

				// The rest are throttle-types.
				foreach ( $parameters as $throttleType ) {
					$hitThrottle = $hitThrottle || self::isThrottled(
						$throttleId, $throttleType, $title, $rateCount, $ratePeriod, $global_filter );
				}

				unset( $actions['throttle'] );
				if ( !$hitThrottle ) {
					$actionsTaken[$filter][] = 'throttle';
					continue;
				}
			}

			if ( $wgAbuseFilterDisallowGlobalLocalBlocks && $global_filter ) {
				foreach ( $wgAbuseFilterRestrictedActions as $blockingAction ) {
					unset( $actions[$blockingAction] );
				}
			}

			if ( !empty( $actions['warn'] ) ) {
				$parameters = $actions['warn']['parameters'];
				$warnKey = 'abusefilter-warned-' . md5($title->getPrefixedText()) . '-' . $filter;

				// Make sure the session is started prior to using it
				if ( session_id() === '' ) {
					wfSetupSession();
				}

				if ( !isset( $_SESSION[$warnKey] ) || !$_SESSION[$warnKey] ) {
					$_SESSION[$warnKey] = true;

					// Threaten them a little bit
					if ( !empty( $parameters[0] ) && strlen( $parameters[0] ) ) {
						$msg = $parameters[0];
					} else {
						$msg = 'abusefilter-warning';
					}
					$messages[] = array( $msg, $parsed_public_comments, $filter );

					$actionsTaken[$filter][] = 'warn';

					continue; // Don't do anything else.
				} else {
					// We already warned them
					$_SESSION[$warnKey] = false;
				}

				unset( $actions['warn'] );
			}

			// prevent double warnings
			if ( count( array_intersect( array_keys( $actions ), $wgAbuseFilterRestrictedActions ) ) > 0 &&
					!empty( $actions['disallow'] ) ) {
				unset( $actions['disallow'] );
			}

			// Do the rest of the actions
			foreach ( $actions as $action => $info ) {
				$newMsg = self::takeConsequenceAction(
					$action, $info['parameters'], $title, $vars,
					self::$filters[$filter]->af_public_comments
				);

				if ( $newMsg !== null ) {
					$messages[] = $newMsg;
				}
				$actionsTaken[$filter][] = $action;
			}
		}

		$status = self::buildStatus( $actionsTaken, $messages );

		wfProfileOut( __METHOD__ );
		return $status;
	}

	/**
	 * Constructs a Status object as returned by executeFilterActions() from the list of
	 * actions taken and the corresponding list of messages.
	 *
	 * @param array[] $actionsTaken associative array mapping each filter to the list if
	 *                actions taken because of that filter.
	 * @param array[] $messages a list if arrays, where each array contains a message key
	 *                followed by any message parameters.
	 *
	 * @todo: change this to accept Message objects. This is only possible from 1.21 onward,
	 *        because before that, Status::fatal does not accept Message objects.
	 *
	 * @return Status
	 */
	protected static function buildStatus( array $actionsTaken, array $messages ) {
		$status = Status::newGood( $actionsTaken );

		foreach ( $messages as $msg ) {
			// Since MW 1.21, we could just pass Message objects, but in 1.20,
			// we still have to rely on arrays.
			call_user_func_array( array( $status, 'fatal' ), $msg );
		}

		return $status;
	}

	/**
	 * @param $vars AbuseFilterVariableHolder
	 * @param $title Title
	 * @param $group string
	 * @return Status
	 */
	public static function filterAction( $vars, $title, $group = 'default' ) {
		global $wgUser, $wgTitle, $wgRequest;

		wfProfileIn( __METHOD__ );

		$context = RequestContext::getMain();
		$oldContextTitle = $context->getTitle();

		$oldWgTitle = $wgTitle;
		if ( !$wgTitle ) {
			$wgTitle = SpecialPage::getTitleFor( 'AbuseFilter' );
		}

		// Add vars from extensions
		wfRunHooks( 'AbuseFilter-filterAction', array( &$vars, $title ) );

		// Set context
		$vars->setVar( 'context', 'filter' );
		$vars->setVar( 'timestamp', time() );

		$dbr = wfGetDB( DB_SLAVE );

		$filter_matched = self::checkAllFilters( $vars, $group );

		$matched_filters = array_keys( array_filter( $filter_matched ) );

		if ( count( $matched_filters ) == 0 ) {
			$status = Status::newGood();
		} else {
			wfProfileIn( __METHOD__ . '-block' );

			$status = self::executeFilterActions(
				$matched_filters, $title, $vars );

			$actions_taken = $status->value; // getValue() was introduced only in 1.20

			$action = $vars->getVar( 'ACTION' )->toString();

			// Create a template
			$log_template = array(
				'afl_user' => $wgUser->getId(),
				'afl_user_text' => $wgUser->getName(),
				'afl_timestamp' => $dbr->timestamp( wfTimestampNow() ),
				'afl_namespace' => $title->getNamespace(),
				'afl_title' => $title->getDBkey(),
				'afl_ip' => $wgRequest->getIP()
			);

			// Hack to avoid revealing IPs of people creating accounts
			if ( !$wgUser->getId() && ( $action == 'createaccount' || $action == 'autocreateaccount' ) ) {
				$log_template['afl_user_text'] = $vars->getVar( 'accountname' )->toString();
			}

			self::addLogEntries( $actions_taken, $log_template, $action, $vars, $group );

			wfProfileOut( __METHOD__ . '-block' );
		}

		// Bug 53498: If we screwed around with $wgTitle, reset it so the title
		// is correctly picked up from the request later. Do the same for the
		// main RequestContext, because that might have picked up the bogus
		// title from $wgTitle.
		if ( $wgTitle !== $oldWgTitle ) {
			$wgTitle = $oldWgTitle;
		}
		if ( $context->getTitle() !== $oldContextTitle ) {
			$context->setTitle( $oldContextTitle );
		}

		wfProfileOut( __METHOD__ );

		return $status;
	}

	/**
	 * @param $actions_taken
	 * @param $log_template
	 * @param $action
	 * @param $vars AbuseFilterVariableHolder
	 * @param string $group
	 * @return mixed
	 */
	public static function addLogEntries( $actions_taken, $log_template, $action, $vars, $group = 'default' ) {
		wfProfileIn( __METHOD__ );
		$dbw = wfGetDB( DB_MASTER );

		$central_log_template = array(
			'afl_wiki' => wfWikiID(),
		);

		$log_rows = array();
		$central_log_rows = array();
		$logged_local_filters = array();
		$logged_global_filters = array();

		foreach ( $actions_taken as $filter => $actions ) {
			$globalIndex = self::decodeGlobalName( $filter );
			$thisLog = $log_template;
			$thisLog['afl_filter'] = $filter;
			$thisLog['afl_action'] = $action;
			$thisLog['afl_actions'] = implode( ',', $actions );

			// Don't log if we were only throttling.
			if ( $thisLog['afl_actions'] != 'throttle' ) {
				$log_rows[] = $thisLog;

				if ( !$globalIndex ) {
					$logged_local_filters[] = $filter;
				}

				// Global logging
				if ( $globalIndex ) {
					$title = Title::makeTitle( $thisLog['afl_namespace'], $thisLog['afl_title'] );
					$centralLog = $thisLog + $central_log_template;
					$centralLog['afl_filter'] = $globalIndex;
					$centralLog['afl_title'] = $title->getPrefixedText();
					$centralLog['afl_namespace'] = 0;

					$central_log_rows[] = $centralLog;
					$logged_global_filters[] = $globalIndex;
				}
			}
		}

		if ( !count( $log_rows ) ) {
			wfProfileOut( __METHOD__ );
			return;
		}

		// Only store the var dump if we're actually going to add log rows.
		$var_dump = self::storeVarDump( $vars );
		$var_dump = "stored-text:$var_dump"; // To distinguish from stuff stored directly

		wfProfileIn( __METHOD__ . '-hitstats' );

		global $wgMemc;

		// Increment trigger counter
		$wgMemc->incr( self::filterMatchesKey() );

		$local_log_ids = array();
		global $wgAbuseFilterNotifications, $wgAbuseFilterNotificationsPrivate;
		foreach ( $log_rows as $data ) {
			$data['afl_var_dump'] = $var_dump;
			$data['afl_id'] = $dbw->nextSequenceValue( 'abuse_filter_log_afl_id_seq' );
			$dbw->insert( 'abuse_filter_log', $data, __METHOD__ );
			$local_log_ids[] = $dbw->insertId();
			if ( $data['afl_id'] === null ) {
				$data['afl_id'] = $dbw->insertId();
			}

			if ( $wgAbuseFilterNotifications !== false ) {
				$entry = new ManualLogEntry( 'abusefilter', 'hit' );
				// Construct a user object
				$user = new User();
				$user->setId( $data['afl_user'] );
				$user->setName( $data['afl_user_text'] );
				$entry->setPerformer( $user );
				// Set action target
				$entry->setTarget( Title::makeTitle( $data['afl_namespace'], $data['afl_title'] ) );
				// Additional info
				$entry->setParameters( array(
					'action'  => $data['afl_action'],
					'filter'  => $data['afl_filter'],
					'actions' => $data['afl_actions'],
					'log'     => $data['afl_id'],
				) );
				if ( self::filterHidden( $data['afl_filter'] ) && !$wgAbuseFilterNotificationsPrivate ) {
					continue;
				}
				$entry->publish( 0, $wgAbuseFilterNotifications );
			}
		}

		$method = __METHOD__;

		if ( count( $logged_local_filters ) ) {
			// Update hit-counter.
			$dbw->onTransactionPreCommitOrIdle(
				function() use ( $dbw, $logged_local_filters, $method ) {
					$dbw->update( 'abuse_filter',
						array( 'af_hit_count=af_hit_count+1' ),
						array( 'af_id' => $logged_local_filters ),
						$method
					);
				}
			);
		}

		$global_log_ids = array();

		// Global stuff
		if ( count( $logged_global_filters ) ) {
			$vars->computeDBVars();
			$global_var_dump = self::storeVarDump( $vars, true );
			$global_var_dump = "stored-text:$global_var_dump";
			foreach ( $central_log_rows as $index => $data ) {
				$central_log_rows[$index]['afl_var_dump'] = $global_var_dump;
			}

			global $wgAbuseFilterCentralDB;
			$fdb = wfGetDB( DB_MASTER, array(), $wgAbuseFilterCentralDB );

			foreach( $central_log_rows as $row ) {
				$fdb->insert( 'abuse_filter_log', $row, __METHOD__ );
				$global_log_ids[] = $dbw->insertId();
			}

			$fdb->onTransactionPreCommitOrIdle(
				function() use ( $fdb, $logged_global_filters, $method ) {
					$fdb->update( 'abuse_filter',
						array( 'af_hit_count=af_hit_count+1' ),
						array( 'af_id' => $logged_global_filters ),
						$method
					);
				}
			);
		}

		$vars->setVar( 'global_log_ids', $global_log_ids );
		$vars->setVar( 'local_log_ids', $local_log_ids );

		// Check for emergency disabling.
		$total = $wgMemc->get( AbuseFilter::filterUsedKey( $group ) );
		self::checkEmergencyDisable( $group, $logged_local_filters, $total );

		wfProfileOut( __METHOD__ . '-hitstats' );

		wfProfileOut( __METHOD__ );
	}

	/**
	 * Store a var dump to External Storage or the text table
	 * Some of this code is stolen from Revision::insertOn and friends
	 *
	 * @param $vars AbuseFilterVariableHolder
	 * @param $global bool
	 *
	 * @return int
	 */
	public static function storeVarDump( $vars, $global = false ) {
		wfProfileIn( __METHOD__ );

		global $wgCompressRevisions;

		// Get all variables yet set and compute old and new wikitext if not yet done
		// as those are needed for the diff view on top of the abuse log pages
		$vars = $vars->dumpAllVars( array( 'old_wikitext', 'new_wikitext' ) );

		// Vars is an array with native PHP data types (non-objects) now
		$text = serialize( $vars );
		$flags = array( 'nativeDataArray' );

		if ( $wgCompressRevisions ) {
			if ( function_exists( 'gzdeflate' ) ) {
				$text = gzdeflate( $text );
				$flags[] = 'gzip';
			}
		}

		// Store to ES if applicable
		global $wgDefaultExternalStore, $wgAbuseFilterCentralDB;
		if ( $wgDefaultExternalStore ) {
			if ( $global ) {
				$text = ExternalStore::insertToForeignDefault( $text, $wgAbuseFilterCentralDB );
			} else {
				$text = ExternalStore::insertToDefault( $text );
			}
			$flags[] = 'external';

			if ( !$text ) {
				// Not mission-critical, just return nothing
				wfProfileOut( __METHOD__ );
				return null;
			}
		}

		// Store to text table
		if ( $global ) {
			$dbw = wfGetDB( DB_MASTER, array(), $wgAbuseFilterCentralDB );
		} else {
			$dbw = wfGetDB( DB_MASTER );
		}
		$old_id = $dbw->nextSequenceValue( 'text_old_id_seq' );
		$dbw->insert( 'text',
			array(
				'old_id'    => $old_id,
				'old_text'  => $text,
				'old_flags' => implode( ',', $flags ),
			), __METHOD__
		);
		$text_id = $dbw->insertId();
		wfProfileOut( __METHOD__ );

		return $text_id;
	}

	/**
	 * Retrieve a var dump from External Storage or the text table
	 * Some of this code is stolen from Revision::loadText et al
	 *
	 * @param $stored_dump
	 *
	 * @return object|AbuseFilterVariableHolder|bool
	 */
	public static function loadVarDump( $stored_dump ) {
		wfProfileIn( __METHOD__ );

		// Back-compat
		if ( strpos( $stored_dump, 'stored-text:' ) === false ) {
			wfProfileOut( __METHOD__ );
			return unserialize( $stored_dump );
		}

		$text_id = substr( $stored_dump, strlen( 'stored-text:' ) );

		$dbr = wfGetDB( DB_SLAVE );

		$text_row = $dbr->selectRow(
			'text',
			array( 'old_text', 'old_flags' ),
			array( 'old_id' => $text_id ),
			__METHOD__
		);

		if ( !$text_row ) {
			wfProfileOut( __METHOD__ );
			return new AbuseFilterVariableHolder;
		}

		$flags = explode( ',', $text_row->old_flags );
		$text = $text_row->old_text;

		if ( in_array( 'external', $flags ) ) {
			$text = ExternalStore::fetchFromURL( $text );
		}

		if ( in_array( 'gzip', $flags ) ) {
			$text = gzinflate( $text );
		}

		$obj = unserialize( $text );

		if ( in_array( 'nativeDataArray', $flags ) ) {
			$vars = $obj;
			$obj = new AbuseFilterVariableHolder();
			foreach( $vars as $key => $value ) {
				$obj->setVar( $key, $value );
			}
		}

		wfProfileOut( __METHOD__ );
		return $obj;
	}

	/**
	 * @param $action string
	 * @param $parameters array
	 * @param $title Title
	 * @param $vars AbuseFilterVariableHolder
	 * @param $rule_desc
	 *
	 * @return array|null a message describing the action that was taken,
	 *         or null if no action was taken. The message is given as an array
	 *         containing the message key followed by any message parameters.
	 *
	 * @note: Returning the message as an array instead of a Message object is
	 *        needed for compatibility with MW 1.20: we will be constructing a
	 *        Status object from these messages, and before 1.21, Status did
	 *        not accept Message objects to be added directly.
	 */
	public static function takeConsequenceAction( $action, $parameters, $title,
		$vars, $rule_desc )
	{
		global $wgAbuseFilterCustomActionsHandlers, $wgRequest;

		$message = null;

		switch ( $action ) {
			case 'disallow':
				if ( strlen( $parameters[0] ) ) {
					$message = array( $parameters[0], $rule_desc );
				} else {
					// Generic message.
					$message = array(
						'abusefilter-disallowed',
						$rule_desc
					);
				}
				break;

			case 'block':
				global $wgUser, $wgAbuseFilterBlockDuration, $wgAbuseFilterAnonBlockDuration;
				$filterUser = AbuseFilter::getFilterUser();

				// Create a block.
				$block = new Block;
				$block->setTarget( $wgUser->getName() );
				$block->setBlocker( $filterUser );
				$block->mReason = wfMessage(
					'abusefilter-blockreason',
					$rule_desc
				)->inContentLanguage()->text();
				$block->isHardblock( false );
				$block->isAutoblocking( true );
				$block->prevents( 'createaccount', true );
				$block->prevents( 'editownusertalk', false );

				if ( $wgUser->isAnon() && $wgAbuseFilterAnonBlockDuration !== null ) {
					// The user isn't logged in and the anon block duration doesn't default to $wgAbuseFilterBlockDuration
					$expiry = $wgAbuseFilterAnonBlockDuration;
				} else {
					$expiry = $wgAbuseFilterBlockDuration;
				}

				$block->mExpiry = SpecialBlock::parseExpiryInput( $expiry );
				$block->insert();

				// Log it
				# Prepare log parameters
				$logParams = array();
				if ( $block->mExpiry == 'infinity' ) {
					$logParams[] = 'indefinite';
				} else {
					$logParams[] = $expiry;
				}
				$logParams[] = 'nocreate';

				$log = new LogPage( 'block' );
				$log->addEntry( 'block',
					Title::makeTitle( NS_USER, $wgUser->getName() ),
					wfMessage( 'abusefilter-blockreason', $rule_desc )->inContentLanguage()->text(),
					$logParams, self::getFilterUser()
				);

				$message = array(
					'abusefilter-blocked-display',
					$rule_desc
				);
				break;
			case 'rangeblock':
				$filterUser = AbuseFilter::getFilterUser();

				$range = IP::sanitizeRange( $wgRequest->getIP() . '/16' );

				// Create a block.
				$block = new Block;
				$block->setTarget( $range );
				$block->setBlocker( $filterUser );
				$block->mReason = wfMessage(
					'abusefilter-blockreason',
					$rule_desc
				)->inContentLanguage()->text();
				$block->isHardblock( false );
				$block->prevents( 'createaccount', true );
				$block->prevents( 'editownusertalk', false );
				$block->mExpiry = SpecialBlock::parseExpiryInput( '1 week' );

				$block->insert();

				// Log it
				# Prepare log parameters
				$logParams = array();
				$logParams[] = 'indefinite';
				$logParams[] = 'nocreate';

				$log = new LogPage( 'block' );
				$log->addEntry( 'block', Title::makeTitle( NS_USER, $range ),
					wfMessage( 'abusefilter-blockreason', $rule_desc )->inContentLanguage()->text(),
					$logParams, self::getFilterUser()
				);

				$message = array(
					'abusefilter-blocked-display',
					$rule_desc
				);
				break;
			case 'degroup':
				global $wgUser;
				if ( !$wgUser->isAnon() ) {
					// Remove all groups from the user. Ouch.
					$groups = $wgUser->getGroups();

					foreach ( $groups as $group ) {
						$wgUser->removeGroup( $group );
					}

					$message = array(
						'abusefilter-degrouped',
						$rule_desc
					);

					// Don't log it if there aren't any groups being removed!
					if ( !count( $groups ) ) {
						break;
					}

					// Log it.
					$log = new LogPage( 'rights' );

					$log->addEntry( 'rights',
						$wgUser->getUserPage(),
						wfMessage( 'abusefilter-degroupreason', $rule_desc )->inContentLanguage()->text(),
						array(
							implode( ', ', $groups ),
							''
						),
						self::getFilterUser()
					);
				}

				break;
			case 'blockautopromote':
				global $wgUser, $wgMemc;
				if ( !$wgUser->isAnon() ) {
					$blockPeriod = (int)mt_rand( 3 * 86400, 7 * 86400 ); // Block for 3-7 days.
					$wgMemc->set( self::autoPromoteBlockKey( $wgUser ), true, $blockPeriod );

					$message = array(
						'abusefilter-autopromote-blocked',
						$rule_desc
					);
				}
				break;

			case 'flag':
				// Do nothing. Here for completeness.
				break;

			case 'tag':
				// Mark with a tag on recentchanges.
				global $wgUser;

				$actionID = implode( '-', array(
						$title->getPrefixedText(), $wgUser->getName(),
							$vars->getVar( 'ACTION' )->toString()
					) );

				AbuseFilter::$tagsToSet[$actionID] = $parameters;
				break;
			default:
				if( isset( $wgAbuseFilterCustomActionsHandlers[$action] ) ) {
					$custom_function = $wgAbuseFilterCustomActionsHandlers[$action];
					if( is_callable( $custom_function ) ) {
						$msg = call_user_func( $custom_function, $action, $parameters, $title, $vars, $rule_desc );
					}
					if( isset( $msg ) ) {
						$message = array( $msg );
					}
				} else {
					wfDebugLog( 'AbuseFilter', "Unrecognised action $action" );
				}
		}

		return $message;
	}

	/**
	 * @param $throttleId
	 * @param $types
	 * @param $title
	 * @param $rateCount
	 * @param $ratePeriod
	 * @param $global bool
	 * @return bool
	 */
	public static function isThrottled( $throttleId, $types, $title, $rateCount, $ratePeriod, $global=false ) {
		global $wgMemc;

		$key = self::throttleKey( $throttleId, $types, $title, $global );
		$count = intval( $wgMemc->get( $key ) );

		wfDebugLog( 'AbuseFilter', "Got value $count for throttle key $key\n" );

		if ( $count > 0 ) {
			$wgMemc->incr( $key );
			$count++;
			wfDebugLog( 'AbuseFilter', "Incremented throttle key $key" );
		} else {
			wfDebugLog( 'AbuseFilter', "Added throttle key $key with value 1" );
			$wgMemc->add( $key, 1, $ratePeriod );
			$count = 1;
		}

		if ( $count > $rateCount ) {
			wfDebugLog( 'AbuseFilter', "Throttle $key hit value $count -- maximum is $rateCount." );
			return true; // THROTTLED
		}

		wfDebugLog( 'AbuseFilter', "Throttle $key not hit!" );

		return false; // NOT THROTTLED
	}

	/**
	 * @param $type
	 * @param $title Title
	 * @return int|string
	 */
	public static function throttleIdentifier( $type, $title ) {
		global $wgUser, $wgRequest;

		switch ( $type ) {
			case 'ip':
				$identifier = $wgRequest->getIP();
				break;
			case 'user':
				$identifier = $wgUser->getId();
				break;
			case 'range':
				$identifier = substr( IP::toHex( $wgRequest->getIP() ), 0, 4 );
				break;
			case 'creationdate':
				$reg = $wgUser->getRegistration();
				$identifier = $reg - ( $reg % 86400 );
				break;
			case 'editcount':
				// Hack for detecting different single-purpose accounts.
				$identifier = $wgUser->getEditCount();
				break;
			case 'site':
				$identifier = 1;
				break;
			case 'page':
				$identifier = $title->getPrefixedText();
				break;
			default:
				$identifier = 0;
		}

		return $identifier;
	}

	/**
	 * @param $throttleId
	 * @param $type
	 * @param $title Title
	 * @param $global bool
	 * @return String
	 */
	public static function throttleKey( $throttleId, $type, $title, $global=false ) {
		$types = explode( ',', $type );

		$identifiers = array();

		foreach ( $types as $subtype ) {
			$identifiers[] = self::throttleIdentifier( $subtype, $title );
		}

		$identifier = sha1( implode( ':', $identifiers ) );

		global $wgAbuseFilterIsCentral, $wgAbuseFilterCentralDB;

		if ( $global && !$wgAbuseFilterIsCentral ) {
			list ( $globalSite, $globalPrefix ) = wfSplitWikiID( $wgAbuseFilterCentralDB );
			return wfForeignMemcKey(
				$globalSite, $globalPrefix,
				'abusefilter', 'throttle', $throttleId, $type, $identifier );
		}

		return wfMemcKey( 'abusefilter', 'throttle', $throttleId, $type, $identifier );
	}

	/**
	 * @param $group
	 * @return String
	 */
	public static function getGlobalRulesKey( $group ) {
		global $wgAbuseFilterIsCentral, $wgAbuseFilterCentralDB;

		if ( !$wgAbuseFilterIsCentral ) {
			list ( $globalSite, $globalPrefix ) = wfSplitWikiID( $wgAbuseFilterCentralDB );

			return wfForeignMemcKey(
				$globalSite, $globalPrefix,
				'abusefilter', 'rules', $group
			);
		}

		return wfMemcKey( 'abusefilter', 'rules', $group );
	}

	/**
	 * @param $user User
	 * @return String
	 */
	public static function autoPromoteBlockKey( $user ) {
		return wfMemcKey( 'abusefilter', 'block-autopromote', $user->getId() );
	}

	/**
	 * Update statistics, and disable filters which are over-blocking.
	 * @param $filters
	 * @param $group
	 */
	public static function recordStats( $filters, $group = 'default' ) {
		global $wgAbuseFilterConditionLimit, $wgMemc;

		wfProfileIn( __METHOD__ );

		// Figure out if we've triggered overflows and blocks.
		$overflow_triggered = ( self::$condCount > $wgAbuseFilterConditionLimit );

		// Store some keys...
		$overflow_key = self::filterLimitReachedKey();
		$total_key = self::filterUsedKey( $group );

		$total = $wgMemc->get( $total_key );

		$storage_period = self::$statsStoragePeriod;

		if ( !$total || $total > 10000 ) {
			// This is for if the total doesn't exist, or has gone past 1000.
			// Recreate all the keys at the same time, so they expire together.
			$wgMemc->set( $total_key, 0, $storage_period );
			$wgMemc->set( $overflow_key, 0, $storage_period );

			foreach ( $filters as $filter => $matched ) {
				$wgMemc->set( self::filterMatchesKey( $filter ), 0, $storage_period );
			}
			$wgMemc->set( self::filterMatchesKey(), 0, $storage_period );
		}

		// Increment total
		$wgMemc->incr( $total_key );

		// Increment overflow counter, if our condition limit overflowed
		if ( $overflow_triggered ) {
			$wgMemc->incr( $overflow_key );
		}
		wfProfileOut( __METHOD__ );
	}

	/**
	 * @param $group
	 * @param $filters
	 * @param $total
	 */
	public static function checkEmergencyDisable( $group, $filters, $total ) {
		global $wgAbuseFilterEmergencyDisableThreshold, $wgAbuseFilterEmergencyDisableCount,
			$wgAbuseFilterEmergencyDisableAge, $wgMemc;

		foreach ( $filters as $filter ) {
			// determine emergency disable values for this action
			$emergencyDisableThreshold = self::getEmergencyValue( $wgAbuseFilterEmergencyDisableThreshold, $group );
			$filterEmergencyDisableCount = self::getEmergencyValue( $wgAbuseFilterEmergencyDisableCount, $group );
			$emergencyDisableAge = self::getEmergencyValue( $wgAbuseFilterEmergencyDisableAge, $group );

			// Increment counter
			$matchCount = $wgMemc->get( self::filterMatchesKey( $filter ) );

			// Handle missing keys...
			if ( !$matchCount ) {
				$wgMemc->set( self::filterMatchesKey( $filter ), 1, self::$statsStoragePeriod );
			} else {
				$wgMemc->incr( self::filterMatchesKey( $filter ) );
			}
			$matchCount++;

			// Figure out if the filter is subject to being deleted.
			$filter_age = wfTimestamp( TS_UNIX, self::$filters[$filter]->af_timestamp );
			$throttle_exempt_time = $filter_age + $emergencyDisableAge;

			if ( $total && $throttle_exempt_time > time()
				&& $matchCount > $filterEmergencyDisableCount
				&& ( $matchCount / $total ) > $emergencyDisableThreshold )
			{
				// More than $wgAbuseFilterEmergencyDisableCount matches,
				// constituting more than $emergencyDisableThreshold
				// (a fraction) of last few edits. Disable it.
				$dbw = wfGetDB( DB_MASTER );
				$dbw->update( 'abuse_filter',
					array( 'af_throttled' => 1 ),
					array( 'af_id' => $filter ),
					__METHOD__
				);
			}
		}
	}

	/**
	 * @param array $emergencyValue
	 * @param string $group
	 * @return mixed
	 */
	public static function getEmergencyValue( array $emergencyValue, $group ) {
		return isset( $emergencyValue[$group] ) ? $emergencyValue[$group] : $emergencyValue['default'];
	}

	/**
	 * @return String
	 */
	public static function filterLimitReachedKey() {
		return wfMemcKey( 'abusefilter', 'stats', 'overflow' );
	}

	/**
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @return String
	 */
	public static function filterUsedKey( $group = null ) {
		return wfMemcKey( 'abusefilter', 'stats', 'total', $group );
	}

	/**
	 * @param $filter
	 * @return String
	 */
	public static function filterMatchesKey( $filter = null ) {
		return wfMemcKey( 'abusefilter', 'stats', 'matches', $filter );
	}

	/**
	 * @return User
	 */
	public static function getFilterUser() {
		$user = User::newFromName( wfMessage( 'abusefilter-blocker' )->inContentLanguage()->text() );
		$user->load();
		if ( $user->getId() && $user->mPassword == '' ) {
			// Already set up.
			return $user;
		}

		// Not set up. Create it.
		if ( !$user->getId() ) {
			print 'Trying to create account -- user id is ' . $user->getId();
			$user->addToDatabase();
			$user->saveSettings();
			// Increment site_stats.ss_users
			$ssu = new SiteStatsUpdate( 0, 0, 0, 0, 1 );
			$ssu->doUpdate();
		} else {
			// Take over the account
			$user->setPassword( null );
			$user->setEmail( null );
			$user->saveSettings();
		}

		// Promote user so it doesn't look too crazy.
		$user->addGroup( 'sysop' );

		return $user;
	}

	/**
	 * @param $rules String
	 * @param $textName String
	 * @param $addResultDiv Boolean
	 * @param $canEdit Boolean
	 * @return string
	 */
	static function buildEditBox( $rules, $textName = 'wpFilterRules', $addResultDiv = true,
									$canEdit = true ) {
		global $wgOut;

		$textareaAttrib = array( 'dir' => 'ltr' ); # Rules are in English
		if ( !$canEdit ) {
			$textareaAttrib['readonly'] = 'readonly';
		}

		global $wgUser;
		$noTestAttrib = array();
		if ( !$wgUser->isAllowed( 'abusefilter-modify' ) ) {
			$noTestAttrib['disabled'] = 'disabled';
			$addResultDiv = false;
		}

		$rules = rtrim( $rules ) . "\n";
		$rules = Xml::textarea( $textName, $rules, 40, 5, $textareaAttrib );

		if ( $canEdit ) {
			$dropDown = self::getBuilderValues();
			// Generate builder drop-down
			$builder = '';

			$builder .= Xml::option( wfMessage( 'abusefilter-edit-builder-select' )->text() );

			foreach ( $dropDown as $group => $values ) {
				// Give grep a chance to find the usages:
				// abusefilter-edit-builder-group-op-arithmetic, abusefilter-edit-builder-group-op-comparison,
				// abusefilter-edit-builder-group-op-bool, abusefilter-edit-builder-group-misc,
				// abusefilter-edit-builder-group-funcs, abusefilter-edit-builder-group-vars
				$builder .=
					Xml::openElement(
						'optgroup',
						array( 'label' => wfMessage( "abusefilter-edit-builder-group-$group" )->text() )
					) . "\n";

				foreach ( $values as $content => $name ) {
					$builder .=
						Xml::option(
							wfMessage( "abusefilter-edit-builder-$group-$name" )->text(),
							$content
						) . "\n";
				}

				$builder .= Xml::closeElement( 'optgroup' ) . "\n";
			}

			$rules .=
				Xml::tags(
					'select',
					array( 'id' => 'wpFilterBuilder', ),
					$builder
				) . ' ';

			// Add syntax checking
			$rules .= Xml::element( 'input',
				array(
					'type' => 'button',
					'value' => wfMessage( 'abusefilter-edit-check' )->text(),
					'id' => 'mw-abusefilter-syntaxcheck'
				) + $noTestAttrib );
		}

		if ( $addResultDiv )
			$rules .= Xml::element( 'div',
				array( 'id' => 'mw-abusefilter-syntaxresult', 'style' => 'display: none;' ),
				'&#160;' );

		// Add script
		$wgOut->addModules( 'ext.abuseFilter.edit' );
		self::$editboxName = $textName;

		return $rules;
	}

	/**
	 * Each version is expected to be an array( $row, $actions )
	 * Returns an array of fields that are different.
	 *
	 * @param $version_1
	 * @param $version_2
	 *
	 * @return array
	 */
	static function compareVersions( $version_1, $version_2 ) {
		$compareFields = array(
			'af_public_comments',
			'af_pattern',
			'af_comments',
			'af_deleted',
			'af_enabled',
			'af_hidden',
			'af_global',
			'af_group',
		);
		$differences = array();

		list( $row1, $actions1 ) = $version_1;
		list( $row2, $actions2 ) = $version_2;

		foreach ( $compareFields as $field ) {
			if ( !isset( $row2->$field ) || $row1->$field != $row2->$field ) {
				$differences[] = $field;
			}
		}

		global $wgAbuseFilterAvailableActions;
		foreach ( $wgAbuseFilterAvailableActions as $action ) {
			if ( !isset( $actions1[$action] ) && !isset( $actions2[$action] ) ) {
				// They're both unset
			} elseif ( isset( $actions1[$action] ) && isset( $actions2[$action] ) ) {
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

	/**
	 * @param $row
	 * @return array
	 */
	static function translateFromHistory( $row ) {
		# Translate into an abuse_filter row with some black magic.
		# This is ever so slightly evil!
		$af_row = new StdClass;

		foreach ( self::$history_mappings as $af_col => $afh_col ) {
			$af_row->$af_col = $row->$afh_col;
		}

		# Process flags

		$af_row->af_deleted = 0;
		$af_row->af_hidden = 0;
		$af_row->af_enabled = 0;

		$flags = explode( ',', $row->afh_flags );
		foreach ( $flags as $flag ) {
			$col_name = "af_$flag";
			$af_row->$col_name = 1;
		}

		# Process actions
		$actions_raw = unserialize( $row->afh_actions );
		$actions_output = array();
		if ( is_array( $actions_raw ) ) {
			foreach ( $actions_raw as $action => $parameters ) {
				$actions_output[$action] = array(
					'action' => $action,
					'parameters' => $parameters
				);
			}
		}

		return array( $af_row, $actions_output );
	}

	/**
	 * @param $action string
	 * @return String
	 */
	static function getActionDisplay( $action ) {
		// Give grep a chance to find the usages:
		// abusefilter-action-tag, abusefilter-action-throttle, abusefilter-action-warn,
		// abusefilter-action-blockautopromote, abusefilter-action-block, abusefilter-action-degroup,
		// abusefilter-action-rangeblock, abusefilter-action-disallow
		$display = wfMessage( "abusefilter-action-$action" )->text();
		$display = wfMessage( "abusefilter-action-$action", $display )->isDisabled() ? $action : $display;
		return $display;
	}

	/**
	 * @param $row
	 * @return AbuseFilterVariableHolder|null
	 */
	public static function getVarsFromRCRow( $row ) {
		if ( $row->rc_this_oldid ) {
			// It's an edit.
			$vars = self::getEditVarsFromRCRow( $row );
		} elseif ( $row->rc_log_type == 'move' ) {
			$vars = self::getMoveVarsFromRCRow( $row );
		} elseif ( $row->rc_log_type == 'newusers' ) {
			$vars = self::getCreateVarsFromRCRow( $row );
		} else {
			return null;
		}
		if ( $vars ) {
			$vars->setVar( 'context', 'generated' );
			$vars->setVar( 'timestamp', wfTimestamp( TS_UNIX, $row->rc_timestamp ) );
		}

		return $vars;
	}

	/**
	 * @param $row
	 * @return AbuseFilterVariableHolder
	 */
	public static function getCreateVarsFromRCRow( $row ) {
		$vars = new AbuseFilterVariableHolder;

		$vars->setVar( 'ACTION', ( $row->rc_log_action == 'autocreate' ) ? 'autocreateaccount' : 'createaccount' );

		$name = Title::makeTitle( $row->rc_namespace, $row->rc_title )->getText();
		// Add user data if the account was created by a registered user
		if ( $row->rc_user && $name != $row->rc_user_text ) {
			$user = User::newFromName( $row->rc_user_text );
			$vars->addHolders( self::generateUserVars( $user ) );
		}

		$vars->setVar( 'accountname', $name );
		return $vars;
	}

	/**
	 * @param $row
	 * @return AbuseFilterVariableHolder
	 */
	public static function getEditVarsFromRCRow( $row ) {
		$vars = new AbuseFilterVariableHolder;
		$title = Title::makeTitle( $row->rc_namespace, $row->rc_title );

		if ( $row->rc_user ) {
			$user = User::newFromName( $row->rc_user_text );
		} else {
			$user = new User;
			$user->setName( $row->rc_user_text );
		}

		$vars->addHolders(
			self::generateUserVars( $user ),
			self::generateTitleVars( $title, 'ARTICLE' )
		);

		$vars->setVar( 'ACTION', 'edit' );
		$vars->setVar( 'SUMMARY', $row->rc_comment );
		$vars->setVar( 'minor_edit', $row->rc_minor );

		$vars->setLazyLoadVar( 'new_wikitext', 'revision-text-by-id',
			array( 'revid' => $row->rc_this_oldid ) );

		if ( $row->rc_last_oldid ) {
			$vars->setLazyLoadVar( 'old_wikitext', 'revision-text-by-id',
				array( 'revid' => $row->rc_last_oldid ) );
		} else {
			$vars->setVar( 'old_wikitext', '' );
		}

		$vars->addHolders( self::getEditVars( $title ) );

		return $vars;
	}

	/**
	 * @param $row
	 * @return AbuseFilterVariableHolder
	 */
	public static function getMoveVarsFromRCRow( $row ) {
		$vars = new AbuseFilterVariableHolder;

		if ( $row->rc_user ) {
			$user = User::newFromId( $row->rc_user );
		} else {
			$user = new User;
			$user->setName( $row->rc_user_text );
		}

		$params = explode( "\n", trim( $row->rc_params ) );

		$oldTitle = Title::makeTitle( $row->rc_namespace, $row->rc_title );
		$newTitle = Title::newFromText( $params[0] );

		$vars = AbuseFilterVariableHolder::merge(
			$vars,
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $oldTitle, 'MOVED_FROM' ),
			AbuseFilter::generateTitleVars( $newTitle, 'MOVED_TO' )
		);

		$vars->setVar( 'SUMMARY', $row->rc_comment );
		$vars->setVar( 'ACTION', 'move' );

		return $vars;
	}

	/**
	 * @param Title $title
	 * @param null|Page $page
	 * @return AbuseFilterVariableHolder
	 */
	public static function getEditVars( $title, Page $page = null ) {
		$vars = new AbuseFilterVariableHolder;

		// NOTE: $page may end up remaining null, e.g. if $title points to a special page.
		if ( !$page && $title instanceof Title && $title->canExist() ) {
			$page = WikiPage::factory( $title );
		}

		$vars->setLazyLoadVar( 'edit_diff', 'diff',
			array( 'oldtext-var' => 'old_wikitext', 'newtext-var' => 'new_wikitext' ) );
		$vars->setLazyLoadVar( 'new_size', 'length', array( 'length-var' => 'new_wikitext' ) );
		$vars->setLazyLoadVar( 'old_size', 'length', array( 'length-var' => 'old_wikitext' ) );
		$vars->setLazyLoadVar( 'edit_delta', 'subtract',
			array( 'val1-var' => 'new_size', 'val2-var' => 'old_size' ) );

		// Some more specific/useful details about the changes.
		$vars->setLazyLoadVar( 'added_lines', 'diff-split',
			array( 'diff-var' => 'edit_diff', 'line-prefix' => '+' ) );
		$vars->setLazyLoadVar( 'removed_lines', 'diff-split',
			array( 'diff-var' => 'edit_diff', 'line-prefix' => '-' ) );

		// Links
		$vars->setLazyLoadVar( 'added_links', 'link-diff-added',
			array( 'oldlink-var' => 'old_links', 'newlink-var' => 'all_links' ) );
		$vars->setLazyLoadVar( 'removed_links', 'link-diff-removed',
			array( 'oldlink-var' => 'old_links', 'newlink-var' => 'all_links' ) );
		$vars->setLazyLoadVar( 'new_text', 'strip-html',
			array( 'html-var' => 'new_html' ) );
		$vars->setLazyLoadVar( 'old_text', 'strip-html',
			array( 'html-var' => 'old_html' ) );

		if ( $title instanceof Title ) {
			$vars->setLazyLoadVar( 'all_links', 'links-from-wikitext',
				array(
					'namespace' => $title->getNamespace(),
					'title' => $title->getText(),
					'text-var' => 'new_wikitext',
					'article' => $page
				) );
			$vars->setLazyLoadVar( 'old_links', 'links-from-wikitext-or-database',
				array(
					'namespace' => $title->getNamespace(),
					'title' => $title->getText(),
					'text-var' => 'old_wikitext'
				) );
			$vars->setLazyLoadVar( 'new_pst', 'parse-wikitext',
				array(
					'namespace' => $title->getNamespace(),
					'title' => $title->getText(),
					'wikitext-var' => 'new_wikitext',
					'article' => $page,
					'pst' => true,
				) );
			$vars->setLazyLoadVar( 'new_html', 'parse-wikitext',
				array(
					'namespace' => $title->getNamespace(),
					'title' => $title->getText(),
					'wikitext-var' => 'new_wikitext',
					'article' => $page
				) );
			$vars->setLazyLoadVar( 'old_html', 'parse-wikitext-nonedit',
				array(
					'namespace' => $title->getNamespace(),
					'title' => $title->getText(),
					'wikitext-var' => 'old_wikitext'
				) );
		}

		return $vars;
	}

	/**
	 * @param $vars AbuseFilterVariableHolder
	 * @return string
	 */
	public static function buildVarDumpTable( $vars ) {
		// Export all values
		if ( $vars instanceof AbuseFilterVariableHolder ) {
			$vars = $vars->exportAllVars();
		}

		$output = '';

		// I don't want to change the names of the pre-existing messages
		// describing the variables, nor do I want to rewrite them, so I'm just
		// mapping the variable names to builder messages with a pre-existing array.
		$variableMessageMappings = self::getBuilderValues();
		$variableMessageMappings = $variableMessageMappings['vars'];

		$output .=
			Xml::openElement( 'table', array( 'class' => 'mw-abuselog-details' ) ) .
			Xml::openElement( 'tbody' ) .
			"\n";

		$header =
			Xml::element( 'th', null, wfMessage( 'abusefilter-log-details-var' )->text() ) .
			Xml::element( 'th', null, wfMessage( 'abusefilter-log-details-val' )->text() );
		$output .= Xml::tags( 'tr', null, $header ) . "\n";

		if ( !count( $vars ) ) {
			$output .= Xml::closeElement( 'tbody' ) . Xml::closeElement( 'table' );
			return $output;
		}

		// Now, build the body of the table.
		foreach ( $vars as $key => $value ) {
			$key = strtolower( $key );

			if ( !empty( $variableMessageMappings[$key] ) ) {
				$mapping = $variableMessageMappings[$key];
				$keyDisplay = wfMessage( "abusefilter-edit-builder-vars-$mapping" )->parse() .
				' ' . Xml::element( 'code', null, wfMessage( 'parentheses', $key )->text() );
			} else {
				$keyDisplay = Xml::element( 'code', null, $key );
			}

			if ( is_null( $value ) )
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

	/**
	 * @param $page
	 * @param $type
	 * @param $title Title
	 * @param $sk Skin
	 * @param $args array
	 * @param $filterWikilinks bool
	 * @return String
	 */
	static function modifyActionText( $page, $type, $title, $sk, $args, $filterWikilinks ) {
		list( $history_id, $filter_id ) = $args;
		$details_title = SpecialPage::getTitleFor( 'AbuseFilter', "history/$filter_id/diff/prev/$history_id" );
		if ( !$filterWikilinks ) { // Plaintext? Bug 43105
			return wfMessage( 'abusefilter-log-entry-modify', '[[' . $title->getFullText() . ']]', '[[' . $details_title->getFullText() . ']]' )->text();
		}

		$filter_link = Linker::link( $title );

		$details_text = wfMessage( 'abusefilter-log-detailslink' )->parse();
		$details_link = Linker::link( $details_title, $details_text );

		return wfMessage( 'abusefilter-log-entry-modify' )
			->rawParams( $filter_link, $details_link )->parse();
	}

	/**
	 * @param $action
	 * @param $parameters
	 * @return String
	 */
	static function formatAction( $action, $parameters ) {
		/*
		 * @var $wgLang Language
		 */
		global $wgLang;
		if ( count( $parameters ) == 0 ) {
			$displayAction = AbuseFilter::getActionDisplay( $action );
		} else {
			$displayAction = AbuseFilter::getActionDisplay( $action ) .
				wfMessage( 'colon-separator' )->escaped() .
					$wgLang->semicolonList( $parameters );
		}
		return $displayAction;
	}

	/**
	 * @param $value array
	 * @return string
	 */
	static function formatFlags( $value ) {
		/*
		 * @var $wgLang Language
		 */
		global $wgLang;
		$flags = array_filter( explode( ',', $value ) );
		$flags_display = array();
		foreach ( $flags as $flag ) {
			$flags_display[] = wfMessage( "abusefilter-history-$flag" )->text();
		}
		return $wgLang->commaList( $flags_display );
	}

	/**
	 * @param $filterID
	 * @return bool|mixed|string
	 */
	static function getGlobalFilterDescription( $filterID ) {
		global $wgAbuseFilterCentralDB;

		if ( !$wgAbuseFilterCentralDB ) {
			return '';
		}

		$fdb = wfGetDB( DB_SLAVE, array(), $wgAbuseFilterCentralDB );

		return $fdb->selectField(
			'abuse_filter',
			'af_public_comments',
			array( 'af_id' => $filterID ),
			__METHOD__
		);
	}

	/**
	 * Gives either the user-specified name for a group,
	 * or spits the input back out
	 * @param $group String: Internal name of the filter group
	 * @return String A name for that filter group, or the input.
	 */
	static function nameGroup($group) {
		// Give grep a chance to find the usages: abusefilter-group-default
		$msg = "abusefilter-group-$group";
		return wfMessage($msg)->exists() ? wfMessage($msg)->escaped() : $group;
	}

	/**
	 * Look up some text of a revision from its revision id
	 *
	 * Note that this is really *some* text, we do not make *any* guarantee
	 * that this text will be even close to what the user actually sees, or
	 * that the form is fit for any intended purpose.
	 *
	 * Note also that if the revision for any reason is not an Revision
	 * the function returns with an empty string.
	 *
	 * @param Revision $revision a valid revision
	 * @param $audience Integer: one of:
	 *      Revision::FOR_PUBLIC       to be displayed to all users
	 *      Revision::FOR_THIS_USER    to be displayed to the given user
	 *      Revision::RAW              get the text regardless of permissions
	 * @return string|null the content of the revision as some kind of string,
	 * 		or an empty string if it can not be found
	 */
	static function revisionToString( $revision, $audience = Revision::FOR_THIS_USER ) {
		if ( !$revision instanceof Revision ) {
			return '';
		}
		if ( defined( 'MW_SUPPORTS_CONTENTHANDLER' ) ) {
			$content = $revision->getContent( $audience );
			if ( $content === null ) {
				return '';
			}
			$result = self::contentToString( $content );
		} else {
			// For MediaWiki without contenthandler support (< 1.21)
			$result = $revision->getText();
		}
		return $result;
	}

	/**
	 * Converts the given Content object to a string.
	 *
	 * This uses Content::getNativeData() if $content is an instance of TextContent,
	 * or Content::getTextForSearchIndex() otherwise.
	 *
	 * The hook 'AbuseFilter::contentToString' can be used to override this
	 * behavior.
	 *
	 * @param Content $content
	 *
	 * @return string a suitable string representation of the content.
	 */
	static function contentToString( Content $content ) {
		$text = null;

		if ( wfRunHooks( 'AbuseFilter-contentToString', array( $content, &$text ) ) ) {
			$text = $content instanceof TextContent
						? $content->getNativeData()
						: $content->getTextForSearchIndex();
		}

		if ( is_string( $text ) ) {
			// bug 20310
			// XXX: Is this really needed? Should we rather apply PST?
			$text = str_replace( "\r\n", "\n", $text );
		} else {
			$text = '';
		}

		return $text;
	}

	/*
	 * Get the history ID of the first change to a given filter
	 *
	 * @param $filterId integer: Filter id
	 * @return integer|bool
	 */
	public static function getFirstFilterChange( $filterID ) {
		static $firstChanges = array();

		if ( !isset( $firstChanges[ $filterID ] ) ) {
			$dbr = wfGetDB( DB_SLAVE );
			$row = $dbr->selectRow(
				'abuse_filter_history',
				'afh_id',
				array(
					'afh_filter' => $filterID,
				),
				__METHOD__,
				array( 'ORDER BY' => 'afh_timestamp ASC' )
			);
			$firstChanges[$filterID] = $row->afh_id;
		}

		return $firstChanges[$filterID];
	}
}
