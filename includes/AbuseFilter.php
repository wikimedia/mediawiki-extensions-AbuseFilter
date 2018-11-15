<?php

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Session\SessionManager;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

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
	// FIXME: avoid global state here
	public static $tagsToSet = [];

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
			'===' => 'equal-strict',
			'!=' => 'notequal',
			'!==' => 'notequal-strict',
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
			'ccnorm_contains_any(haystack,needle1,needle2,..)' => 'ccnorm-contains-any',
			'ccnorm_contains_all(haystack,needle1,needle2,..)' => 'ccnorm-contains-all',
			'rmdoubles(string)' => 'rmdoubles',
			'specialratio(string)' => 'specialratio',
			'norm(string)' => 'norm',
			'count(needle,haystack)' => 'count',
			'rcount(needle,haystack)' => 'rcount',
			'get_matches(needle,haystack)' => 'get_matches',
			'rmwhitespace(text)' => 'rmwhitespace',
			'rmspecials(text)' => 'rmspecials',
			'ip_in_range(ip, range)' => 'ip_in_range',
			'contains_any(haystack,needle1,needle2,...)' => 'contains-any',
			'contains_all(haystack,needle1,needle2,...)' => 'contains-all',
			'equals_to_any(haystack,needle1,needle2,...)' => 'equals-to-any',
			'substr(subject, offset, length)' => 'substr',
			'strpos(haystack, needle)' => 'strpos',
			'str_replace(subject, search, replace)' => 'str_replace',
			'rescape(string)' => 'rescape',
			'set_var(var,value)' => 'set_var',
			'sanitize(string)' => 'sanitize',
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
			'page_id' => 'page-id',
			'page_namespace' => 'page-ns',
			'page_title' => 'page-title',
			'page_prefixedtitle' => 'page-prefixedtitle',
			'page_age' => 'page-age',
			'moved_from_id' => 'movedfrom-id',
			'moved_from_namespace' => 'movedfrom-ns',
			'moved_from_title' => 'movedfrom-title',
			'moved_from_prefixedtitle' => 'movedfrom-prefixedtitle',
			'moved_from_age' => 'movedfrom-age',
			'moved_to_id' => 'movedto-id',
			'moved_to_namespace' => 'movedto-ns',
			'moved_to_title' => 'movedto-title',
			'moved_to_prefixedtitle' => 'movedto-prefixedtitle',
			'moved_to_age' => 'movedto-age',
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
			'page_restrictions_edit' => 'restrictions-edit',
			'page_restrictions_move' => 'restrictions-move',
			'page_restrictions_create' => 'restrictions-create',
			'page_restrictions_upload' => 'restrictions-upload',
			'page_recent_contributors' => 'recent-contributors',
			'page_first_contributor' => 'first-contributor',
			'moved_from_restrictions_edit' => 'movedfrom-restrictions-edit',
			'moved_from_restrictions_move' => 'movedfrom-restrictions-move',
			'moved_from_restrictions_create' => 'movedfrom-restrictions-create',
			'moved_from_restrictions_upload' => 'movedfrom-restrictions-upload',
			'moved_from_recent_contributors' => 'movedfrom-recent-contributors',
			'moved_from_first_contributor' => 'movedfrom-first-contributor',
			'moved_to_restrictions_edit' => 'movedto-restrictions-edit',
			'moved_to_restrictions_move' => 'movedto-restrictions-move',
			'moved_to_restrictions_create' => 'movedto-restrictions-create',
			'moved_to_restrictions_upload' => 'movedto-restrictions-upload',
			'moved_to_recent_contributors' => 'movedto-recent-contributors',
			'moved_to_first_contributor' => 'movedto-first-contributor',
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

	/** @var array Old vars which aren't in use anymore */
	public static $disabledVars = [
		'old_text' => 'old-text-stripped',
		'old_html' => 'old-html'
	];

	public static $deprecatedVars = [
		'article_text' => 'page_title',
		'article_prefixedtext' => 'page_prefixedtitle',
		'article_namespace' => 'page_namespace',
		'article_articleid' => 'page_id',
		'article_restrictions_edit' => 'page_restrictions_edit',
		'article_restrictions_move' => 'page_restrictions_move',
		'article_restrictions_create' => 'page_restrictions_create',
		'article_restrictions_upload' => 'page_restrictions_upload',
		'article_recent_contributors' => 'page_recent_contributors',
		'article_first_contributor' => 'page_first_contributor',
		'moved_from_text' => 'moved_from_title',
		'moved_from_prefixedtext' => 'moved_from_prefixedtitle',
		'moved_from_articleid' => 'moved_from_id',
		'moved_to_text' => 'moved_to_title',
		'moved_to_prefixedtext' => 'moved_to_prefixedtitle',
		'moved_to_articleid' => 'moved_to_id',
	];

	public static $editboxName = null;

	/**
	 * @param IContextSource $context
	 * @param string $pageType
	 * @param LinkRenderer $linkRenderer
	 */
	public static function addNavigationLinks(
		IContextSource $context,
		$pageType,
		LinkRenderer $linkRenderer
	) {
		$linkDefs = [
			'home' => 'Special:AbuseFilter',
			'recentchanges' => 'Special:AbuseFilter/history',
			'examine' => 'Special:AbuseFilter/examine',
			'log' => 'Special:AbuseLog',
		];

		if ( $context->getUser()->isAllowedAny( 'abusefilter-modify', 'abusefilter-view-private' ) ) {
			$linkDefs = array_merge( $linkDefs, [
				'test' => 'Special:AbuseFilter/test',
				'tools' => 'Special:AbuseFilter/tools'
			] );
		}

		if ( $context->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			$linkDefs = array_merge( $linkDefs, [
				'import' => 'Special:AbuseFilter/import'
			] );
		}

		// Re-use the message
		$msgOverrides = [
			'recentchanges' => 'abusefilter-filter-log',
		];

		$links = [];

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

		$linkStr = $context->msg( 'parentheses' )
			->rawParams( $context->getLanguage()->pipeList( $links ) )
			->text();
		$linkStr = $context->msg( 'abusefilter-topnav' )->parse() . " $linkStr";

		$linkStr = Xml::tags( 'div', [ 'class' => 'mw-abusefilter-navigation' ], $linkStr );

		$context->getOutput()->setSubtitle( $linkStr );
	}

	/**
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

		Hooks::run( 'AbuseFilter-builder', [ &$realValues ] );

		return $realValues;
	}

	/**
	 * @return array
	 */
	public static function getDeprecatedVariables() {
		static $deprecatedVars = null;

		if ( $deprecatedVars ) {
			return $deprecatedVars;
		}

		$deprecatedVars = self::$deprecatedVars;

		Hooks::run( 'AbuseFilter-deprecatedVariables', [ &$deprecatedVars ] );

		return $deprecatedVars;
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
		}
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

	/**
	 * For use in batch scripts and the like
	 */
	public static function disableConditionLimit() {
		self::$condLimitEnabled = false;
	}

	/**
	 * @param Title|null $title
	 * @param string $prefix
	 * @param bool $transition Temporary parameter to help with T173889 and to be removed afterwards
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateTitleVars( $title, $prefix, $transition = true ) {
		$vars = new AbuseFilterVariableHolder;

		if ( !$title ) {
			return $vars;
		}

		// Temporary overrides for T173889, necessary because Flow (and maybe
		// other extensions) still pass old prefix/suffix and thus fail, since
		// hybrid variables are generated (e.g. article_prefixedtitle).
		// Once their variables will be renamed according to the new syntax,
		// we should get rid of these if and just use the new prefix/suffix.
		// Right now, what we want to do is:
		// - Use new prefix/suffix for AF's own variables (they're handled at parser level)
		// - Use old prefix/suffix for external variables (we don't handle them)
		$titleSuffix = 'TITLE';
		if ( $transition && $prefix === 'BOARD' ) {
			$titleSuffix = 'TEXT';
		}
		if ( $transition && $prefix === 'ARTICLE' ) {
			$prefix = 'PAGE';
		}

		$vars->setVar( $prefix . '_ID', $title->getArticleID() );
		$vars->setVar( $prefix . '_NAMESPACE', $title->getNamespace() );
		$vars->setVar( $prefix . "_$titleSuffix", $title->getText() );
		$vars->setVar( $prefix . "_PREFIXED$titleSuffix", $title->getPrefixedText() );

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

		$vars->setLazyLoadVar( "{$prefix}_age", 'page-age',
			[
				'title' => $title->getText(),
				'namespace' => $title->getNamespace(),
				'asof' => wfTimestampNow()
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
	 * @param string $filter
	 * @return true|array True when successful, otherwise a two-element array with exception message
	 *  and character position of the syntax error
	 */
	public static function checkSyntax( $filter ) {
		global $wgAbuseFilterParserClass;

		/** @var $parser AbuseFilterParser */
		$parser = new $wgAbuseFilterParserClass;

		return $parser->checkSyntax( $filter );
	}

	/**
	 * @param string $expr
	 * @return string
	 */
	public static function evaluateExpression( $expr ) {
		global $wgAbuseFilterParserClass;

		if ( self::checkSyntax( $expr ) !== true ) {
			return 'BADSYNTAX';
		}

		/** @var $parser AbuseFilterParser */
		$parser = new $wgAbuseFilterParserClass;

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
			$result = false;

			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->debug( 'AbuseFilter parser error: ' . $excep->getMessage() );

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
	 * @param Title|null $title
	 * @param string $mode 'execute' for edits and logs, 'stash' for cached matches
	 *
	 * @return bool[] Map of (integer filter ID => bool)
	 */
	public static function checkAllFilters(
		$vars,
		$group = 'default',
		Title $title = null,
		$mode = 'execute'
	) {
		global $wgAbuseFilterCentralDB, $wgAbuseFilterIsCentral;
		global $wgAbuseFilterConditionLimit;

		// Ensure that we start fresh, see T193374
		self::$condCount = 0;

		// Fetch filters to check from the database.
		$filter_matched = [];

		$dbr = wfGetDB( DB_REPLICA );
		$fields = [
			'af_id',
			'af_pattern',
			'af_public_comments',
			'af_timestamp'
		];
		$res = $dbr->select(
			'abuse_filter',
			$fields,
			[
				'af_enabled' => 1,
				'af_deleted' => 0,
				'af_group' => $group,
			],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$filter_matched[$row->af_id] = self::checkFilter( $row, $vars, $title, '', $mode );
		}

		if ( $wgAbuseFilterCentralDB && !$wgAbuseFilterIsCentral ) {
			// Global filters
			$globalRulesKey = self::getGlobalRulesKey( $group );

			$fname = __METHOD__;
			$res = ObjectCache::getMainWANInstance()->getWithSetCallback(
				$globalRulesKey,
				WANObjectCache::TTL_INDEFINITE,
				function () use ( $group, $fname, $fields ) {
					global $wgAbuseFilterCentralDB;

					$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
					$fdb = $lbFactory->getMainLB( $wgAbuseFilterCentralDB )->getConnectionRef(
						DB_REPLICA, [], $wgAbuseFilterCentralDB
					);

					return iterator_to_array( $fdb->select(
						'abuse_filter',
						$fields,
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
					self::checkFilter( $row, $vars, $title, 'global-', $mode );
			}
		}

		if ( $title instanceof Title && self::$condCount > $wgAbuseFilterConditionLimit ) {
			$actionID = implode( '-', [
				$title->getPrefixedText(),
				$vars->getVar( 'user_name' )->toString(),
				$vars->getVar( 'action' )->toString()
			] );
			self::bufferTagsToSetByAction( [ $actionID => [ 'abusefilter-condition-limit' ] ] );
		}

		if ( $mode === 'execute' ) {
			// Update statistics, and disable filters which are over-blocking.
			self::recordStats( $filter_matched, $group );
		}

		return $filter_matched;
	}

	/**
	 * @param stdClass $row
	 * @param AbuseFilterVariableHolder $vars
	 * @param Title|null $title
	 * @param string $prefix
	 * @param string $mode 'execute' for edits and logs, 'stash' for cached matches
	 * @return bool
	 */
	public static function checkFilter(
		$row,
		$vars,
		Title $title = null,
		$prefix = '',
		$mode = 'execute'
	) {
		global $wgAbuseFilterProfile, $wgAbuseFilterRuntimeProfile,
			$wgAbuseFilterSlowFilterRuntimeLimit;

		$filterID = $prefix . $row->af_id;

		// Record data to be used if profiling is enabled and mode is 'execute'
		$startConds = self::$condCount;
		$startTime = microtime( true );

		// Store the row somewhere convenient
		self::$filterCache[$filterID] = $row;

		$pattern = trim( $row->af_pattern );
		if (
			self::checkConditions(
				$pattern,
				$vars,
				// Ignore errors
				true
			)
		) {
			// Record match.
			$result = true;
		} else {
			// Record non-match.
			$result = false;
		}

		$timeTaken = microtime( true ) - $startTime;
		$condsUsed = self::$condCount - $startConds;

		if ( $wgAbuseFilterProfile && $mode === 'execute' ) {
			self::recordProfilingResult( $row->af_id, $timeTaken, $condsUsed );
		}

		$runtime = $timeTaken * 1000;
		if ( $mode === 'execute' && $wgAbuseFilterRuntimeProfile &&
			$runtime > $wgAbuseFilterSlowFilterRuntimeLimit ) {
			self::recordSlowFilter( $filterID, $runtime, $condsUsed, $result, $title );
		}

		return $result;
	}

	/**
	 * Logs slow filter's runtime data for later analysis
	 *
	 * @param string $filterId
	 * @param float $runtime
	 * @param int $totalConditions
	 * @param bool $matched
	 * @param Title|null $title
	 */
	private static function recordSlowFilter(
		$filterId, $runtime, $totalConditions, $matched, Title $title = null
	) {
		$title = $title ? $title->getPrefixedText() : '';

		$logger = LoggerFactory::getInstance( 'AbuseFilter' );
		$logger->info(
			'Edit filter {filter_id} on {wiki} is taking longer than expected',
			[
				'wiki' => wfWikiID(),
				'filter_id' => $filterId,
				'title' => $title,
				'runtime' => $runtime,
				'matched' => $matched,
				'total_conditions' => $totalConditions
			]
		);
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

		// 1000 ms in a sec
		$timeProfile = ( $curTotal / $curCount ) * 1000;
		// Return in ms, rounded to 2dp
		$timeProfile = round( $timeProfile, 2 );

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
		if ( strpos( $filter, 'global-' ) === 0 ) {
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
	 * @param IDatabase $dbr
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
				// Don't do the action
			} elseif ( $row->afa_filter != $row->af_id ) {
				// We probably got a NULL, as it's a LEFT JOIN. Don't add it.
			} else {
				$actionsByFilter[$prefix . $row->afa_filter][$row->afa_consequence] = [
					'action' => $row->afa_consequence,
					'parameters' => array_filter( explode( "\n", $row->afa_parameters ) )
				];
			}
		}

		return $actionsByFilter;
	}

	/**
	 * Executes a set of actions.
	 *
	 * @param string[] $filters
	 * @param Title $title
	 * @param AbuseFilterVariableHolder $vars
	 * @param User $user
	 * @return Status returns the operation's status. $status->isOK() will return true if
	 *         there were no actions taken, false otherwise. $status->getValue() will return
	 *         an array listing the actions taken. $status->getErrors() etc. will provide
	 *         the errors and warnings to be shown to the user to explain the actions.
	 */
	public static function executeFilterActions( $filters, $title, $vars, User $user ) {
		global $wgMainCacheType;

		$actionsByFilter = self::getConsequencesForFilters( $filters );
		$actionsTaken = array_fill_keys( $filters, [] );

		$messages = [];
		// Accumulator to track max block to issue
		$maxExpiry = -1;

		global $wgAbuseFilterDisallowGlobalLocalBlocks, $wgAbuseFilterRestrictions,
			$wgAbuseFilterBlockDuration, $wgAbuseFilterAnonBlockDuration;
		foreach ( $actionsByFilter as $filter => $actions ) {
			// Special-case handling for warnings.
			$filter_public_comments = self::getFilter( $filter )->af_public_comments;

			$global_filter = self::decodeGlobalName( $filter ) !== false;

			// If the filter has "throttle" enabled and throttling is available via object
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
				$action = $vars->getVar( 'action' )->toString();
				// Generate a unique key to determine whether the user has already been warned.
				// We'll warn again if one of these changes: session, page, triggered filter or action
				$warnKey = 'abusefilter-warned-' . md5( $title->getPrefixedText() ) .
					'-' . $filter . '-' . $action;

				// Make sure the session is started prior to using it
				$session = SessionManager::getGlobalSession();
				$session->persist();

				if ( !isset( $session[$warnKey] ) || !$session[$warnKey] ) {
					$session[$warnKey] = true;

					// Threaten them a little bit
					if ( isset( $parameters[0] ) ) {
						$msg = $parameters[0];
					} else {
						$msg = 'abusefilter-warning';
					}
					$messages[] = [ $msg, $filter_public_comments, $filter ];

					$actionsTaken[$filter][] = 'warn';

					// Don't do anything else.
					continue;
				} else {
					// We already warned them
					$session[$warnKey] = false;
				}

				unset( $actions['warn'] );
			}

			// Prevent double warnings
			if ( count( array_intersect_key( $actions, array_filter( $wgAbuseFilterRestrictions ) ) ) > 0 &&
				!empty( $actions['disallow'] )
			) {
				unset( $actions['disallow'] );
			}

			// Find out the max expiry to issue the longest triggered block.
			// Need to check here since methods like user->getBlock() aren't available
			if ( !empty( $actions['block'] ) ) {
				$parameters = $actions['block']['parameters'];

				if ( count( $parameters ) === 3 ) {
					// New type of filters with custom block
					if ( $user->isAnon() ) {
						$expiry = $parameters[1];
					} else {
						$expiry = $parameters[2];
					}
				} else {
					// Old type with fixed expiry
					if ( $user->isAnon() && $wgAbuseFilterAnonBlockDuration !== null ) {
						// The user isn't logged in and the anon block duration
						// doesn't default to $wgAbuseFilterBlockDuration.
						$expiry = $wgAbuseFilterAnonBlockDuration;
					} else {
						$expiry = $wgAbuseFilterBlockDuration;
					}
				}

				$currentExpiry = SpecialBlock::parseExpiryInput( $expiry );
				if ( $currentExpiry > SpecialBlock::parseExpiryInput( $maxExpiry ) ) {
					// Save the parameters to issue the block with
					$maxExpiry = $expiry;
					$blockValues = [
						self::getFilter( $filter )->af_public_comments,
						$filter,
						is_array( $parameters ) && in_array( 'blocktalk', $parameters )
					];
				}
				unset( $actions['block'] );
			}

			// Do the rest of the actions
			foreach ( $actions as $action => $info ) {
				$newMsg = self::takeConsequenceAction(
					$action,
					$info['parameters'],
					$title,
					$vars,
					self::getFilter( $filter )->af_public_comments,
					$filter,
					$user
				);

				if ( $newMsg !== null ) {
					$messages[] = $newMsg;
				}
				$actionsTaken[$filter][] = $action;
			}
		}

		// Since every filter has been analysed, we now know what the
		// longest block duration is, so we can issue the block if
		// maxExpiry has been changed.
		if ( $maxExpiry !== -1 ) {
			self::doAbuseFilterBlock(
				[
					'desc' => $blockValues[0],
					'number' => $blockValues[1]
				],
				$user->getName(),
				$maxExpiry,
				true,
				$blockValues[2]
			);
			$message = [
				'abusefilter-blocked-display',
				$blockValues[0],
				$blockValues[1]
			];
			// Manually add the message. If we're here, there is one.
			$messages[] = $message;
			$actionsTaken[ $blockValues[1] ][] = 'block';
		}

		return self::buildStatus( $actionsTaken, $messages );
	}

	/**
	 * Constructs a Status object as returned by executeFilterActions() from the list of
	 * actions taken and the corresponding list of messages.
	 *
	 * @param array[] $actionsTaken associative array mapping each filter to the list if
	 *                actions taken because of that filter.
	 * @param array[] $messages a list of arrays, where each array contains a message key
	 *                followed by any message parameters.
	 *
	 * @return Status
	 */
	protected static function buildStatus( array $actionsTaken, array $messages ) {
		$status = Status::newGood( $actionsTaken );

		foreach ( $messages as $msg ) {
			$status->fatal( ...$msg );
		}

		return $status;
	}

	/**
	 * @param AbuseFilterVariableHolder $vars
	 * @param Title $title
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @param User $user The user performing the action
	 * @param string $mode Use 'execute' to run filters and log or 'stash' to only cache matches
	 * @return Status
	 */
	public static function filterAction(
		AbuseFilterVariableHolder $vars, $title, $group, User $user, $mode = 'execute'
	) {
		global $wgRequest, $wgAbuseFilterRuntimeProfile, $wgAbuseFilterLogIP;

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
			$filter_matched = self::checkAllFilters( $vars, $group, $title, $mode );
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
			$status = self::executeFilterActions( $matched_filters, $title, $vars, $user );
			$actions_taken = $status->getValue();
			$action = $vars->getVar( 'ACTION' )->toString();

			// If $user isn't safe to load (e.g. a failure during
			// AbortAutoAccount), create a dummy anonymous user instead.
			$user = $user->isSafeToLoad() ? $user : new User;

			// Create a template
			$log_template = [
				'afl_user' => $user->getId(),
				'afl_user_text' => $user->getName(),
				'afl_timestamp' => wfGetDB( DB_REPLICA )->timestamp( wfTimestampNow() ),
				'afl_namespace' => $title->getNamespace(),
				'afl_title' => $title->getDBkey(),
				'afl_action' => $action,
				// DB field is not null, so nothing
				'afl_ip' => ( $wgAbuseFilterLogIP ) ? $wgRequest->getIP() : ""
			];

			// Hack to avoid revealing IPs of people creating accounts
			if ( !$user->getId() && ( $action == 'createaccount' || $action == 'autocreateaccount' ) ) {
				$log_template['afl_user_text'] = $vars->getVar( 'accountname' )->toString();
			}

			self::addLogEntries( $actions_taken, $log_template, $vars, $group );
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
					return null;
				}

				$id = $globalIndex;
				$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
				$lb = $lbFactory->getMainLB( $wgAbuseFilterCentralDB );
				$dbr = $lb->getConnectionRef( DB_REPLICA, [], $wgAbuseFilterCentralDB );
			} else {
				// Local wiki filter
				$dbr = wfGetDB( DB_REPLICA );
			}

			$fields = [
				'af_id',
				'af_pattern',
				'af_public_comments',
				'af_timestamp'
			];

			$row = $dbr->selectRow(
				'abuse_filter',
				$fields,
				[ 'af_id' => $id ],
				__METHOD__
			);
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
		$excludedVars = [
			'old_html' => true,
			'new_html' => true,
			'user_age' => true,
			'timestamp' => true,
			'page_age' => true,
			'moved_from_age' => true,
			'moved_to_age' => true
		];

		$inputVars = array_diff_key( $inputVars, $excludedVars );
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
	 * @param AbuseFilterVariableHolder $vars
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 */
	public static function addLogEntries( $actions_taken, $log_template, $vars, $group = 'default' ) {
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
			$thisLog['afl_actions'] = implode( ',', $actions );

			// Don't log if we were only throttling.
			if ( $thisLog['afl_actions'] != 'throttle' ) {
				$log_rows[] = $thisLog;
				// Global logging
				if ( $globalIndex ) {
					$title = Title::makeTitle( $thisLog['afl_namespace'], $thisLog['afl_title'] );
					$centralLog = $thisLog + $central_log_template;
					$centralLog['afl_filter'] = $globalIndex;
					$centralLog['afl_title'] = $title->getPrefixedText();
					$centralLog['afl_namespace'] = 0;

					$central_log_rows[] = $centralLog;
					$logged_global_filters[] = $globalIndex;
				} else {
					$logged_local_filters[] = $filter;
				}
			}
		}

		if ( !count( $log_rows ) ) {
			return;
		}

		// Only store the var dump if we're actually going to add log rows.
		$var_dump = self::storeVarDump( $vars );
		// To distinguish from stuff stored directly
		$var_dump = "stored-text:$var_dump";

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
			if ( ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' )
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
				$global_log_ids[] = $fdb->insertId();
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

		// Store to ExternalStore if applicable
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
	 * @param string $stored_dump
	 *
	 * @return array|object|AbuseFilterVariableHolder|bool
	 */
	public static function loadVarDump( $stored_dump ) {
		// Backward compatibility
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
			// If old variable names are used, make sure to keep them
			if ( count( array_intersect_key( self::getDeprecatedVariables(), $obj->mVars ) ) !== 0 ) {
				$obj->mVarsVersion = 1;
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
	 * @param User $user
	 *
	 * @return array|null a message describing the action that was taken,
	 *         or null if no action was taken. The message is given as an array
	 *         containing the message key followed by any message parameters.
	 */
	public static function takeConsequenceAction( $action, $parameters, $title,
		$vars, $rule_desc, $rule_number, User $user ) {
		global $wgAbuseFilterCustomActionsHandlers, $wgRequest;

		$message = null;

		switch ( $action ) {
			case 'disallow':
				if ( isset( $parameters[0] ) ) {
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
			case 'rangeblock':
				global $wgAbuseFilterRangeBlockSize, $wgBlockCIDRLimit;

				$ip = $wgRequest->getIP();
				if ( IP::isIPv6( $ip ) ) {
					$CIDRsize = max( $wgAbuseFilterRangeBlockSize['IPv6'], $wgBlockCIDRLimit['IPv6'] );
				} else {
					$CIDRsize = max( $wgAbuseFilterRangeBlockSize['IPv4'], $wgBlockCIDRLimit['IPv4'] );
				}
				$blockCIDR = $ip . '/' . $CIDRsize;
				self::doAbuseFilterBlock(
					[
						'desc' => $rule_desc,
						'number' => $rule_number
					],
					IP::sanitizeRange( $blockCIDR ),
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
				if ( !$user->isAnon() ) {
					// Remove all groups from the user.
					$groups = $user->getGroups();
					// Make sure that the stored var dump contains user groups, since we may
					// need them if reverting this degroup via Special:AbuseFilter/revert
					$vars->setVar( 'user_groups', $groups );

					foreach ( $groups as $group ) {
						$user->removeGroup( $group );
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

					$logEntry = new ManualLogEntry( 'rights', 'rights' );
					$logEntry->setPerformer( self::getFilterUser() );
					$logEntry->setTarget( $user->getUserPage() );
					$logEntry->setComment(
						wfMessage(
							'abusefilter-degroupreason',
							$rule_desc,
							$rule_number
						)->inContentLanguage()->text()
					);
					$logEntry->setParameters( [
						'4::oldgroups' => $groups,
						'5::newgroups' => []
					] );
					$logEntry->publish( $logEntry->insert() );
				}

				break;
			case 'blockautopromote':
				if ( !$user->isAnon() ) {
					// Block for 3-7 days.
					$blockPeriod = (int)mt_rand( 3 * 86400, 7 * 86400 );
					ObjectCache::getMainStashInstance()->set(
						self::autoPromoteBlockKey( $user ), true, $blockPeriod
					);

					$message = [
						'abusefilter-autopromote-blocked',
						$rule_desc,
						$rule_number
					];
				}
				break;

			case 'block':
				// Do nothing, handled at the end of executeFilterActions. Here for completeness.
				break;
			case 'flag':
				// Do nothing. Here for completeness.
				break;

			case 'tag':
				// Mark with a tag on recentchanges.
				$actionID = implode( '-', [
					$title->getPrefixedText(), $user->getName(),
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
					$logger = LoggerFactory::getInstance( 'AbuseFilter' );
					$logger->debug( "Unrecognised action $action" );
				}
		}

		return $message;
	}

	/**
	 * @param array[] $tagsByAction Map of (integer => string[])
	 */
	private static function bufferTagsToSetByAction( array $tagsByAction ) {
		global $wgAbuseFilterActions;
		if ( isset( $wgAbuseFilterActions['tag'] ) && $wgAbuseFilterActions['tag'] ) {
			foreach ( $tagsByAction as $actionID => $tags ) {
				if ( !isset( self::$tagsToSet[$actionID] ) ) {
					self::$tagsToSet[$actionID] = $tags;
				} else {
					self::$tagsToSet[$actionID] = array_merge( self::$tagsToSet[$actionID], $tags );
				}
			}
		}
	}

	/**
	 * Perform a block by the AbuseFilter system user
	 * @param array $rule should have 'desc' and 'number'
	 * @param string $target
	 * @param string $expiry
	 * @param bool $isAutoBlock
	 * @param bool $preventEditOwnUserTalk
	 */
	protected static function doAbuseFilterBlock(
		array $rule,
		$target,
		$expiry,
		$isAutoBlock,
		$preventEditOwnUserTalk = false
	) {
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
		$block->prevents( 'editownusertalk', $preventEditOwnUserTalk );
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
			if ( $preventEditOwnUserTalk === true ) {
				$flags[] = 'nousertalk';
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
	 * @param string $throttleId
	 * @param string $types
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

		$logger = LoggerFactory::getInstance( 'AbuseFilter' );
		$logger->debug( "Got value $count for throttle key $key" );

		if ( $count > 0 ) {
			$stash->incr( $key );
			$count++;
			$logger->debug( "Incremented throttle key $key" );
		} else {
			$logger->debug( "Added throttle key $key with value 1" );
			$stash->add( $key, 1, $ratePeriod );
			$count = 1;
		}

		if ( $count > $rateCount ) {
			$logger->debug( "Throttle $key hit value $count -- maximum is $rateCount." );

			// THROTTLED
			return true;
		}

		$logger->debug( "Throttle $key not hit!" );

		// NOT THROTTLED
		return false;
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
	 * @param string $throttleId
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

		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		if ( $global && !$wgAbuseFilterIsCentral ) {
			return $cache->makeGlobalKey(
				'abusefilter', 'throttle', $wgAbuseFilterCentralDB, $throttleId, $type, $identifier
			);
		}

		return $cache->makeKey( 'abusefilter', 'throttle', $throttleId, $type, $identifier );
	}

	/**
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @return string
	 */
	public static function getGlobalRulesKey( $group ) {
		global $wgAbuseFilterIsCentral, $wgAbuseFilterCentralDB;

		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		if ( !$wgAbuseFilterIsCentral ) {
			return $cache->makeGlobalKey( 'abusefilter', 'rules', $wgAbuseFilterCentralDB, $group );
		}

		return $cache->makeKey( 'abusefilter', 'rules', $group );
	}

	/**
	 * @param User $user
	 * @return string
	 */
	public static function autoPromoteBlockKey( $user ) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

		return $cache->makeKey( 'abusefilter', 'block-autopromote', $user->getId() );
	}

	/**
	 * Update statistics, and disable filters which are over-blocking.
	 * @param bool[] $filters
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 */
	public static function recordStats( $filters, $group = 'default' ) {
		global $wgAbuseFilterConditionLimit, $wgAbuseFilterProfileActionsCap;

		$stash = ObjectCache::getMainStashInstance();

		// Figure out if we've triggered overflows and blocks.
		$overflow_triggered = ( self::$condCount > $wgAbuseFilterConditionLimit );

		$overflow_key = self::filterLimitReachedKey();
		$total_key = self::filterUsedKey( $group );

		$total = $stash->get( $total_key );

		$storage_period = self::$statsStoragePeriod;

		if ( !$total || $total > $wgAbuseFilterProfileActionsCap ) {
			// This is for if the total doesn't exist, or has gone past 10,000.
			// Recreate all the keys at the same time, so they expire together.
			$stash->set( $total_key, 0, $storage_period );
			$stash->set( $overflow_key, 0, $storage_period );

			foreach ( $filters as $filter => $matched ) {
				$stash->set( self::filterMatchesKey( $filter ), 0, $storage_period );
			}
			$stash->set( self::filterMatchesKey(), 0, $storage_period );
		}

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
			// Determine emergency disable values for this action
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
		return $emergencyValue[$group] ?? $emergencyValue['default'];
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
	 * Extract values for syntax highlight
	 *
	 * @param bool $canEdit
	 * @return array
	 */
	public static function getAceConfig( $canEdit ) {
		$values = self::getBuilderValues();
		$deprecatedVars = self::getDeprecatedVariables();

		$builderVariables = implode( '|', array_keys( $values['vars'] ) );
		$builderFunctions = implode( '|', array_keys( AbuseFilterParser::$mFunctions ) );
		// AbuseFilterTokenizer::$keywords also includes constants (true, false and null),
		// but Ace redefines these constants afterwards so this will not be an issue
		$builderKeywords = implode( '|', AbuseFilterTokenizer::$keywords );
		// Extract operators from tokenizer like we do in AbuseFilterParserTest
		$operators = implode( '|', array_map( function ( $op ) {
			return preg_quote( $op, '/' );
		}, AbuseFilterTokenizer::$operators ) );
		$deprecatedVariables = implode( '|', array_keys( $deprecatedVars ) );
		$disabledVariables = implode( '|', array_keys( self::$disabledVars ) );

		return [
			'variables' => $builderVariables,
			'functions' => $builderFunctions,
			'keywords' => $builderKeywords,
			'operators' => $operators,
			'deprecated' => $deprecatedVariables,
			'disabled' => $disabledVariables,
			'aceReadOnly' => !$canEdit
		];
	}

	/**
	 * Build input and button for loading a filter
	 *
	 * @return string
	 */
	public static function buildFilterLoader() {
		$loadText =
			new OOUI\TextInputWidget(
				[
					'type' => 'number',
					'name' => 'wpInsertFilter',
					'id' => 'mw-abusefilter-load-filter'
				]
			);
		$loadButton =
			new OOUI\ButtonWidget(
				[
					'label' => wfMessage( 'abusefilter-test-load' )->text(),
					'id' => 'mw-abusefilter-load'
				]
			);
		$loadGroup =
			new OOUI\ActionFieldLayout(
				$loadText,
				$loadButton,
				[
					'label' => wfMessage( 'abusefilter-test-load-filter' )->text()
				]
			);
		// CSS class for reducing default input field width
		$loadDiv =
			Xml::tags(
				'div',
				[ 'class' => 'mw-abusefilter-load-filter-id' ],
				$loadGroup
			);
		return $loadDiv;
	}

	/**
	 * Check whether a filter is allowed to use a tag
	 *
	 * @param string $tag Tag name
	 * @return Status
	 */
	public static function isAllowedTag( $tag ) {
		$tagNameStatus = ChangeTags::isTagNameValid( $tag );

		if ( !$tagNameStatus->isGood() ) {
			return $tagNameStatus;
		}

		$finalStatus = Status::newGood();

		$canAddStatus =
			ChangeTags::canAddTagsAccompanyingChange(
				[ $tag ]
			);

		if ( $canAddStatus->isGood() ) {
			return $finalStatus;
		}

		if ( $tag === 'abusefilter-condition-limit' ) {
			$finalStatus->fatal( 'abusefilter-tag-reserved' );
			return $finalStatus;
		}

		$alreadyDefinedTags = [];
		AbuseFilterHooks::onListDefinedTags( $alreadyDefinedTags );

		if ( in_array( $tag, $alreadyDefinedTags, true ) ) {
			return $finalStatus;
		}

		$canCreateTagStatus = ChangeTags::canCreateTag( $tag );
		if ( $canCreateTagStatus->isGood() ) {
			return $finalStatus;
		}

		$finalStatus->fatal( 'abusefilter-edit-bad-tags' );
		return $finalStatus;
	}

	/**
	 * Validate throttle parameters
	 *
	 * @param array $params Throttle parameters
	 * @return null|string Null on success, a string with the error message on failure
	 */
	public static function checkThrottleParameters( $params ) {
		$throttleRate = explode( ',', $params[1] );
		$throttleCount = $throttleRate[0];
		$throttlePeriod = $throttleRate[1];
		$throttleGroups = array_slice( $params, 2 );
		$validGroups = [
			'ip',
			'user',
			'range',
			'creationdate',
			'editcount',
			'site',
			'page'
		];

		$error = null;
		if ( preg_match( '/^[1-9][0-9]*$/', $throttleCount ) === 0 ) {
			$error = 'abusefilter-edit-invalid-throttlecount';
		} elseif ( preg_match( '/^[1-9][0-9]*$/', $throttlePeriod ) === 0 ) {
			$error = 'abusefilter-edit-invalid-throttleperiod';
		} elseif ( !$throttleGroups ) {
			$error = 'abusefilter-edit-empty-throttlegroups';
		} else {
			$valid = true;
			// Groups should be unique in three ways: no direct duplicates like 'user' and 'user',
			// no duplicated subgroups, not even shuffled ('ip,user' and 'user,ip') and no duplicates
			// within subgroups ('user,ip,user')
			$uniqueGroups = [];
			$uniqueSubGroups = true;
			// Every group should be valid, and subgroups should have valid groups inside
			foreach ( $throttleGroups as $group ) {
				if ( strpos( $group, ',' ) !== false ) {
					$subGroups = explode( ',', $group );
					if ( $subGroups !== array_unique( $subGroups ) ) {
						$uniqueSubGroups = false;
						break;
					}
					foreach ( $subGroups as $subGroup ) {
						if ( !in_array( $subGroup, $validGroups ) ) {
							$valid = false;
							break 2;
						}
					}
					sort( $subGroups );
					$uniqueGroups[] = implode( ',', $subGroups );
				} else {
					if ( !in_array( $group, $validGroups ) ) {
						$valid = false;
						break;
					}
					$uniqueGroups[] = $group;
				}
			}

			if ( !$valid ) {
				$error = 'abusefilter-edit-invalid-throttlegroups';
			} elseif ( !$uniqueSubGroups || $uniqueGroups !== array_unique( $uniqueGroups ) ) {
				$error = 'abusefilter-edit-duplicated-throttlegroups';
			}
		}

		return $error;
	}

	/**
	 * Checks whether user input for the filter editing form is valid and if so saves the filter
	 *
	 * @param AbuseFilterViewEdit $page
	 * @param int|string $filter
	 * @param WebRequest $request
	 * @param stdClass $newRow
	 * @param array $actions
	 * @return Status
	 */
	public static function saveFilter( $page, $filter, $request, $newRow, $actions ) {
		$validationStatus = Status::newGood();

		// Check the syntax
		$syntaxerr = self::checkSyntax( $request->getVal( 'wpFilterRules' ) );
		if ( $syntaxerr !== true ) {
			$validationStatus->error( 'abusefilter-edit-badsyntax', $syntaxerr[0] );
			return $validationStatus;
		}
		// Check for missing required fields (title and pattern)
		$missing = [];
		if ( !$request->getVal( 'wpFilterRules' ) ||
			trim( $request->getVal( 'wpFilterRules' ) ) === '' ) {
			$missing[] = wfMessage( 'abusefilter-edit-field-conditions' )->escaped();
		}
		if ( !$request->getVal( 'wpFilterDescription' ) ) {
			$missing[] = wfMessage( 'abusefilter-edit-field-description' )->escaped();
		}
		if ( count( $missing ) !== 0 ) {
			$missing = $page->getLanguage()->commaList( $missing );
			$validationStatus->error( 'abusefilter-edit-missingfields', $missing );
			return $validationStatus;
		}

		// Don't allow setting as deleted an active filter
		if ( $request->getCheck( 'wpFilterEnabled' ) == true &&
			$request->getCheck( 'wpFilterDeleted' ) == true ) {
			$validationStatus->error( 'abusefilter-edit-deleting-enabled' );
			return $validationStatus;
		}

		// If we've activated the 'tag' option, check the arguments for validity.
		if ( !empty( $actions['tag'] ) ) {
			foreach ( $actions['tag']['parameters'] as $tag ) {
				$status = self::isAllowedTag( $tag );

				if ( !$status->isGood() ) {
					$err = $status->getErrors();
					$msg = $err[0]['message'];
					$validationStatus->error( $msg );
					return $validationStatus;
				}
			}
		}

		// If 'throttle' is selected, check its parameters
		if ( !empty( $actions['throttle'] ) ) {
			$throttleCheck = self::checkThrottleParameters( $actions['throttle']['parameters'] );
			if ( $throttleCheck !== null ) {
				$validationStatus->error( $throttleCheck );
				return $validationStatus;
			}
		}

		$differences = self::compareVersions(
			[ $newRow, $actions ],
			[ $newRow->mOriginalRow, $newRow->mOriginalActions ]
		);

		// Don't allow adding a new global rule, or updating a
		// rule that is currently global, without permissions.
		if ( !$page->canEditFilter( $newRow ) || !$page->canEditFilter( $newRow->mOriginalRow ) ) {
			$validationStatus->fatal( 'abusefilter-edit-notallowed-global' );
			return $validationStatus;
		}

		// Don't allow custom messages on global rules
		if ( $newRow->af_global == 1 && (
				$request->getVal( 'wpFilterWarnMessage' ) !== 'abusefilter-warning' ||
				$request->getVal( 'wpFilterDisallowMessage' ) !== 'abusefilter-disallowed'
		) ) {
			$validationStatus->fatal( 'abusefilter-edit-notallowed-global-custom-msg' );
			return $validationStatus;
		}

		$origActions = $newRow->mOriginalActions;
		$wasGlobal = (bool)$newRow->mOriginalRow->af_global;

		unset( $newRow->mOriginalRow );
		unset( $newRow->mOriginalActions );

		// Check for non-changes
		if ( !count( $differences ) ) {
			$validationStatus->setResult( true, false );
			return $validationStatus;
		}

		// Check for restricted actions
		$restrictions = $page->getConfig()->get( 'AbuseFilterRestrictions' );
		if ( count( array_intersect_key(
				array_filter( $restrictions ),
				array_merge(
					array_filter( $actions ),
					array_filter( $origActions )
				)
			) )
			&& !$page->getUser()->isAllowed( 'abusefilter-modify-restricted' )
		) {
			$validationStatus->error( 'abusefilter-edit-restricted' );
			return $validationStatus;
		}

		// Everything went fine, so let's save the filter
		list( $new_id, $history_id ) =
			self::doSaveFilter( $newRow, $differences, $filter, $actions, $wasGlobal, $page );
		$validationStatus->setResult( true, [ $new_id, $history_id ] );
		return $validationStatus;
	}

	/**
	 * Saves new filter's info to DB
	 *
	 * @param stdClass $newRow
	 * @param int|string $filter
	 * @param array $differences
	 * @param array $actions
	 * @param bool $wasGlobal
	 * @param AbuseFilterViewEdit $page
	 * @return int[] first element is new ID, second is history ID
	 */
	private static function doSaveFilter(
		$newRow,
		$differences,
		$filter,
		$actions,
		$wasGlobal,
		$page
	) {
		$user = $page->getUser();
		$dbw = wfGetDB( DB_MASTER );

		// Convert from object to array
		$newRow = get_object_vars( $newRow );

		// Set last modifier.
		$newRow['af_timestamp'] = $dbw->timestamp( wfTimestampNow() );
		$newRow['af_user'] = $user->getId();
		$newRow['af_user_text'] = $user->getName();

		$dbw->startAtomic( __METHOD__ );

		// Insert MAIN row.
		if ( $filter == 'new' ) {
			$new_id = $dbw->nextSequenceValue( 'abuse_filter_af_id_seq' );
			$is_new = true;
		} else {
			$new_id = $filter;
			$is_new = false;
		}

		// Reset throttled marker, if we're re-enabling it.
		$newRow['af_throttled'] = $newRow['af_throttled'] && !$newRow['af_enabled'];
		$newRow['af_id'] = $new_id;

		// T67807: integer 1's & 0's might be better understood than booleans
		$newRow['af_enabled'] = (int)$newRow['af_enabled'];
		$newRow['af_hidden'] = (int)$newRow['af_hidden'];
		$newRow['af_throttled'] = (int)$newRow['af_throttled'];
		$newRow['af_deleted'] = (int)$newRow['af_deleted'];
		$newRow['af_global'] = (int)$newRow['af_global'];

		$dbw->replace( 'abuse_filter', [ 'af_id' ], $newRow, __METHOD__ );

		if ( $is_new ) {
			$new_id = $dbw->insertId();
		}

		// Actions
		$availableActions = $page->getConfig()->get( 'AbuseFilterActions' );
		$actionsRows = [];
		foreach ( array_filter( $availableActions ) as $action => $_ ) {
			// Check if it's set
			$enabled = isset( $actions[$action] ) && (bool)$actions[$action];

			if ( $enabled ) {
				$parameters = $actions[$action]['parameters'];
				if ( $action === 'throttle' && $parameters[0] === 'new' ) {
					// FIXME: Do we really need to keep the filter ID inside throttle parameters?
					// We'd save space, keep things simpler and avoid this hack. Note: if removing
					// it, a maintenance script will be necessary to clean up the table.
					$parameters[0] = $new_id;
				}

				$thisRow = [
					'afa_filter' => $new_id,
					'afa_consequence' => $action,
					'afa_parameters' => implode( "\n", $parameters )
				];
				$actionsRows[] = $thisRow;
			}
		}

		// Create a history row
		$afh_row = [];

		foreach ( self::$history_mappings as $af_col => $afh_col ) {
			$afh_row[$afh_col] = $newRow[$af_col];
		}

		// Actions
		$displayActions = [];
		foreach ( $actions as $action ) {
			$displayActions[$action['action']] = $action['parameters'];
		}
		$afh_row['afh_actions'] = serialize( $displayActions );

		$afh_row['afh_changed_fields'] = implode( ',', $differences );

		// Flags
		$flags = [];
		if ( $newRow['af_hidden'] ) {
			$flags[] = 'hidden';
		}
		if ( $newRow['af_enabled'] ) {
			$flags[] = 'enabled';
		}
		if ( $newRow['af_deleted'] ) {
			$flags[] = 'deleted';
		}
		if ( $newRow['af_global'] ) {
			$flags[] = 'global';
		}

		$afh_row['afh_flags'] = implode( ',', $flags );

		$afh_row['afh_filter'] = $new_id;
		$afh_row['afh_id'] = $dbw->nextSequenceValue( 'abuse_filter_af_id_seq' );

		// Do the update
		$dbw->insert( 'abuse_filter_history', $afh_row, __METHOD__ );
		$history_id = $dbw->insertId();
		if ( $filter != 'new' ) {
			$dbw->delete(
				'abuse_filter_action',
				[ 'afa_filter' => $filter ],
				__METHOD__
			);
		}
		$dbw->insert( 'abuse_filter_action', $actionsRows, __METHOD__ );

		$dbw->endAtomic( __METHOD__ );

		// Invalidate cache if this was a global rule
		if ( $wasGlobal || $newRow['af_global'] ) {
			$group = 'default';
			if ( isset( $newRow['af_group'] ) && $newRow['af_group'] != '' ) {
				$group = $newRow['af_group'];
			}

			$globalRulesKey = self::getGlobalRulesKey( $group );
			ObjectCache::getMainWANInstance()->touchCheckKey( $globalRulesKey );
		}

		// Logging
		$subtype = $filter === 'new' ? 'create' : 'modify';
		$logEntry = new ManualLogEntry( 'abusefilter', $subtype );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $page->getTitle( $new_id ) );
		$logEntry->setParameters( [
			'historyId' => $history_id,
			'newId' => $new_id
		] );
		$logid = $logEntry->insert();
		$logEntry->publish( $logid );

		// Purge the tag list cache so the fetchAllTags hook applies tag changes
		if ( isset( $actions['tag'] ) ) {
			AbuseFilterHooks::purgeTagCache();
		}

		self::resetFilterProfile( $new_id );
		return [ $new_id, $history_id ];
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
	public static function compareVersions( $version_1, $version_2 ) {
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
				// They're both set. Double check needed, e.g. per T180194
				if ( array_diff( $actions1[$action]['parameters'],
					$actions2[$action]['parameters'] ) ||
					array_diff( $actions2[$action]['parameters'],
					$actions1[$action]['parameters'] ) ) {
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
	public static function translateFromHistory( $row ) {
		// Manually translate into an abuse_filter row.
		$af_row = new stdClass;

		foreach ( self::$history_mappings as $af_col => $afh_col ) {
			$af_row->$af_col = $row->$afh_col;
		}

		// Process flags
		$af_row->af_deleted = 0;
		$af_row->af_hidden = 0;
		$af_row->af_enabled = 0;

		if ( $row->afh_flags !== '' ) {
			$flags = explode( ',', $row->afh_flags );
			foreach ( $flags as $flag ) {
				$col_name = "af_$flag";
				$af_row->$col_name = 1;
			}
		}

		// Process actions
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
	public static function getActionDisplay( $action ) {
		// Give grep a chance to find the usages:
		// abusefilter-action-tag, abusefilter-action-throttle, abusefilter-action-warn,
		// abusefilter-action-blockautopromote, abusefilter-action-block, abusefilter-action-degroup,
		// abusefilter-action-rangeblock, abusefilter-action-disallow
		$display = wfMessage( "abusefilter-action-$action" )->escaped();
		$display = wfMessage( "abusefilter-action-$action", $display )->isDisabled()
			? htmlspecialchars( $action )
			: $display;

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
			self::generateTitleVars( $title, 'PAGE' )
		);

		$vars->setVar( 'ACTION', 'delete' );
		$vars->setVar( 'SUMMARY', CommentStore::getStore()->getComment( 'rc_comment', $row )->text );

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
			self::generateTitleVars( $title, 'PAGE' )
		);

		$vars->setVar( 'ACTION', 'edit' );
		$vars->setVar( 'SUMMARY', CommentStore::getStore()->getComment( 'rc_comment', $row )->text );

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

		$vars->setVar( 'SUMMARY', CommentStore::getStore()->getComment( 'rc_comment', $row )->text );
		$vars->setVar( 'ACTION', 'move' );

		return $vars;
	}

	/**
	 * @param Title|null $title
	 * @param Page|null $page
	 * @return AbuseFilterVariableHolder
	 */
	public static function getEditVars( $title, Page $page = null ) {
		$vars = new AbuseFilterVariableHolder;

		// NOTE: $page may end up remaining null, e.g. if $title points to a special page.
		if ( !$page && $title instanceof Title && $title->canExist() ) {
			$page = WikiPage::factory( $title );
		}

		$vars->setLazyLoadVar( 'edit_diff', 'diff-array',
			[ 'oldtext-var' => 'old_wikitext', 'newtext-var' => 'new_wikitext' ] );
		$vars->setLazyLoadVar( 'edit_diff_pst', 'diff-array',
			[ 'oldtext-var' => 'old_wikitext', 'newtext-var' => 'new_pst' ] );
		$vars->setLazyLoadVar( 'new_size', 'length', [ 'length-var' => 'new_wikitext' ] );
		$vars->setLazyLoadVar( 'old_size', 'length', [ 'length-var' => 'old_wikitext' ] );
		$vars->setLazyLoadVar( 'edit_delta', 'subtract-int',
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
		$deprecatedVars = self::getDeprecatedVariables();
		foreach ( $vars as $key => $value ) {
			$key = strtolower( $key );

			if ( array_key_exists( $key, $deprecatedVars ) ) {
				$key = $deprecatedVars[$key];
			}
			if ( !empty( $variableMessageMappings[$key] ) ) {
				$mapping = $variableMessageMappings[$key];
				$keyDisplay = $context->msg( "abusefilter-edit-builder-vars-$mapping" )->parse() .
					' ' . Xml::element( 'code', null, $context->msg( 'parentheses' )->rawParams( $key )->text() );
			} elseif ( !empty( self::$disabledVars[$key] ) ) {
				$mapping = self::$disabledVars[$key];
				$keyDisplay = $context->msg( "abusefilter-edit-builder-vars-$mapping" )->parse() .
					' ' . Xml::element( 'code', null, $context->msg( 'parentheses' )->rawParams( $key )->text() );
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
	public static function formatAction( $action, $parameters ) {
		/** @var $wgLang Language */
		global $wgLang;
		if ( count( $parameters ) === 0 ||
			( $action === 'block' && count( $parameters ) !== 3 ) ) {
			$displayAction = self::getActionDisplay( $action );
		} else {
			if ( $action === 'block' ) {
				// Needs to be treated separately since the message is more complex
				$messages = [
					wfMessage( 'abusefilter-block-anon' )->escaped() .
					wfMessage( 'colon-separator' )->escaped() .
					$wgLang->translateBlockExpiry( $parameters[1] ),
					wfMessage( 'abusefilter-block-user' )->escaped() .
					wfMessage( 'colon-separator' )->escaped() .
					$wgLang->translateBlockExpiry( $parameters[2] )
				];
				if ( $parameters[0] === 'blocktalk' ) {
					$messages[] = wfMessage( 'abusefilter-block-talk' )->escaped();
				}
				$displayAction = $wgLang->commaList( $messages );
			} elseif ( $action === 'throttle' ) {
				array_shift( $parameters );
				list( $actions, $time ) = explode( ',', array_shift( $parameters ) );

				if ( $parameters === [ '' ] ) {
					// Having empty groups won't happen for new filters due to validation upon saving,
					// but old entries may have it. We'd better not show a broken message. Also,
					// the array has an empty string inside because we haven't been passing an empty array
					// as the default when retrieving wpFilterThrottleGroups with getArray (when it was
					// a CheckboxMultiselect).
					$groups = '';
				} else {
					// Old entries may not have unique values.
					$throttleGroups = array_unique( $parameters );
					// Join comma-separated groups in a commaList with a final "and", and convert to messages.
					// Messages used here: abusefilter-throttle-ip, abusefilter-throttle-user,
					// abusefilter-throttle-site, abusefilter-throttle-creationdate, abusefilter-throttle-editcount
					// abusefilter-throttle-range, abusefilter-throttle-page
					foreach ( $throttleGroups as &$val ) {
						if ( strpos( $val, ',' ) !== false ) {
							$subGroups = explode( ',', $val );
							foreach ( $subGroups as &$group ) {
								$msg = wfMessage( "abusefilter-throttle-$group" );
								// We previously accepted literally everything in this field, so old entries
								// may have weird stuff.
								$group = $msg->exists() ? $msg->text() : $group;
							}
							unset( $group );
							$val = $wgLang->listToText( $subGroups );
						} else {
							$msg = wfMessage( "abusefilter-throttle-$val" );
							$val = $msg->exists() ? $msg->text() : $val;
						}
					}
					unset( $val );
					$groups = $wgLang->semicolonList( $throttleGroups );
				}
				$displayAction = self::getActionDisplay( $action ) .
				wfMessage( 'colon-separator' )->escaped() .
				wfMessage( 'abusefilter-throttle-details' )->params( $actions, $time, $groups )->escaped();
			} else {
				$displayAction = self::getActionDisplay( $action ) .
				wfMessage( 'colon-separator' )->escaped() .
				$wgLang->semicolonList( array_map( 'htmlspecialchars', $parameters ) );
			}
		}

		return $displayAction;
	}

	/**
	 * @param string $value
	 * @return string
	 */
	public static function formatFlags( $value ) {
		/** @var $wgLang Language */
		global $wgLang;
		$flags = array_filter( explode( ',', $value ) );
		$flags_display = [];
		foreach ( $flags as $flag ) {
			$flags_display[] = wfMessage( "abusefilter-history-$flag" )->escaped();
		}

		return $wgLang->commaList( $flags_display );
	}

	/**
	 * @param string $filterID
	 * @return string
	 */
	public static function getGlobalFilterDescription( $filterID ) {
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
	public static function nameGroup( $group ) {
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
	 * @param Revision|null $revision a valid revision
	 * @param int $audience one of:
	 *      Revision::FOR_PUBLIC       to be displayed to all users
	 *      Revision::FOR_THIS_USER    to be displayed to the given user
	 *      Revision::RAW              get the text regardless of permissions
	 * @return string|null the content of the revision as some kind of string,
	 *        or an empty string if it can not be found
	 */
	public static function revisionToString( $revision, $audience = Revision::FOR_THIS_USER ) {
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
	public static function contentToString( Content $content ) {
		$text = null;

		if ( Hooks::run( 'AbuseFilter-contentToString', [ $content, &$text ] ) ) {
			$text = $content instanceof TextContent
				? $content->getNativeData()
				: $content->getTextForSearchIndex();
		}

		// T22310
		$text = TextContent::normalizeLineEndings( (string)$text );
		return $text;
	}

	/**
	 * Get the history ID of the first change to a given filter
	 *
	 * @param int $filterID Filter id
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
