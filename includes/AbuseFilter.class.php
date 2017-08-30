<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

/**
 * This class contains most of the business logic of AbuseFilter. It consists of mostly
 * static functions that handle activities such as parsing edits, applying filters,
 * logging actions, etc.
 */
class AbuseFilter {
	public static $statsStoragePeriod = 86400;
	public static $condLimitEnabled = true;

	/** @var array Map of (filter ID => stdClass) */
	private static $filterCache = [];

	public static $condCount = 0;

	/** @var array Map of (action ID => string[]) */
	public static $tagsToSet = []; // FIXME: avoid global state here

	public static $history_mappings = [
		'af_pattern' => 'afh_pattern',
		'af_user' => 'afh_user',
		'af_user_text' => 'afh_user_text',
		'af_timestamp' => 'afh_timestamp',
		'af_comments' => 'afh_comments',
		'af_public_comments' => 'afh_public_comments',
		'af_deleted' => 'afh_deleted',
		'af_id' => 'afh_filter',
		'af_group' => 'afh_group',
	];
	public static $builderValues = [
		'op-arithmetic' => [
			'+' => 'addition',
			'-' => 'subtraction',
			'*' => 'multiplication',
			'/' => 'divide',
			'%' => 'modulo',
			'**' => 'pow'
		],
		'op-comparison' => [
			'==' => 'equal',
			'!=' => 'notequal',
			'<' => 'lt',
			'>' => 'gt',
			'<=' => 'lte',
			'>=' => 'gte'
		],
		'op-bool' => [
			'!' => 'not',
			'&' => 'and',
			'|' => 'or',
			'^' => 'xor'
		],
		'misc' => [
			'in' => 'in',
			'contains' => 'contains',
			'like' => 'like',
			'""' => 'stringlit',
			'rlike' => 'rlike',
			'irlike' => 'irlike',
			'cond ? iftrue : iffalse' => 'tern',
			'if cond then iftrue elseiffalse end' => 'cond',
		],
		'funcs' => [
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
		],
		'vars' => [
			'timestamp' => 'timestamp',
			'accountname' => 'accountname',
			'action' => 'action',
			'added_lines' => 'addedlines',
			'edit_delta' => 'delta',
			'edit_diff' => 'diff',
			'new_size' => 'newsize',
			'old_size' => 'oldsize',
			'new_content_model' => 'new-content-model',
			'old_content_model' => 'old-content-model',
			'removed_lines' => 'removedlines',
			'summary' => 'summary',
			'article_articleid' => 'article-id',
			'article_namespace' => 'article-ns',
			'article_text' => 'article-text',
			'article_prefixedtext' => 'article-prefixedtext',
			// 'article_views' => 'article-views', # May not be enabled, defined in getBuilderValues()
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
			'user_rights' => 'user-rights',
			'user_blocked' => 'user-blocked',
			'user_emailconfirm' => 'user-emailconfirm',
			'old_wikitext' => 'old-text',
			'new_wikitext' => 'new-text',
			'added_links' => 'added-links',
			'removed_links' => 'removed-links',
			'all_links' => 'all-links',
			'new_pst' => 'new-pst',
			'edit_diff_pst' => 'diff-pst',
			'added_lines_pst' => 'addedlines-pst',
			'new_text' => 'new-text-stripped',
			'new_html' => 'new-html',
			'article_restrictions_edit' => 'restrictions-edit',
			'article_restrictions_move' => 'restrictions-move',
			'article_restrictions_create' => 'restrictions-create',
			'article_restrictions_upload' => 'restrictions-upload',
			'article_recent_contributors' => 'recent-contributors',
			'article_first_contributor' => 'first-contributor',
			// 'old_text' => 'old-text-stripped', # Disabled, performance
			// 'old_html' => 'old-html', # Disabled, performance
			'old_links' => 'old-links',
			'minor_edit' => 'minor-edit',
			'file_sha1' => 'file-sha1',
			'file_size' => 'file-size',
			'file_mime' => 'file-mime',
			'file_mediatype' => 'file-mediatype',
			'file_width' => 'file-width',
			'file_height' => 'file-height',
			'file_bits_per_channel' => 'file-bits-per-channel',
		],
	];

	public static $editboxName = null;

	/**
	 * @param IContextSource $context
	 * @param string $pageType
	 */
	public static function addNavigationLinks( IContextSource $context, $pageType ) {
		$linkDefs = [
			'home' => 'Special:AbuseFilter',
			'recentchanges' => 'Special:AbuseFilter/history',
			'examine' => 'Special:AbuseFilter/examine',
			'log' => 'Special:AbuseLog',
		];

		if ( $context->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			$linkDefs = array_merge( $linkDefs, [
				'test' => 'Special:AbuseFilter/test',
				'tools' => 'Special:AbuseFilter/tools',
				'import' => 'Special:AbuseFilter/import',
			] );
		}

		// Save some translator work
		$msgOverrides = [
			'recentchanges' => 'abusefilter-filter-log',
		];

		$links = [];
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		foreach ( $linkDefs as $name => $page ) {
			// Give grep a chance to find the usages:
			// abusefilter-topnav-home, abusefilter-topnav-test, abusefilter-topnav-examine
			// abusefilter-topnav-log, abusefilter-topnav-tools, abusefilter-topnav-import
			$msgName = "abusefilter-topnav-$name";

			if ( isset( $msgOverrides[$name] ) ) {
				$msgName = $msgOverrides[$name];
			}

			$msg = $context->msg( $msgName )->parse();
			$title = Title::newFromText( $page );

			if ( $name == $pageType ) {
				$links[] = Xml::tags( 'strong', null, $msg );
			} else {
				$links[] = $linkRenderer->makeLink( $title, new HtmlArmor( $msg ) );
			}
		}

		$linkStr = $context->msg( 'parentheses', $context->getLanguage()->pipeList( $links ) )->text();
		$linkStr = $context->msg( 'abusefilter-topnav' )->parse() . " $linkStr";

		$linkStr = Xml::tags( 'div', [ 'class' => 'mw-abusefilter-navigation' ], $linkStr );

		$context->getOutput()->setSubtitle( $linkStr );
	}

	/**
	 * @static
	 * @param User $user
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateUserVars( $user ) {
		$vars = new AbuseFilterVariableHolder;

		$vars->setLazyLoadVar(
			'user_editcount',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getEditCount' ]
		);

		$vars->setVar( 'user_name', $user->getName() );

		$vars->setLazyLoadVar(
			'user_emailconfirm',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getEmailAuthenticationTimestamp' ]
		);

		$vars->setLazyLoadVar(
			'user_age',
			'user-age',
			[ 'user' => $user, 'asof' => wfTimestampNow() ]
		);

		$vars->setLazyLoadVar(
			'user_groups',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getEffectiveGroups' ]
		);

		$vars->setLazyLoadVar(
			'user_rights',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getRights' ]
		);

		$vars->setLazyLoadVar(
			'user_blocked',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'isBlocked' ]
		);

		Hooks::run( 'AbuseFilter-generateUserVars', [ $vars, $user ] );

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
		Hooks::run( 'AbuseFilter-builder', [ &$realValues ] );

		return $realValues;
	}

	/**
	 * @param string $filter
	 * @return bool
	 */
	public static function filterHidden( $filter ) {
		$globalIndex = self::decodeGlobalName( $filter );
		if ( $globalIndex ) {
			global $wgAbuseFilterCentralDB;
			if ( !$wgAbuseFilterCentralDB ) {
				return false;
			}
			$dbr = wfGetDB( DB_REPLICA, [], $wgAbuseFilterCentralDB );
			$filter = $globalIndex;
		} else {
			$dbr = wfGetDB( DB_REPLICA );
		}
		if ( $filter === 'new' ) {
			return false;
		};
		$hidden = $dbr->selectField(
			'abuse_filter',
			'af_hidden',
			[ 'af_id' => $filter ],
			__METHOD__
		);

		return (bool)$hidden;
	}

	/**
	 * @param int $val
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
	 * @param Title|null $title
	 * @param string $prefix
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateTitleVars( $title, $prefix ) {
		$vars = new AbuseFilterVariableHolder;

		if ( !$title ) {
			return $vars;
		}

		$vars->setVar( $prefix . '_ARTICLEID', $title->getArticleID() );
		$vars->setVar( $prefix . '_NAMESPACE', $title->getNamespace() );
		$vars->setVar( $prefix . '_TEXT', $title->getText() );
		$vars->setVar( $prefix . '_PREFIXEDTEXT', $title->getPrefixedText() );

		global $wgDisableCounters;
		if ( !$wgDisableCounters && !$title->isSpecialPage() ) {
			// Support: HitCounters extension
			// XXX: This should be part of the extension (T159069)
			if ( method_exists( 'HitCounters\HitCounters', 'getCount' ) ) {
				$vars->setVar( $prefix . '_VIEWS', HitCounters\HitCounters::getCount( $title ) );
			}
		}

		// Use restrictions.
		global $wgRestrictionTypes;
		foreach ( $wgRestrictionTypes as $action ) {
			$vars->setLazyLoadVar( "{$prefix}_restrictions_$action", 'get-page-restrictions',
				[ 'title' => $title->getText(),
					'namespace' => $title->getNamespace(),
					'action' => $action
				]
			);
		}

		$vars->setLazyLoadVar( "{$prefix}_recent_contributors", 'load-recent-authors',
			[
				'title' => $title->getText(),
				'namespace' => $title->getNamespace()
			] );

		$vars->setLazyLoadVar( "{$prefix}_first_contributor", 'load-first-author',
			[
				'title' => $title->getText(),
				'namespace' => $title->getNamespace()
			] );

		Hooks::run( 'AbuseFilter-generateTitleVars', [ $vars, $title, $prefix ] );

		return $vars;
	}

	/**
	 * @param $filter
	 * @return mixed
	 */
	public static function checkSyntax( $filter ) {
		global $wgAbuseFilterParserClass;

		/** @var $parser AbuseFilterParser */
		$parser = new $wgAbuseFilterParserClass;

		return $parser->checkSyntax( $filter );
	}

	/**
	 * @param $expr
	 * @param array $vars
	 * @return string
	 */
	public static function evaluateExpression( $expr, $vars = [] ) {
		global $wgAbuseFilterParserClass;

		if ( self::checkSyntax( $expr ) !== true ) {
			return 'BADSYNTAX';
		}

		/** @var $parser AbuseFilterParser */
		$parser = new $wgAbuseFilterParserClass( $vars );

		return $parser->evaluateExpression( $expr );
	}

	/**
	 * @param string $conds
	 * @param AbuseFilterVariableHolder $vars
	 * @param bool $ignoreError
	 * @return bool
	 * @throws Exception
	 */
	public static function checkConditions(
		$conds, $vars, $ignoreError = true
	) {
		global $wgAbuseFilterParserClass;

		static $parser, $lastVars;

		if ( is_null( $parser ) || $vars !== $lastVars ) {
			/** @var $parser AbuseFilterParser */
			$parser = new $wgAbuseFilterParserClass( $vars );
			$lastVars = $vars;
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

		return $result;
	}

	/**
	 * Returns an associative array of filters which were tripped
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 *
	 * @return bool[] Map of (integer filter ID => bool)
	 */
	public static function checkAllFilters( $vars, $group = 'default' ) {
		global $wgAbuseFilterCentralDB, $wgAbuseFilterIsCentral;

		// Fetch from the database.
		$filter_matched = [];

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'abuse_filter',
			'*',
			[
				'af_enabled' => 1,
				'af_deleted' => 0,
				'af_group' => $group,
			],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$filter_matched[$row->af_id] = self::checkFilter( $row, $vars, true );
		}

		if ( $wgAbuseFilterCentralDB && !$wgAbuseFilterIsCentral ) {
			// Global filters
			$globalRulesKey = self::getGlobalRulesKey( $group );

			$fname = __METHOD__;
			$res = ObjectCache::getMainWANInstance()->getWithSetCallback(
				$globalRulesKey,
				WANObjectCache::TTL_INDEFINITE,
				function () use ( $group, $fname ) {
					global $wgAbuseFilterCentralDB;

					$fdb = wfGetLB( $wgAbuseFilterCentralDB )->getConnectionRef(
						DB_REPLICA, [], $wgAbuseFilterCentralDB
					);

					return iterator_to_array( $fdb->select(
						'abuse_filter',
						'*',
						[
							'af_enabled' => 1,
							'af_deleted' => 0,
							'af_global' => 1,
							'af_group' => $group,
						],
						$fname
					) );
				},
				[
					'checkKeys' => [ $globalRulesKey ],
					'lockTSE' => 300
				]
			);

			foreach ( $res as $row ) {
				$filter_matched['global-' . $row->af_id] =
					self::checkFilter( $row, $vars, true, 'global-' );
			}
		}

		// Update statistics, and disable filters which are over-blocking.
		self::recordStats( $filter_matched, $group );

		return $filter_matched;
	}

	/**
	 * @static
	 * @param stdClass $row
	 * @param AbuseFilterVariableHolder $vars
	 * @param bool $profile
	 * @param string $prefix
	 * @return bool
	 */
	public static function checkFilter( $row, $vars, $profile = false, $prefix = '' ) {
		global $wgAbuseFilterProfile;

		$filterID = $prefix . $row->af_id;

		$startConds = $startTime = null;
		if ( $profile && $wgAbuseFilterProfile ) {
			$startConds = self::$condCount;
			$startTime = microtime( true );
		}

		// Store the row somewhere convenient
		self::$filterCache[$filterID] = $row;

		// Check conditions...
		$pattern = trim( $row->af_pattern );
		if (
			self::checkConditions(
				$pattern,
				$vars,
				true /* ignore errors */
			)
		) {
			// Record match.
			$result = true;
		} else {
			// Record non-match.
			$result = false;
		}

		if ( $profile && $wgAbuseFilterProfile ) {
			$endTime = microtime( true );
			$endConds = self::$condCount;

			$timeTaken = $endTime - $startTime;
			$condsUsed = $endConds - $startConds;
			self::recordProfilingResult( $row->af_id, $timeTaken, $condsUsed );
		}

		return $result;
	}

	/**
	 * @param int $filter
	 */
	public static function resetFilterProfile( $filter ) {
		$stash = ObjectCache::getMainStashInstance();
		$countKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'count' );
		$totalKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'total' );
		$condsKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'conds' );

		$stash->delete( $countKey );
		$stash->delete( $totalKey );
		$stash->delete( $condsKey );
	}

	/**
	 * @param int $filter
	 * @param float $time
	 * @param int $conds
	 */
	public static function recordProfilingResult( $filter, $time, $conds ) {
		// Defer updates to avoid massive (~1 second) edit time increases
		DeferredUpdates::addCallableUpdate( function () use ( $filter, $time, $conds ) {
			$stash = ObjectCache::getMainStashInstance();
			$countKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'count' );
			$totalKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'total' );
			$condsKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'conds' );

			$curCount = $stash->get( $countKey );
			$curTotal = $stash->get( $totalKey );
			$curConds = $stash->get( $condsKey );

			if ( $curCount ) {
				$stash->set( $condsKey, $curConds + $conds, 3600 );
				$stash->set( $totalKey, $curTotal + $time, 3600 );
				$stash->incr( $countKey );
			} else {
				$stash->set( $countKey, 1, 3600 );
				$stash->set( $totalKey, $time, 3600 );
				$stash->set( $condsKey, $conds, 3600 );
			}
		} );
	}

	/**
	 * @param string $filter
	 * @return array
	 */
	public static function getFilterProfile( $filter ) {
		$stash = ObjectCache::getMainStashInstance();
		$countKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'count' );
		$totalKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'total' );
		$condsKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'conds' );

		$curCount = $stash->get( $countKey );
		$curTotal = $stash->get( $totalKey );
		$curConds = $stash->get( $condsKey );

		if ( !$curCount ) {
			return [ 0, 0 ];
		}

		$timeProfile = ( $curTotal / $curCount ) * 1000; // 1000 ms in a sec
		$timeProfile = round( $timeProfile, 2 ); // Return in ms, rounded to 2dp

		$condProfile = ( $curConds / $curCount );
		$condProfile = round( $condProfile, 0 );

		return [ $timeProfile, $condProfile ];
	}

	/**
	 * Utility function to decode global-$index to $index. Returns false if not global
	 *
	 * @param string $filter
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
	 * @param string[] $filters
	 * @return array[]
	 */
	public static function getConsequencesForFilters( $filters ) {
		$globalFilters = [];
		$localFilters = [];

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
		$dbr = wfGetDB( DB_REPLICA );
		// Retrieve the consequences.
		$consequences = [];

		if ( count( $localFilters ) ) {
			$consequences = self::loadConsequencesFromDB( $dbr, $localFilters );
		}

		if ( count( $globalFilters ) ) {
			$fdb = wfGetDB( DB_REPLICA, [], $wgAbuseFilterCentralDB );
			$consequences = $consequences + self::loadConsequencesFromDB( $fdb, $globalFilters, 'global-' );
		}

		return $consequences;
	}

	/**
	 * @param DatabaseBase $dbr
	 * @param string[] $filters
	 * @param string $prefix
	 * @return array[]
	 */
	public static function loadConsequencesFromDB( $dbr, $filters, $prefix = '' ) {
		$actionsByFilter = [];
		foreach ( $filters as $filter ) {
			$actionsByFilter[$prefix . $filter] = [];
		}

		$res = $dbr->select(
			[ 'abuse_filter_action', 'abuse_filter' ],
			'*',
			[ 'af_id' => $filters ],
			__METHOD__,
			[],
			[ 'abuse_filter_action' => [ 'LEFT JOIN', 'afa_filter=af_id' ] ]
		);

		// Categorise consequences by filter.
		global $wgAbuseFilterRestrictions;
		foreach ( $res as $row ) {
			if ( $row->af_throttled
				&& !empty( $wgAbuseFilterRestrictions[$row->afa_consequence] )
			) {
				# Don't do the action
			} elseif ( $row->afa_filter != $row->af_id ) {
				// We probably got a NULL, as it's a LEFT JOIN.
				// Don't add it.
			} else {
				$actionsByFilter[$prefix . $row->afa_filter][$row->afa_consequence] = [
					'action' => $row->afa_consequence,
					'parameters' => explode( "\n", $row->afa_parameters )
				];
			}
		}

		return $actionsByFilter;
	}

	/**
	 * Executes a list of actions.
	 *
	 * @param string[] $filters
	 * @param Title $title
	 * @param AbuseFilterVariableHolder $vars
	 * @return Status returns the operation's status. $status->isOK() will return true if
	 *         there were no actions taken, false otherwise. $status->getValue() will return
	 *         an array listing the actions taken. $status->getErrors() etc. will provide
	 *         the errors and warnings to be shown to the user to explain the actions.
	 */
	public static function executeFilterActions( $filters, $title, $vars ) {
		global $wgMainCacheType;

		$actionsByFilter = self::getConsequencesForFilters( $filters );
		$actionsTaken = array_fill_keys( $filters, [] );

		$messages = [];

		global $wgOut, $wgAbuseFilterDisallowGlobalLocalBlocks, $wgAbuseFilterRestrictions;
		foreach ( $actionsByFilter as $filter => $actions ) {
			// Special-case handling for warnings.
			$parsed_public_comments = $wgOut->parseInline(
				self::getFilter( $filter )->af_public_comments
			);

			$global_filter = self::decodeGlobalName( $filter ) !== false;

			// If the filter is throttled and throttling is available via object
			// caching, check to see if the user has hit the throttle.
			if ( !empty( $actions['throttle'] ) && $wgMainCacheType !== CACHE_NONE ) {
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
				$actions = array_diff_key( $actions, array_filter( $wgAbuseFilterRestrictions ) );
			}

			if ( !empty( $actions['warn'] ) ) {
				$parameters = $actions['warn']['parameters'];
				$warnKey = 'abusefilter-warned-' . md5( $title->getPrefixedText() ) . '-' . $filter;

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
					$messages[] = [ $msg, $parsed_public_comments, $filter ];

					$actionsTaken[$filter][] = 'warn';

					continue; // Don't do anything else.
				} else {
					// We already warned them
					$_SESSION[$warnKey] = false;
				}

				unset( $actions['warn'] );
			}

			// prevent double warnings
			if ( count( array_intersect_key( $actions, array_filter( $wgAbuseFilterRestrictions ) ) ) > 0 &&
				!empty( $actions['disallow'] )
			) {
				unset( $actions['disallow'] );
			}

			// Do the rest of the actions
			foreach ( $actions as $action => $info ) {
				$newMsg = self::takeConsequenceAction(
					$action,
					$info['parameters'],
					$title,
					$vars,
					self::getFilter( $filter )->af_public_comments,
					$filter
				);

				if ( $newMsg !== null ) {
					$messages[] = $newMsg;
				}
				$actionsTaken[$filter][] = $action;
			}
		}

		return self::buildStatus( $actionsTaken, $messages );
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
	 * @return Status
	 */
	protected static function buildStatus( array $actionsTaken, array $messages ) {
		$status = Status::newGood( $actionsTaken );

		foreach ( $messages as $msg ) {
			call_user_func_array( [ $status, 'fatal' ], $msg );
		}

		return $status;
	}

	/**
	 * @param AbuseFilterVariableHolder $vars
	 * @param Title $title
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @param User $user The user performing the action; defaults to $wgUser
	 * @param string $mode Use 'execute' to run filters and log or 'stash' to only cache matches
	 * @return Status
	 */
	public static function filterAction(
		$vars, $title, $group = 'default', $user = null, $mode = 'execute'
	) {
		global $wgUser, $wgTitle, $wgRequest, $wgAbuseFilterRuntimeProfile;

		$context = RequestContext::getMain();
		$oldContextTitle = $context->getTitle();

		$oldWgTitle = $wgTitle;

		if ( !$wgTitle ) {
			$wgTitle = SpecialPage::getTitleFor( 'AbuseFilter' );
		}

		if ( !$user ) {
			$user = $wgUser;
		}

		$logger = LoggerFactory::getInstance( 'StashEdit' );
		$statsd = MediaWikiServices::getInstance()->getStatsdDataFactory();

		// Add vars from extensions
		Hooks::run( 'AbuseFilter-filterAction', [ &$vars, $title ] );
		$vars->setVar( 'context', 'filter' );
		$vars->setVar( 'timestamp', time() );

		// Get the stash key based on the relevant "input" variables
		$cache = ObjectCache::getLocalClusterInstance();
		$stashKey = self::getStashKey( $cache, $vars, $group );
		$isForEdit = ( $vars->getVar( 'action' )->toString() === 'edit' );

		if ( $wgAbuseFilterRuntimeProfile ) {
			$startTime = microtime( true );
		}

		$filter_matched = false;
		if ( $mode === 'execute' && $isForEdit ) {
			// Check the filter edit stash results first
			$cacheData = $cache->get( $stashKey );
			if ( $cacheData ) {
				$filter_matched = $cacheData['matches'];
				// Merge in any tags to apply to recent changes entries
				self::bufferTagsToSetByAction( $cacheData['tags'] );
			}
		}

		if ( is_array( $filter_matched ) ) {
			if ( $isForEdit && $mode !== 'stash' ) {
				$logger->info( __METHOD__ . ": cache hit for '$title' (key $stashKey)." );
				$statsd->increment( 'abusefilter.check-stash.hit' );
			}
		} else {
			$filter_matched = self::checkAllFilters( $vars, $group );
			if ( $isForEdit && $mode !== 'stash' ) {
				$logger->info( __METHOD__ . ": cache miss for '$title' (key $stashKey)." );
				$statsd->increment( 'abusefilter.check-stash.miss' );
			}
		}

		if ( $mode === 'stash' ) {
			// Save the filter stash result and do nothing further
			$cacheData = [ 'matches' => $filter_matched, 'tags' => self::$tagsToSet ];

			// Add runtime metrics in cache for later use
			if ( $wgAbuseFilterRuntimeProfile ) {
				$cacheData['condCount'] = self::$condCount;
				$cacheData['runtime'] = ( microtime( true ) - $startTime ) * 1000;
			}

			$cache->set( $stashKey, $cacheData, $cache::TTL_MINUTE );
			$logger->debug( __METHOD__ . ": cache store for '$title' (key $stashKey)." );
			$statsd->increment( 'abusefilter.check-stash.store' );

			return Status::newGood();
		}

		$matched_filters = array_keys( array_filter( $filter_matched ) );

		// Save runtime metrics only on edits
		if ( $wgAbuseFilterRuntimeProfile && $mode === 'execute' && $isForEdit ) {
			if ( $cacheData ) {
				$runtime = $cacheData['runtime'];
				$condCount = $cacheData['condCount'];
			} else {
				$runtime = ( microtime( true ) - $startTime ) * 1000;
				$condCount = self::$condCount;
			}

			self::recordRuntimeProfilingResult( count( $matched_filters ), $condCount, $runtime );
		}

		if ( count( $matched_filters ) == 0 ) {
			$status = Status::newGood();
		} else {
			$status = self::executeFilterActions( $matched_filters, $title, $vars );
			$actions_taken = $status->getValue();
			$action = $vars->getVar( 'ACTION' )->toString();

			// If $wgUser isn't safe to load (e.g. a failure during
			// AbortAutoAccount), create a dummy anonymous user instead.
			$user = $user->isSafeToLoad() ? $user : new User;

			// Create a template
			$log_template = [
				'afl_user' => $user->getId(),
				'afl_user_text' => $user->getName(),
				'afl_timestamp' => wfGetDB( DB_REPLICA )->timestamp( wfTimestampNow() ),
				'afl_namespace' => $title->getNamespace(),
				'afl_title' => $title->getDBkey(),
				'afl_ip' => $wgRequest->getIP()
			];

			// Hack to avoid revealing IPs of people creating accounts
			if ( !$user->getId() && ( $action == 'createaccount' || $action == 'autocreateaccount' ) ) {
				$log_template['afl_user_text'] = $vars->getVar( 'accountname' )->toString();
			}

			self::addLogEntries( $actions_taken, $log_template, $action, $vars, $group );
		}

		// Bug 53498: If we screwed around with $wgTitle, reset it so the title
		// is correctly picked up from the request later. Do the same for the
		// main RequestContext, because that might have picked up the bogus
		// title from $wgTitle.
		if ( $wgTitle !== $oldWgTitle ) {
			$wgTitle = $oldWgTitle;
		}

		if ( $context->getTitle() !== $oldContextTitle && $oldContextTitle instanceof Title ) {
			$context->setTitle( $oldContextTitle );
		}

		return $status;
	}

	/**
	 * @param string $id Filter ID (integer or "global-<integer>")
	 * @return stdClass|null DB row
	 */
	public static function getFilter( $id ) {
		global $wgAbuseFilterCentralDB;

		if ( !isset( self::$filterCache[$id] ) ) {
			$globalIndex = self::decodeGlobalName( $id );
			if ( $globalIndex ) {
				// Global wiki filter
				if ( !$wgAbuseFilterCentralDB ) {
					return null; // not enabled
				}

				$id = $globalIndex;
				$lb = wfGetLB( $wgAbuseFilterCentralDB );
				$dbr = $lb->getConnectionRef( DB_REPLICA, [], $wgAbuseFilterCentralDB );
			} else {
				// Local wiki filter
				$dbr = wfGetDB( DB_REPLICA );
			}

			$row = $dbr->selectRow( 'abuse_filter', '*', [ 'af_id' => $id ], __METHOD__ );
			self::$filterCache[$id] = $row ?: null;
		}

		return self::$filterCache[$id];
	}

	/**
	 * @param BagOStuff $cache
	 * @param AbuseFilterVariableHolder $vars
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 *
	 * @return string
	 */
	private static function getStashKey(
		BagOStuff $cache, AbuseFilterVariableHolder $vars, $group
	) {
		$inputVars = $vars->exportNonLazyVars();
		// Exclude noisy fields that have superficial changes
		unset( $inputVars['old_html'] );
		unset( $inputVars['new_html'] );
		unset( $inputVars['user_age'] );
		unset( $inputVars['timestamp'] );
		unset( $inputVars['_VIEWS'] );
		ksort( $inputVars );
		$hash = md5( serialize( $inputVars ) );

		return $cache->makeKey(
			'abusefilter',
			'check-stash',
			$group,
			$hash,
			'v1'
		);
	}

	/**
	 * @param array[] $actions_taken
	 * @param array $log_template
	 * @param string $action
	 * @param AbuseFilterVariableHolder $vars
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @return mixed
	 */
	public static function addLogEntries( $actions_taken, $log_template, $action,
		$vars, $group = 'default'
	) {
		$dbw = wfGetDB( DB_MASTER );

		$central_log_template = [
			'afl_wiki' => wfWikiID(),
		];

		$log_rows = [];
		$central_log_rows = [];
		$logged_local_filters = [];
		$logged_global_filters = [];

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
			return;
		}

		// Only store the var dump if we're actually going to add log rows.
		$var_dump = self::storeVarDump( $vars );
		$var_dump = "stored-text:$var_dump"; // To distinguish from stuff stored directly

		$stash = ObjectCache::getMainStashInstance();

		// Increment trigger counter
		$stash->incr( self::filterMatchesKey() );

		$local_log_ids = [];
		global $wgAbuseFilterNotifications, $wgAbuseFilterNotificationsPrivate;
		foreach ( $log_rows as $data ) {
			$data['afl_var_dump'] = $var_dump;
			$data['afl_id'] = $dbw->nextSequenceValue( 'abuse_filter_log_afl_id_seq' );
			$dbw->insert( 'abuse_filter_log', $data, __METHOD__ );
			$local_log_ids[] = $data['afl_id'] = $dbw->insertId();
			// Give grep a chance to find the usages:
			// logentry-abusefilter-hit
			$entry = new ManualLogEntry( 'abusefilter', 'hit' );
			// Construct a user object
			$user = User::newFromId( $data['afl_user'] );
			$user->setName( $data['afl_user_text'] );
			$entry->setPerformer( $user );
			// Set action target
			$entry->setTarget( Title::makeTitle( $data['afl_namespace'], $data['afl_title'] ) );
			// Additional info
			$entry->setParameters( [
				'action' => $data['afl_action'],
				'filter' => $data['afl_filter'],
				'actions' => $data['afl_actions'],
				'log' => $data['afl_id'],
			] );

			// Send data to CheckUser if installed and we
			// aren't already sending a notification to recentchanges
			if ( is_callable( 'CheckUserHooks::updateCheckUserData' )
				&& strpos( $wgAbuseFilterNotifications, 'rc' ) === false
			) {
				$rc = $entry->getRecentChange();
				CheckUserHooks::updateCheckUserData( $rc );
			}

			if ( $wgAbuseFilterNotifications !== false ) {
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
				function () use ( $dbw, $logged_local_filters, $method ) {
					$dbw->update( 'abuse_filter',
						[ 'af_hit_count=af_hit_count+1' ],
						[ 'af_id' => $logged_local_filters ],
						$method
					);
				}
			);
		}

		$global_log_ids = [];

		// Global stuff
		if ( count( $logged_global_filters ) ) {
			$vars->computeDBVars();
			$global_var_dump = self::storeVarDump( $vars, true );
			$global_var_dump = "stored-text:$global_var_dump";
			foreach ( $central_log_rows as $index => $data ) {
				$central_log_rows[$index]['afl_var_dump'] = $global_var_dump;
			}

			global $wgAbuseFilterCentralDB;
			$fdb = wfGetDB( DB_MASTER, [], $wgAbuseFilterCentralDB );

			foreach ( $central_log_rows as $row ) {
				$fdb->insert( 'abuse_filter_log', $row, __METHOD__ );
				$global_log_ids[] = $dbw->insertId();
			}

			$fdb->onTransactionPreCommitOrIdle(
				function () use ( $fdb, $logged_global_filters, $method ) {
					$fdb->update( 'abuse_filter',
						[ 'af_hit_count=af_hit_count+1' ],
						[ 'af_id' => $logged_global_filters ],
						$method
					);
				}
			);
		}

		$vars->setVar( 'global_log_ids', $global_log_ids );
		$vars->setVar( 'local_log_ids', $local_log_ids );

		// Check for emergency disabling.
		$total = $stash->get( self::filterUsedKey( $group ) );
		self::checkEmergencyDisable( $group, $logged_local_filters, $total );
	}

	/**
	 * Store a var dump to External Storage or the text table
	 * Some of this code is stolen from Revision::insertOn and friends
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param bool $global
	 *
	 * @return int|null
	 */
	public static function storeVarDump( $vars, $global = false ) {
		global $wgCompressRevisions;

		// Get all variables yet set and compute old and new wikitext if not yet done
		// as those are needed for the diff view on top of the abuse log pages
		$vars = $vars->dumpAllVars( [ 'old_wikitext', 'new_wikitext' ] );

		// Vars is an array with native PHP data types (non-objects) now
		$text = serialize( $vars );
		$flags = [ 'nativeDataArray' ];

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
				return null;
			}
		}

		// Store to text table
		if ( $global ) {
			$dbw = wfGetDB( DB_MASTER, [], $wgAbuseFilterCentralDB );
		} else {
			$dbw = wfGetDB( DB_MASTER );
		}
		$old_id = $dbw->nextSequenceValue( 'text_old_id_seq' );
		$dbw->insert( 'text',
			[
				'old_id' => $old_id,
				'old_text' => $text,
				'old_flags' => implode( ',', $flags ),
			], __METHOD__
		);

		return $dbw->insertId();
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
		// Back-compat
		if ( substr( $stored_dump, 0, strlen( 'stored-text:' ) ) !== 'stored-text:' ) {
			$data = unserialize( $stored_dump );
			if ( is_array( $data ) ) {
				$vh = new AbuseFilterVariableHolder;
				foreach ( $data as $name => $value ) {
					$vh->setVar( $name, $value );
				}

				return $vh;
			} else {
				return $data;
			}
		}

		$text_id = substr( $stored_dump, strlen( 'stored-text:' ) );

		$dbr = wfGetDB( DB_REPLICA );

		$text_row = $dbr->selectRow(
			'text',
			[ 'old_text', 'old_flags' ],
			[ 'old_id' => $text_id ],
			__METHOD__
		);

		if ( !$text_row ) {
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
			foreach ( $vars as $key => $value ) {
				$obj->setVar( $key, $value );
			}
		}

		return $obj;
	}

	/**
	 * @param string $action
	 * @param array $parameters
	 * @param Title $title
	 * @param AbuseFilterVariableHolder $vars
	 * @param string $rule_desc
	 * @param int|string $rule_number
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
		$vars, $rule_desc, $rule_number ) {
		global $wgAbuseFilterCustomActionsHandlers, $wgRequest;

		$message = null;

		switch ( $action ) {
			case 'disallow':
				if ( strlen( $parameters[0] ) ) {
					$message = [ $parameters[0], $rule_desc, $rule_number ];
				} else {
					// Generic message.
					$message = [
						'abusefilter-disallowed',
						$rule_desc,
						$rule_number
					];
				}
				break;

			case 'block':
				global $wgAbuseFilterBlockDuration, $wgAbuseFilterAnonBlockDuration, $wgUser;
				if ( $wgUser->isAnon() && $wgAbuseFilterAnonBlockDuration !== null ) {
					// The user isn't logged in and the anon block duration
					// doesn't default to $wgAbuseFilterBlockDuration.
					$expiry = $wgAbuseFilterAnonBlockDuration;
				} else {
					$expiry = $wgAbuseFilterBlockDuration;
				}

				self::doAbuseFilterBlock(
					[
						'desc' => $rule_desc,
						'number' => $rule_number
					],
					$wgUser->getName(),
					$expiry,
					true
				);

				$message = [
					'abusefilter-blocked-display',
					$rule_desc,
					$rule_number
				];
				break;
			case 'rangeblock':
				self::doAbuseFilterBlock(
					[
						'desc' => $rule_desc,
						'number' => $rule_number
					],
					IP::sanitizeRange( $wgRequest->getIP() . '/16' ),
					'1 week',
					false
				);

				$message = [
					'abusefilter-blocked-display',
					$rule_desc,
					$rule_number
				];
				break;
			case 'degroup':
				global $wgUser;
				if ( !$wgUser->isAnon() ) {
					// Remove all groups from the user. Ouch.
					$groups = $wgUser->getGroups();

					foreach ( $groups as $group ) {
						$wgUser->removeGroup( $group );
					}

					$message = [
						'abusefilter-degrouped',
						$rule_desc,
						$rule_number
					];

					// Don't log it if there aren't any groups being removed!
					if ( !count( $groups ) ) {
						break;
					}

					// Log it.
					$log = new LogPage( 'rights' );

					$log->addEntry( 'rights',
						$wgUser->getUserPage(),
						wfMessage(
							'abusefilter-degroupreason',
							$rule_desc,
							$rule_number
						)->inContentLanguage()->text(),
						[
							implode( ', ', $groups ),
							''
						],
						self::getFilterUser()
					);
				}

				break;
			case 'blockautopromote':
				global $wgUser;
				if ( !$wgUser->isAnon() ) {
					$blockPeriod = (int)mt_rand( 3 * 86400, 7 * 86400 ); // Block for 3-7 days.
					ObjectCache::getMainStashInstance()->set(
						self::autoPromoteBlockKey( $wgUser ), true, $blockPeriod
					);

					$message = [
						'abusefilter-autopromote-blocked',
						$rule_desc,
						$rule_number
					];
				}
				break;

			case 'flag':
				// Do nothing. Here for completeness.
				break;

			case 'tag':
				// Mark with a tag on recentchanges.
				global $wgUser;

				$actionID = implode( '-', [
					$title->getPrefixedText(), $wgUser->getName(),
					$vars->getVar( 'ACTION' )->toString()
				] );

				self::bufferTagsToSetByAction( [ $actionID => $parameters ] );
				break;
			default:
				if ( isset( $wgAbuseFilterCustomActionsHandlers[$action] ) ) {
					$custom_function = $wgAbuseFilterCustomActionsHandlers[$action];
					if ( is_callable( $custom_function ) ) {
						$msg = call_user_func(
							$custom_function,
							$action,
							$parameters,
							$title,
							$vars,
							$rule_desc,
							$rule_number
						);
					}
					if ( isset( $msg ) ) {
						$message = [ $msg ];
					}
				} else {
					wfDebugLog( 'AbuseFilter', "Unrecognised action $action" );
				}
		}

		return $message;
	}

	/**
	 * @param array[] $tagsByAction Map of (integer => string[])
	 */
	private static function bufferTagsToSetByAction( array $tagsByAction ) {
		foreach ( $tagsByAction as $actionID => $tags ) {
			if ( !isset( self::$tagsToSet[$actionID] ) ) {
				self::$tagsToSet[$actionID] = $tags;
			} else {
				self::$tagsToSet[$actionID] = array_merge( self::$tagsToSet[$actionID], $tags );
			}
		}
	}

	/**
	 * Perform a block by the AbuseFilter system user
	 * @param array $rule should have 'desc' and 'number'
	 * @param string $target
	 * @param string $expiry
	 * @param bool $isAutoBlock
	 */
	protected static function doAbuseFilterBlock( array $rule, $target, $expiry, $isAutoBlock ) {
		$filterUser = self::getFilterUser();
		$reason = wfMessage(
			'abusefilter-blockreason',
			$rule['desc'], $rule['number']
		)->inContentLanguage()->text();

		$block = new Block();
		$block->setTarget( $target );
		$block->setBlocker( $filterUser );
		$block->mReason = $reason;
		$block->isHardblock( false );
		$block->isAutoblocking( $isAutoBlock );
		$block->prevents( 'createaccount', true );
		$block->prevents( 'editownusertalk', false );
		$block->mExpiry = SpecialBlock::parseExpiryInput( $expiry );

		$success = $block->insert();

		if ( $success ) {
			// Log it only if the block was successful
			$logParams = [];
			$logParams['5::duration'] = ( $block->mExpiry === 'infinity' )
				? 'indefinite'
				: $expiry;
			$flags = [ 'nocreate' ];
			if ( !$block->isAutoblocking() && !IP::isIPAddress( $target ) ) {
				// Conditionally added same as SpecialBlock
				$flags[] = 'noautoblock';
			}
			$logParams['6::flags'] = implode( ',', $flags );

			$logEntry = new ManualLogEntry( 'block', 'block' );
			$logEntry->setTarget( Title::makeTitle( NS_USER, $target ) );
			$logEntry->setComment( $reason );
			$logEntry->setPerformer( $filterUser );
			$logEntry->setParameters( $logParams );
			$blockIds = array_merge( [ $success['id'] ], $success['autoIds'] );
			$logEntry->setRelations( [ 'ipb_id' => $blockIds ] );
			$logEntry->publish( $logEntry->insert() );
		}
	}

	/**
	 * @param $throttleId
	 * @param $types
	 * @param Title $title
	 * @param string $rateCount
	 * @param string $ratePeriod
	 * @param bool $global
	 * @return bool
	 */
	public static function isThrottled( $throttleId, $types, $title, $rateCount,
		$ratePeriod, $global = false
	) {
		$stash = ObjectCache::getMainStashInstance();
		$key = self::throttleKey( $throttleId, $types, $title, $global );
		$count = intval( $stash->get( $key ) );

		wfDebugLog( 'AbuseFilter', "Got value $count for throttle key $key\n" );

		if ( $count > 0 ) {
			$stash->incr( $key );
			$count++;
			wfDebugLog( 'AbuseFilter', "Incremented throttle key $key" );
		} else {
			wfDebugLog( 'AbuseFilter', "Added throttle key $key with value 1" );
			$stash->add( $key, 1, $ratePeriod );
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
	 * @param string $type
	 * @param Title $title
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
	 * @param string $type
	 * @param Title $title
	 * @param bool $global
	 * @return string
	 */
	public static function throttleKey( $throttleId, $type, $title, $global = false ) {
		$types = explode( ',', $type );

		$identifiers = [];

		foreach ( $types as $subtype ) {
			$identifiers[] = self::throttleIdentifier( $subtype, $title );
		}

		$identifier = sha1( implode( ':', $identifiers ) );

		global $wgAbuseFilterIsCentral, $wgAbuseFilterCentralDB;

		if ( $global && !$wgAbuseFilterIsCentral ) {
			list( $globalSite, $globalPrefix ) = wfSplitWikiID( $wgAbuseFilterCentralDB );

			return wfForeignMemcKey(
				$globalSite, $globalPrefix,
				'abusefilter', 'throttle', $throttleId, $type, $identifier );
		}

		return wfMemcKey( 'abusefilter', 'throttle', $throttleId, $type, $identifier );
	}

	/**
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @return string
	 */
	public static function getGlobalRulesKey( $group ) {
		global $wgAbuseFilterIsCentral, $wgAbuseFilterCentralDB;

		if ( !$wgAbuseFilterIsCentral ) {
			list( $globalSite, $globalPrefix ) = wfSplitWikiID( $wgAbuseFilterCentralDB );

			return wfForeignMemcKey(
				$globalSite, $globalPrefix,
				'abusefilter', 'rules', $group
			);
		}

		return wfMemcKey( 'abusefilter', 'rules', $group );
	}

	/**
	 * @param User $user
	 * @return string
	 */
	public static function autoPromoteBlockKey( $user ) {
		return wfMemcKey( 'abusefilter', 'block-autopromote', $user->getId() );
	}

	/**
	 * Update statistics, and disable filters which are over-blocking.
	 * @param bool[] $filters
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 */
	public static function recordStats( $filters, $group = 'default' ) {
		global $wgAbuseFilterConditionLimit;

		$stash = ObjectCache::getMainStashInstance();

		// Figure out if we've triggered overflows and blocks.
		$overflow_triggered = ( self::$condCount > $wgAbuseFilterConditionLimit );

		// Store some keys...
		$overflow_key = self::filterLimitReachedKey();
		$total_key = self::filterUsedKey( $group );

		$total = $stash->get( $total_key );

		$storage_period = self::$statsStoragePeriod;

		if ( !$total || $total > 10000 ) {
			// This is for if the total doesn't exist, or has gone past 10,000.
			// Recreate all the keys at the same time, so they expire together.
			$stash->set( $total_key, 0, $storage_period );
			$stash->set( $overflow_key, 0, $storage_period );

			foreach ( $filters as $filter => $matched ) {
				$stash->set( self::filterMatchesKey( $filter ), 0, $storage_period );
			}
			$stash->set( self::filterMatchesKey(), 0, $storage_period );
		}

		// Increment total
		$stash->incr( $total_key );

		// Increment overflow counter, if our condition limit overflowed
		if ( $overflow_triggered ) {
			$stash->incr( $overflow_key );
		}
	}

	/**
	 * Record runtime profiling data
	 *
	 * @param int $totalFilters
	 * @param int $totalConditions
	 * @param float $runtime
	 */
	private static function recordRuntimeProfilingResult( $totalFilters, $totalConditions, $runtime ) {
		$keyPrefix = 'abusefilter.runtime-profile.' . wfWikiID() . '.';

		$statsd = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$statsd->timing( $keyPrefix . 'runtime', $runtime );
		$statsd->timing( $keyPrefix . 'total_filters', $totalFilters );
		$statsd->timing( $keyPrefix . 'total_conditions', $totalConditions );
	}

	/**
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @param string[] $filters
	 * @param int $total
	 */
	public static function checkEmergencyDisable( $group, $filters, $total ) {
		global $wgAbuseFilterEmergencyDisableThreshold, $wgAbuseFilterEmergencyDisableCount,
			$wgAbuseFilterEmergencyDisableAge;

		$stash = ObjectCache::getMainStashInstance();
		foreach ( $filters as $filter ) {
			// determine emergency disable values for this action
			$emergencyDisableThreshold =
				self::getEmergencyValue( $wgAbuseFilterEmergencyDisableThreshold, $group );
			$filterEmergencyDisableCount =
				self::getEmergencyValue( $wgAbuseFilterEmergencyDisableCount, $group );
			$emergencyDisableAge =
				self::getEmergencyValue( $wgAbuseFilterEmergencyDisableAge, $group );

			// Increment counter
			$matchCount = $stash->get( self::filterMatchesKey( $filter ) );

			// Handle missing keys...
			if ( !$matchCount ) {
				$stash->set( self::filterMatchesKey( $filter ), 1, self::$statsStoragePeriod );
			} else {
				$stash->incr( self::filterMatchesKey( $filter ) );
			}
			$matchCount++;

			// Figure out if the filter is subject to being deleted.
			$filter_age = wfTimestamp( TS_UNIX, self::getFilter( $filter )->af_timestamp );
			$throttle_exempt_time = $filter_age + $emergencyDisableAge;

			if ( $total && $throttle_exempt_time > time()
				&& $matchCount > $filterEmergencyDisableCount
				&& ( $matchCount / $total ) > $emergencyDisableThreshold
			) {
				// More than $wgAbuseFilterEmergencyDisableCount matches,
				// constituting more than $emergencyDisableThreshold
				// (a fraction) of last few edits. Disable it.
				DeferredUpdates::addUpdate(
					new AutoCommitUpdate(
						wfGetDB( DB_MASTER ),
						__METHOD__,
						function ( IDatabase $dbw, $fname ) use ( $filter ) {
							$dbw->update( 'abuse_filter',
								[ 'af_throttled' => 1 ],
								[ 'af_id' => $filter ],
								$fname
							);
						}
					)
				);
			}
		}
	}

	/**
	 * @param array $emergencyValue
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @return mixed
	 */
	public static function getEmergencyValue( array $emergencyValue, $group ) {
		return isset( $emergencyValue[$group] ) ? $emergencyValue[$group] : $emergencyValue['default'];
	}

	/**
	 * @return string
	 */
	public static function filterLimitReachedKey() {
		return wfMemcKey( 'abusefilter', 'stats', 'overflow' );
	}

	/**
	 * @param string|null $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @return string
	 */
	public static function filterUsedKey( $group = null ) {
		return wfMemcKey( 'abusefilter', 'stats', 'total', $group );
	}

	/**
	 * @param string|null $filter
	 * @return string
	 */
	public static function filterMatchesKey( $filter = null ) {
		return wfMemcKey( 'abusefilter', 'stats', 'matches', $filter );
	}

	/**
	 * @return User
	 */
	public static function getFilterUser() {
		$username = wfMessage( 'abusefilter-blocker' )->inContentLanguage()->text();
		$user = User::newSystemUser( $username, [ 'steal' => true ] );

		// Promote user to 'sysop' so it doesn't look
		// like an unprivileged account is blocking users
		if ( !in_array( 'sysop', $user->getGroups() ) ) {
			$user->addGroup( 'sysop' );
		}

		return $user;
	}

	/**
	 * @param string $rules
	 * @param string $textName
	 * @param bool $addResultDiv
	 * @param bool $canEdit
	 * @return string
	 */
	static function buildEditBox( $rules, $textName = 'wpFilterRules', $addResultDiv = true,
		$canEdit = true ) {
		global $wgOut;

		$textareaAttrib = [ 'dir' => 'ltr' ]; # Rules are in English
		if ( !$canEdit ) {
			$textareaAttrib['readonly'] = 'readonly';
		}

		global $wgUser;
		$noTestAttrib = [];
		if ( !$wgUser->isAllowed( 'abusefilter-modify' ) ) {
			$noTestAttrib['disabled'] = 'disabled';
			$addResultDiv = false;
		}

		$rules = rtrim( $rules ) . "\n";
		$rules = Xml::textarea( $textName, $rules, 40, 15, $textareaAttrib );

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
						[ 'label' => wfMessage( "abusefilter-edit-builder-group-$group" )->text() ]
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
					[ 'id' => 'wpFilterBuilder', ],
					$builder
				) . ' ';

			// Add syntax checking
			$rules .= Xml::element( 'input',
				[
					'type' => 'button',
					'value' => wfMessage( 'abusefilter-edit-check' )->text(),
					'id' => 'mw-abusefilter-syntaxcheck'
				] + $noTestAttrib );
		}

		if ( $addResultDiv ) {
			$rules .= Xml::element( 'div',
				[ 'id' => 'mw-abusefilter-syntaxresult', 'style' => 'display: none;' ],
				'&#160;' );
		}

		// Add script
		$wgOut->addModules( 'ext.abuseFilter.edit' );
		self::$editboxName = $textName;

		return $rules;
	}

	/**
	 * Each version is expected to be an array( $row, $actions )
	 * Returns an array of fields that are different.
	 *
	 * @param array $version_1
	 * @param array $version_2
	 *
	 * @return array
	 */
	static function compareVersions( $version_1, $version_2 ) {
		$compareFields = [
			'af_public_comments',
			'af_pattern',
			'af_comments',
			'af_deleted',
			'af_enabled',
			'af_hidden',
			'af_global',
			'af_group',
		];
		$differences = [];

		list( $row1, $actions1 ) = $version_1;
		list( $row2, $actions2 ) = $version_2;

		foreach ( $compareFields as $field ) {
			if ( !isset( $row2->$field ) || $row1->$field != $row2->$field ) {
				$differences[] = $field;
			}
		}

		global $wgAbuseFilterActions;
		foreach ( array_filter( $wgAbuseFilterActions ) as $action => $_ ) {
			if ( !isset( $actions1[$action] ) && !isset( $actions2[$action] ) ) {
				// They're both unset
			} elseif ( isset( $actions1[$action] ) && isset( $actions2[$action] ) ) {
				// They're both set.
				if ( array_diff( $actions1[$action]['parameters'],
					$actions2[$action]['parameters'] ) ) {
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
	 * @param stdClass $row
	 * @return array
	 */
	static function translateFromHistory( $row ) {
		# Translate into an abuse_filter row with some black magic.
		# This is ever so slightly evil!
		$af_row = new stdClass;

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
		$actions_output = [];
		if ( is_array( $actions_raw ) ) {
			foreach ( $actions_raw as $action => $parameters ) {
				$actions_output[$action] = [
					'action' => $action,
					'parameters' => $parameters
				];
			}
		}

		return [ $af_row, $actions_output ];
	}

	/**
	 * @param string $action
	 * @return string
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
	 * @param stdClass $row
	 * @return AbuseFilterVariableHolder|null
	 */
	public static function getVarsFromRCRow( $row ) {
		if ( $row->rc_log_type == 'move' ) {
			$vars = self::getMoveVarsFromRCRow( $row );
		} elseif ( $row->rc_log_type == 'newusers' ) {
			$vars = self::getCreateVarsFromRCRow( $row );
		} elseif ( $row->rc_log_type == 'delete' ) {
			$vars = self::getDeleteVarsFromRCRow( $row );
		} elseif ( $row->rc_this_oldid ) {
			// It's an edit.
			$vars = self::getEditVarsFromRCRow( $row );
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
	 * @param stdClass $row
	 * @return AbuseFilterVariableHolder
	 */
	public static function getCreateVarsFromRCRow( $row ) {
		$vars = new AbuseFilterVariableHolder;

		$vars->setVar( 'ACTION', ( $row->rc_log_action == 'autocreate' ) ?
			'autocreateaccount' :
			'createaccount' );

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
	 * @param stdClass $row
	 * @return AbuseFilterVariableHolder
	 */
	public static function getDeleteVarsFromRCRow( $row ) {
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

		$vars->setVar( 'ACTION', 'delete' );
		if ( class_exists( CommentStore::class ) ) {
			$vars->setVar( 'SUMMARY', CommentStore::newKey( 'rc_comment' )
				// $row comes from RecentChange::selectFields()
				->getCommentLegacy( wfGetDB( DB_REPLICA ), $row )->text
			);
		} else {
			$vars->setVar( 'SUMMARY', $row->rc_comment );
		}

		return $vars;
	}

	/**
	 * @param stdClass $row
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
		if ( class_exists( CommentStore::class ) ) {
			$vars->setVar( 'SUMMARY', CommentStore::newKey( 'rc_comment' )
				// $row comes from RecentChange::selectFields()
				->getCommentLegacy( wfGetDB( DB_REPLICA ), $row )->text
			);
		} else {
			$vars->setVar( 'SUMMARY', $row->rc_comment );
		}

		$vars->setLazyLoadVar( 'new_wikitext', 'revision-text-by-id',
			[ 'revid' => $row->rc_this_oldid ] );

		if ( $row->rc_last_oldid ) {
			$vars->setLazyLoadVar( 'old_wikitext', 'revision-text-by-id',
				[ 'revid' => $row->rc_last_oldid ] );
		} else {
			$vars->setVar( 'old_wikitext', '' );
		}

		$vars->addHolders( self::getEditVars( $title ) );

		return $vars;
	}

	/**
	 * @param stdClass $row
	 * @return AbuseFilterVariableHolder
	 */
	public static function getMoveVarsFromRCRow( $row ) {
		if ( $row->rc_user ) {
			$user = User::newFromId( $row->rc_user );
		} else {
			$user = new User;
			$user->setName( $row->rc_user_text );
		}

		$params = array_values( DatabaseLogEntry::newFromRow( $row )->getParameters() );

		$oldTitle = Title::makeTitle( $row->rc_namespace, $row->rc_title );
		$newTitle = Title::newFromText( $params[0] );

		$vars = AbuseFilterVariableHolder::merge(
			self::generateUserVars( $user ),
			self::generateTitleVars( $oldTitle, 'MOVED_FROM' ),
			self::generateTitleVars( $newTitle, 'MOVED_TO' )
		);

		if ( class_exists( CommentStore::class ) ) {
			$vars->setVar( 'SUMMARY', CommentStore::newKey( 'rc_comment' )
				// $row comes from RecentChange::selectFields()
				->getCommentLegacy( wfGetDB( DB_REPLICA ), $row )->text
			);
		} else {
			$vars->setVar( 'SUMMARY', $row->rc_comment );
		}
		$vars->setVar( 'ACTION', 'move' );

		return $vars;
	}

	/**
	 * @param Title $title
	 * @param Page|null $page
	 * @return AbuseFilterVariableHolder
	 */
	public static function getEditVars( $title, Page $page = null ) {
		$vars = new AbuseFilterVariableHolder;

		// NOTE: $page may end up remaining null, e.g. if $title points to a special page.
		if ( !$page && $title instanceof Title && $title->canExist() ) {
			$page = WikiPage::factory( $title );
		}

		$vars->setLazyLoadVar( 'edit_diff', 'diff',
			[ 'oldtext-var' => 'old_wikitext', 'newtext-var' => 'new_wikitext' ] );
		$vars->setLazyLoadVar( 'edit_diff_pst', 'diff',
			[ 'oldtext-var' => 'old_wikitext', 'newtext-var' => 'new_pst' ] );
		$vars->setLazyLoadVar( 'new_size', 'length', [ 'length-var' => 'new_wikitext' ] );
		$vars->setLazyLoadVar( 'old_size', 'length', [ 'length-var' => 'old_wikitext' ] );
		$vars->setLazyLoadVar( 'edit_delta', 'subtract',
			[ 'val1-var' => 'new_size', 'val2-var' => 'old_size' ] );

		// Some more specific/useful details about the changes.
		$vars->setLazyLoadVar( 'added_lines', 'diff-split',
			[ 'diff-var' => 'edit_diff', 'line-prefix' => '+' ] );
		$vars->setLazyLoadVar( 'removed_lines', 'diff-split',
			[ 'diff-var' => 'edit_diff', 'line-prefix' => '-' ] );
		$vars->setLazyLoadVar( 'added_lines_pst', 'diff-split',
			[ 'diff-var' => 'edit_diff_pst', 'line-prefix' => '+' ] );

		// Links
		$vars->setLazyLoadVar( 'added_links', 'link-diff-added',
			[ 'oldlink-var' => 'old_links', 'newlink-var' => 'all_links' ] );
		$vars->setLazyLoadVar( 'removed_links', 'link-diff-removed',
			[ 'oldlink-var' => 'old_links', 'newlink-var' => 'all_links' ] );
		$vars->setLazyLoadVar( 'new_text', 'strip-html',
			[ 'html-var' => 'new_html' ] );
		$vars->setLazyLoadVar( 'old_text', 'strip-html',
			[ 'html-var' => 'old_html' ] );

		if ( $title instanceof Title ) {
			$vars->setLazyLoadVar( 'all_links', 'links-from-wikitext',
				[
					'namespace' => $title->getNamespace(),
					'title' => $title->getText(),
					'text-var' => 'new_wikitext',
					'article' => $page
				] );
			$vars->setLazyLoadVar( 'old_links', 'links-from-wikitext-or-database',
				[
					'namespace' => $title->getNamespace(),
					'title' => $title->getText(),
					'text-var' => 'old_wikitext'
				] );
			$vars->setLazyLoadVar( 'new_pst', 'parse-wikitext',
				[
					'namespace' => $title->getNamespace(),
					'title' => $title->getText(),
					'wikitext-var' => 'new_wikitext',
					'article' => $page,
					'pst' => true,
				] );
			$vars->setLazyLoadVar( 'new_html', 'parse-wikitext',
				[
					'namespace' => $title->getNamespace(),
					'title' => $title->getText(),
					'wikitext-var' => 'new_wikitext',
					'article' => $page
				] );
			$vars->setLazyLoadVar( 'old_html', 'parse-wikitext-nonedit',
				[
					'namespace' => $title->getNamespace(),
					'title' => $title->getText(),
					'wikitext-var' => 'old_wikitext'
				] );
		}

		return $vars;
	}

	/**
	 * @param AbuseFilterVariableHolder|array $vars
	 * @param IContextSource $context
	 * @return string
	 */
	public static function buildVarDumpTable( $vars, IContextSource $context ) {
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
			Xml::openElement( 'table', [ 'class' => 'mw-abuselog-details' ] ) .
			Xml::openElement( 'tbody' ) .
			"\n";

		$header =
			Xml::element( 'th', null, $context->msg( 'abusefilter-log-details-var' )->text() ) .
			Xml::element( 'th', null, $context->msg( 'abusefilter-log-details-val' )->text() );
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
				$keyDisplay = $context->msg( "abusefilter-edit-builder-vars-$mapping" )->parse() .
					' ' . Xml::element( 'code', null, $context->msg( 'parentheses', $key )->text() );
			} else {
				$keyDisplay = Xml::element( 'code', null, $key );
			}

			if ( is_null( $value ) ) {
				$value = '';
			}
			$value = Xml::element( 'div', [ 'class' => 'mw-abuselog-var-value' ], $value, false );

			$trow =
				Xml::tags( 'td', [ 'class' => 'mw-abuselog-var' ], $keyDisplay ) .
				Xml::tags( 'td', [ 'class' => 'mw-abuselog-var-value' ], $value );
			$output .=
				Xml::tags( 'tr',
					[ 'class' => "mw-abuselog-details-$key mw-abuselog-value" ], $trow
				) . "\n";
		}

		$output .= Xml::closeElement( 'tbody' ) . Xml::closeElement( 'table' );

		return $output;
	}

	/**
	 * @param string $action
	 * @param string[] $parameters
	 * @return string
	 */
	static function formatAction( $action, $parameters ) {
		/** @var $wgLang Language */
		global $wgLang;
		if ( count( $parameters ) == 0 ) {
			$displayAction = self::getActionDisplay( $action );
		} else {
			$displayAction = self::getActionDisplay( $action ) .
				wfMessage( 'colon-separator' )->escaped() .
				$wgLang->semicolonList( $parameters );
		}

		return $displayAction;
	}

	/**
	 * @param string $value
	 * @return string
	 */
	static function formatFlags( $value ) {
		/** @var $wgLang Language */
		global $wgLang;
		$flags = array_filter( explode( ',', $value ) );
		$flags_display = [];
		foreach ( $flags as $flag ) {
			$flags_display[] = wfMessage( "abusefilter-history-$flag" )->text();
		}

		return $wgLang->commaList( $flags_display );
	}

	/**
	 * @param string $filterID
	 * @return string
	 */
	static function getGlobalFilterDescription( $filterID ) {
		global $wgAbuseFilterCentralDB;

		if ( !$wgAbuseFilterCentralDB ) {
			return '';
		}

		static $cache = [];
		if ( isset( $cache[$filterID] ) ) {
			return $cache[$filterID];
		}

		$fdb = wfGetDB( DB_REPLICA, [], $wgAbuseFilterCentralDB );

		$cache[$filterID] = $fdb->selectField(
			'abuse_filter',
			'af_public_comments',
			[ 'af_id' => $filterID ],
			__METHOD__
		);

		return $cache[$filterID];
	}

	/**
	 * Gives either the user-specified name for a group,
	 * or spits the input back out
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @return string A name for that filter group, or the input.
	 */
	static function nameGroup( $group ) {
		// Give grep a chance to find the usages: abusefilter-group-default
		$msg = "abusefilter-group-$group";

		return wfMessage( $msg )->exists() ? wfMessage( $msg )->escaped() : $group;
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
	 * @param int $audience one of:
	 *      Revision::FOR_PUBLIC       to be displayed to all users
	 *      Revision::FOR_THIS_USER    to be displayed to the given user
	 *      Revision::RAW              get the text regardless of permissions
	 * @return string|null the content of the revision as some kind of string,
	 *        or an empty string if it can not be found
	 */
	static function revisionToString( $revision, $audience = Revision::FOR_THIS_USER ) {
		if ( !$revision instanceof Revision ) {
			return '';
		}

		$content = $revision->getContent( $audience );
		if ( $content === null ) {
			return '';
		}
		$result = self::contentToString( $content );

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

		if ( Hooks::run( 'AbuseFilter-contentToString', [ $content, &$text ] ) ) {
			$text = $content instanceof TextContent
				? $content->getNativeData()
				: $content->getTextForSearchIndex();
		}

		if ( is_string( $text ) ) {
			// T22310
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
	 * @param int $filterId Filter id
	 * @return int
	 */
	public static function getFirstFilterChange( $filterID ) {
		static $firstChanges = [];

		if ( !isset( $firstChanges[$filterID] ) ) {
			$dbr = wfGetDB( DB_REPLICA );
			$row = $dbr->selectRow(
				'abuse_filter_history',
				'afh_id',
				[
					'afh_filter' => $filterID,
				],
				__METHOD__,
				[ 'ORDER BY' => 'afh_timestamp ASC' ]
			);
			$firstChanges[$filterID] = $row->afh_id;
		}

		return $firstChanges[$filterID];
	}
}
