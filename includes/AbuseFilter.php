<?php

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\DBError;

/**
 * This class contains most of the business logic of AbuseFilter. It consists of
 * static functions for generic use (mostly utility functions).
 */
class AbuseFilter {
	/**
	 * @var int How long to keep profiling data in cache (in seconds)
	 */
	public static $statsStoragePeriod = 86400;

	/**
	 * @var array [filter ID => stdClass|null] as retrieved from self::getFilter. ID could be either
	 *   an integer or "<GLOBAL_FILTER_PREFIX><integer>"
	 */
	private static $filterCache = [];

	/** @var string The prefix to use for global filters */
	const GLOBAL_FILTER_PREFIX = 'global-';

	/*
	 * @var array Map of (action ID => string[])
	 * @fixme avoid global state here
	 */
	public static $tagsToSet = [];

	/**
	 * @var array IDs of logged filters like [ page title => [ 'local' => [ids], 'global' => [ids] ] ].
	 * @fixme avoid global state
	 */
	public static $logIds = [];

	/**
	 * @var string[] The FULL list of fields in the abuse_filter table
	 * @todo Make a const once we get rid of HHVM
	 * @internal
	 */
	public static $allAbuseFilterFields = [
		'af_id',
		'af_pattern',
		'af_user',
		'af_user_text',
		'af_timestamp',
		'af_enabled',
		'af_comments',
		'af_public_comments',
		'af_hidden',
		'af_hit_count',
		'af_throttled',
		'af_deleted',
		'af_actions',
		'af_global',
		'af_group'
	];

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
		'old_html' => 'old-html',
		'minor_edit' => 'minor-edit'
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
		];

		if ( $context->getUser()->isAllowed( 'abusefilter-log' ) ) {
			$linkDefs = array_merge( $linkDefs, [
				'log' => 'Special:AbuseLog'
			] );
		}

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

		$links = [];

		foreach ( $linkDefs as $name => $page ) {
			// Give grep a chance to find the usages:
			// abusefilter-topnav-home, abusefilter-topnav-recentchanges, abusefilter-topnav-test,
			// abusefilter-topnav-log, abusefilter-topnav-tools, abusefilter-topnav-import
			// abusefilter-topnav-examine
			$msgName = "abusefilter-topnav-$name";

			$msg = $context->msg( $msgName )->parse();
			$title = Title::newFromText( $page );

			if ( $name === $pageType ) {
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
	 * @param null|stdClass $rcRow If the variables should be generated for an RC row, this is the row.
	 *   Null if it's for the current action being filtered.
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateUserVars( User $user, $rcRow = null ) {
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
			'user-block',
			[ 'user' => $user ]
		);

		Hooks::run( 'AbuseFilter-generateUserVars', [ $vars, $user, $rcRow ] );

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
	 * @param int $filterID The ID of the filter
	 * @param bool|int $global Whether the filter is global
	 * @return bool
	 */
	public static function filterHidden( $filterID, $global = false ) {
		global $wgAbuseFilterCentralDB;

		if ( $global ) {
			if ( !$wgAbuseFilterCentralDB ) {
				return false;
			}
			$dbr = self::getCentralDB( DB_REPLICA );
		} else {
			$dbr = wfGetDB( DB_REPLICA );
		}

		$hidden = $dbr->selectField(
			'abuse_filter',
			'af_hidden',
			[ 'af_id' => $filterID ],
			__METHOD__
		);

		return (bool)$hidden;
	}

	/**
	 * @param Title|null $title
	 * @param string $prefix
	 * @param null|stdClass $rcRow If the variables should be generated for an RC row, this is the row.
	 *   Null if it's for the current action being filtered.
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateTitleVars( $title, $prefix, $rcRow = null ) {
		$vars = new AbuseFilterVariableHolder;

		if ( !$title ) {
			return $vars;
		}

		$vars->setVar( $prefix . '_id', $title->getArticleID() );
		$vars->setVar( $prefix . '_namespace', $title->getNamespace() );
		$vars->setVar( $prefix . '_title', $title->getText() );
		$vars->setVar( $prefix . '_prefixedtitle', $title->getPrefixedText() );

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

		Hooks::run( 'AbuseFilter-generateTitleVars', [ $vars, $title, $prefix, $rcRow ] );

		return $vars;
	}

	/**
	 * Computes all variables unrelated to title and user. In general, these variables are known
	 * even without an ongoing action.
	 *
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateStaticVars() {
		$vars = new AbuseFilterVariableHolder();

		// For now, we don't have variables to add; other extensions could.
		Hooks::run( 'AbuseFilter-generateStaticVars', [ $vars ] );
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

		// Static vars are the only ones available
		$vars = self::generateStaticVars();
		$vars->setVar( 'timestamp', wfTimestamp( TS_UNIX ) );
		/** @var $parser AbuseFilterParser */
		$parser = new $wgAbuseFilterParserClass( $vars );

		return $parser->evaluateExpression( $expr );
	}

	/**
	 * @param string $conds
	 * @param AbuseFilterParser $parser The parser instance to use.
	 * @param bool $ignoreError
	 * @param string|null $filter The ID of the filter being parsed
	 * @return bool
	 * @throws Exception
	 */
	public static function checkConditions(
		$conds, AbuseFilterParser $parser, $ignoreError = true, $filter = null
	) {
		try {
			$result = $parser->parse( $conds );
		} catch ( Exception $excep ) {
			$result = false;

			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$extraInfo = $filter !== null ? " for filter $filter" : '';
			$logger->warning( "AbuseFilter parser error$extraInfo: " . $excep->getMessage() );

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
	 * @param Title $title
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @param string $mode 'execute' for edits and logs, 'stash' for cached matches
	 * @return bool[] Map of (integer filter ID => bool)
	 * @deprecated Since 1.33 See comment on AbuseFilterRunner::checkAllFilters
	 */
	public static function checkAllFilters(
		AbuseFilterVariableHolder $vars,
		Title $title,
		$group = 'default',
		$mode = 'execute'
	) {
		global $wgAbuseFilterParserClass;

		/** @var $parser AbuseFilterParser */
		$parser = new $wgAbuseFilterParserClass( $vars );
		$user = RequestContext::getMain()->getUser();

		$runner = new AbuseFilterRunner( $user, $title, $vars, $group );
		$runner->executeMode = $mode === 'execute';
		$runner->parser = $parser;
		return $runner->checkAllFilters();
	}

	/**
	 * @param int $filter
	 */
	private static function resetFilterProfile( $filter ) {
		$stash = MediaWikiServices::getInstance()->getMainObjectStash();
		$countKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'count' );
		$totalKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'total' );
		$condsKey = wfMemcKey( 'abusefilter', 'profile', $filter, 'conds' );

		$stash->delete( $countKey );
		$stash->delete( $totalKey );
		$stash->delete( $condsKey );
	}

	/**
	 * @param string $filter
	 * @return array
	 */
	public static function getFilterProfile( $filter ) {
		$stash = MediaWikiServices::getInstance()->getMainObjectStash();
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
	 * Utility function to split "<GLOBAL_FILTER_PREFIX>$index" to an array [ $id, $global ], where
	 * $id is $index casted to int, and $global is a boolean: true if the filter is global,
	 * false otherwise (i.e. if the $filter === $index). Note that the $index
	 * is always casted to int. Passing anything which isn't an integer-like value or a string
	 * in the shape "<GLOBAL_FILTER_PREFIX>integer" will throw.
	 * This reverses self::buildGlobalName
	 *
	 * @param string|int $filter
	 * @return array
	 * @throws InvalidArgumentException
	 */
	public static function splitGlobalName( $filter ) {
		if ( preg_match( '/^' . self::GLOBAL_FILTER_PREFIX . '\d+$/', $filter ) === 1 ) {
			$id = intval( substr( $filter, strlen( self::GLOBAL_FILTER_PREFIX ) ) );
			return [ $id, true ];
		} elseif ( is_numeric( $filter ) ) {
			return [ (int)$filter, false ];
		} else {
			throw new InvalidArgumentException( "Invalid filter name: $filter" );
		}
	}

	/**
	 * Given a filter ID and a boolean indicating whether it's global, build a string like
	 * "<GLOBAL_FILTER_PREFIX>$ID". Note that, with global = false, $id is casted to string.
	 * This reverses self::splitGlobalName.
	 *
	 * @param int $id The filter ID
	 * @param bool $global Whether the filter is global
	 * @return string
	 * @todo Calling this method should be avoided wherever possible
	 */
	public static function buildGlobalName( $id, $global = true ) {
		$prefix = $global ? self::GLOBAL_FILTER_PREFIX : '';
		return "$prefix$id";
	}

	/**
	 * @param string[] $filters
	 * @return array[]
	 */
	public static function getConsequencesForFilters( $filters ) {
		$globalFilters = [];
		$localFilters = [];

		foreach ( $filters as $filter ) {
			list( $filterID, $global ) = self::splitGlobalName( $filter );

			if ( $global ) {
				$globalFilters[] = $filterID;
			} else {
				$localFilters[] = $filter;
			}
		}

		// Load local filter info
		$dbr = wfGetDB( DB_REPLICA );
		// Retrieve the consequences.
		$consequences = [];

		if ( count( $localFilters ) ) {
			$consequences = self::loadConsequencesFromDB( $dbr, $localFilters );
		}

		if ( count( $globalFilters ) ) {
			$consequences += self::loadConsequencesFromDB(
				self::getCentralDB( DB_REPLICA ),
				$globalFilters,
				self::GLOBAL_FILTER_PREFIX
			);
		}

		return $consequences;
	}

	/**
	 * @param IDatabase $dbr
	 * @param string[] $filters
	 * @param string $prefix
	 * @return array[]
	 */
	public static function loadConsequencesFromDB( IDatabase $dbr, $filters, $prefix = '' ) {
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
				// Don't do the action, just log
				$logger = LoggerFactory::getInstance( 'AbuseFilter' );
				$logger->info(
					'Filter {filter_id} is throttled, skipping action: {action}',
					[
						'filter_id' => $row->af_id,
						'action' => $row->afa_consequence
					]
				);
			} elseif ( $row->afa_filter !== $row->af_id ) {
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
	 * @param AbuseFilterVariableHolder $vars
	 * @param Title $title
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @param User $user The user performing the action
	 * @return Status
	 * @deprecated Since 1.33 Build an AbuseFilterRunner instance and call run() on that.
	 */
	public static function filterAction(
		AbuseFilterVariableHolder $vars, Title $title, $group, User $user
	) {
		$runner = new AbuseFilterRunner( $user, $title, $vars, $group );
		return $runner->run();
	}

	/**
	 * @param string $filter Filter ID (integer or "<GLOBAL_FILTER_PREFIX><integer>")
	 * @return stdClass|null DB row on success, null on failure
	 */
	public static function getFilter( $filter ) {
		global $wgAbuseFilterCentralDB;

		if ( !isset( self::$filterCache[$filter] ) ) {
			list( $filterID, $global ) = self::splitGlobalName( $filter );
			if ( $global ) {
				// Global wiki filter
				if ( !$wgAbuseFilterCentralDB ) {
					return null;
				}
				$dbr = self::getCentralDB( DB_REPLICA );
			} else {
				// Local wiki filter
				$dbr = wfGetDB( DB_REPLICA );
			}

			$row = $dbr->selectRow(
				'abuse_filter',
				self::$allAbuseFilterFields,
				[ 'af_id' => $filterID ],
				__METHOD__
			);
			self::$filterCache[$filter] = $row ?: null;
		}

		return self::$filterCache[$filter];
	}

	/**
	 * Saves an abuse_filter row in cache
	 * @param string $id Filter ID (integer or "<GLOBAL_FILTER_PREFIX><integer>")
	 * @param stdClass $row A full abuse_filter row to save
	 * @throws UnexpectedValueException if the row is not full
	 */
	public static function cacheFilter( $id, $row ) {
		// Check that all fields have been passed, otherwise using self::getFilter for this
		// row will return partial data.
		$actual = array_keys( get_object_vars( $row ) );

		if ( count( $actual ) !== count( self::$allAbuseFilterFields )
			|| array_diff( self::$allAbuseFilterFields, $actual )
		) {
			throw new UnexpectedValueException( 'The specified row must be a full abuse_filter row.' );
		}
		self::$filterCache[$id] = $row;
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
	public static function storeVarDump( AbuseFilterVariableHolder $vars, $global = false ) {
		global $wgCompressRevisions;

		// Get all variables yet set and compute old and new wikitext if not yet done
		// as those are needed for the diff view on top of the abuse log pages
		$vars = $vars->dumpAllVars( [ 'old_wikitext', 'new_wikitext' ] );

		// Vars is an array with native PHP data types (non-objects) now
		$text = serialize( $vars );
		$flags = [ 'nativeDataArray' ];

		if ( $wgCompressRevisions && function_exists( 'gzdeflate' ) ) {
			$text = gzdeflate( $text );
			$flags[] = 'gzip';
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
			$dbw = self::getCentralDB( DB_MASTER );
		} else {
			$dbw = wfGetDB( DB_MASTER );
		}
		$dbw->insert( 'text',
			[
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
			if ( count( array_intersect_key( self::getDeprecatedVariables(), $obj->getVars() ) ) !== 0 ) {
				$obj->mVarsVersion = 1;
			}
		}

		return $obj;
	}

	/**
	 * Get an identifier for the given action to be used in self::$tagsToSet
	 *
	 * @param string $action The name of the current action, as used by AbuseFilter (e.g. 'edit'
	 *   or 'createaccount')
	 * @param Title $title The title where the current action is executed on. This is the user page
	 *   for account creations.
	 * @param string $username Of the user executing the action (as returned by User::getName()).
	 *   For account creation, this is the name of the new account.
	 * @return string
	 */
	public static function getTaggingActionId( $action, Title $title, $username ) {
		return implode(
			'-',
			[
				$title->getPrefixedText(),
				$username,
				$action
			]
		);
	}

	/**
	 * @param array[] $tagsByAction Map of (integer => string[])
	 */
	public static function bufferTagsToSetByAction( array $tagsByAction ) {
		global $wgAbuseFilterActions;
		if ( isset( $wgAbuseFilterActions['tag'] ) && $wgAbuseFilterActions['tag'] ) {
			foreach ( $tagsByAction as $actionID => $tags ) {
				if ( !isset( self::$tagsToSet[$actionID] ) ) {
					self::$tagsToSet[$actionID] = $tags;
				} else {
					self::$tagsToSet[$actionID] = array_unique(
						array_merge( self::$tagsToSet[$actionID], $tags )
					);
				}
			}
		}
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
	public static function autoPromoteBlockKey( User $user ) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

		return $cache->makeKey( 'abusefilter', 'block-autopromote', $user->getId() );
	}

	/**
	 * @param string $type The value to get, either "threshold", "count" or "age"
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @return mixed
	 */
	public static function getEmergencyValue( $type, $group ) {
		switch ( $type ) {
			case 'threshold':
				global $wgAbuseFilterEmergencyDisableThreshold;
				$value = $wgAbuseFilterEmergencyDisableThreshold;
				break;
			case 'count':
				global $wgAbuseFilterEmergencyDisableCount;
				$value = $wgAbuseFilterEmergencyDisableCount;
				break;
			case 'age':
				global $wgAbuseFilterEmergencyDisableAge;
				$value = $wgAbuseFilterEmergencyDisableAge;
				break;
			default:
				throw new InvalidArgumentException( '$type must be either "threshold", "count" or "age"' );
		}

		return $value[$group] ?? $value['default'];
	}

	/**
	 * @return string
	 */
	public static function filterLimitReachedKey() {
		return wfMemcKey( 'abusefilter', 'stats', 'overflow' );
	}

	/**
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @return string
	 */
	public static function filterUsedKey( $group ) {
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

		if ( !$user ) {
			// User name is invalid. Don't throw because this is a system message, easy
			// to change and make wrong either by mistake or intentionally to break the site.
			wfWarn(
				'The AbuseFilter user\'s name is invalid. Please change it in ' .
				'MediaWiki:abusefilter-blocker'
			);
			// Use the default name to avoid breaking other stuff. This should have no harm,
			// aside from blocks temporarily attributed to another user.
			$defaultName = wfMessage( 'abusefilter-blocker' )->inLanguage( 'en' )->text();
			$user = User::newSystemUser( $defaultName, [ 'steal' => true ] );
		}

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
		list( $throttleCount, $throttlePeriod ) = explode( ',', $params[1], 2 );
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
	 * @param stdClass $newRow
	 * @param array $actions
	 * @return Status
	 */
	public static function saveFilter( AbuseFilterViewEdit $page, $filter, $newRow, $actions ) {
		$validationStatus = Status::newGood();
		$request = $page->getRequest();

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
		if ( $request->getCheck( 'wpFilterEnabled' ) && $request->getCheck( 'wpFilterDeleted' ) ) {
			$validationStatus->error( 'abusefilter-edit-deleting-enabled' );
			return $validationStatus;
		}

		// If we've activated the 'tag' option, check the arguments for validity.
		if ( isset( $actions['tag'] ) ) {
			if ( count( $actions['tag'] ) === 0 ) {
				$validationStatus->error( 'tags-create-no-name' );
				return $validationStatus;
			}
			foreach ( $actions['tag'] as $tag ) {
				$status = self::isAllowedTag( $tag );

				if ( !$status->isGood() ) {
					$err = $status->getErrors();
					$msg = $err[0]['message'];
					$validationStatus->error( $msg );
					return $validationStatus;
				}
			}
		}

		// Warning and disallow message cannot be empty
		if ( isset( $actions['warn'] ) && $actions['warn'][0] === '' ) {
			$validationStatus->error( 'abusefilter-edit-invalid-warn-message' );
			return $validationStatus;
		} elseif ( isset( $actions['disallow'] ) && $actions['disallow'][0] === '' ) {
			$validationStatus->error( 'abusefilter-edit-invalid-disallow-message' );
			return $validationStatus;
		}

		// If 'throttle' is selected, check its parameters
		if ( isset( $actions['throttle'] ) ) {
			$throttleCheck = self::checkThrottleParameters( $actions['throttle'] );
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
				array_merge( $actions, $origActions )
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
	 * @param array $differences
	 * @param int|string $filter
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
		AbuseFilterViewEdit $page
	) {
		$user = $page->getUser();
		$dbw = wfGetDB( DB_MASTER );

		// Convert from object to array
		$newRow = get_object_vars( $newRow );

		// Set last modifier.
		$newRow['af_timestamp'] = $dbw->timestamp();
		$newRow['af_user'] = $user->getId();
		$newRow['af_user_text'] = $user->getName();

		$dbw->startAtomic( __METHOD__ );

		// Insert MAIN row.
		if ( $filter === 'new' ) {
			$new_id = null;
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

		$availableActions = $page->getConfig()->get( 'AbuseFilterActions' );
		$actionsRows = [];
		foreach ( array_filter( $availableActions ) as $action => $_ ) {
			// Check if it's set
			$enabled = isset( $actions[$action] );

			if ( $enabled ) {
				$parameters = $actions[$action];
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

		$afh_row['afh_actions'] = serialize( $actions );

		$afh_row['afh_changed_fields'] = implode( ',', $differences );

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

		// Do the update
		$dbw->insert( 'abuse_filter_history', $afh_row, __METHOD__ );
		$history_id = $dbw->insertId();
		if ( $filter !== 'new' ) {
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
			if ( isset( $newRow['af_group'] ) && $newRow['af_group'] !== '' ) {
				$group = $newRow['af_group'];
			}

			$globalRulesKey = self::getGlobalRulesKey( $group );
			MediaWikiServices::getInstance()->getMainWANObjectCache()->touchCheckKey( $globalRulesKey );
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
				if ( array_diff( $actions1[$action], $actions2[$action] ) ||
					array_diff( $actions2[$action], $actions1[$action] ) ) {
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
		$actionsRaw = unserialize( $row->afh_actions );
		$actionsOutput = is_array( $actionsRaw ) ? $actionsRaw : [];

		return [ $af_row, $actionsOutput ];
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
		if ( $row->rc_log_type === 'move' ) {
			$vars = self::getMoveVarsFromRCRow( $row );
		} elseif ( $row->rc_log_type === 'newusers' ) {
			$vars = self::getCreateVarsFromRCRow( $row );
		} elseif ( $row->rc_log_type === 'delete' ) {
			$vars = self::getDeleteVarsFromRCRow( $row );
		} elseif ( $row->rc_log_type == 'upload' ) {
			$vars = self::getUploadVarsFromRCRow( $row );
		} elseif ( $row->rc_this_oldid ) {
			// It's an edit.
			$vars = self::getEditVarsFromRCRow( $row );
		} else {
			return null;
		}
		if ( $vars ) {
			$vars->setVar( 'timestamp', wfTimestamp( TS_UNIX, $row->rc_timestamp ) );
			$vars->addHolders( self::generateStaticVars() );
		}

		return $vars;
	}

	/**
	 * @param stdClass $row
	 * @return AbuseFilterVariableHolder
	 */
	public static function getCreateVarsFromRCRow( $row ) {
		$vars = new AbuseFilterVariableHolder;

		$vars->setVar( 'action', ( $row->rc_log_action === 'autocreate' ) ?
			'autocreateaccount' :
			'createaccount' );

		$name = Title::makeTitle( $row->rc_namespace, $row->rc_title )->getText();
		// Add user data if the account was created by a registered user
		if ( $row->rc_user && $name !== $row->rc_user_text ) {
			$user = User::newFromName( $row->rc_user_text );
			$vars->addHolders( self::generateUserVars( $user, $row ) );
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
			self::generateUserVars( $user, $row ),
			self::generateTitleVars( $title, 'page', $row )
		);

		$vars->setVar( 'action', 'delete' );
		$vars->setVar( 'summary', CommentStore::getStore()->getComment( 'rc_comment', $row )->text );

		return $vars;
	}

	/**
	 * @param stdClass $row
	 * @return AbuseFilterVariableHolder
	 */
	public static function getUploadVarsFromRCRow( $row ) {
		$vars = new AbuseFilterVariableHolder;
		$title = Title::makeTitle( $row->rc_namespace, $row->rc_title );

		if ( $row->rc_user ) {
			$user = User::newFromName( $row->rc_user_text );
		} else {
			$user = new User;
			$user->setName( $row->rc_user_text );
		}

		$vars->addHolders(
			self::generateUserVars( $user, $row ),
			self::generateTitleVars( $title, 'page', $row )
		);

		$vars->setVar( 'action', 'upload' );
		$vars->setVar( 'summary', CommentStore::getStore()->getComment( 'rc_comment', $row )->text );

		$time = LogEntryBase::extractParams( $row->rc_params )['img_timestamp'];
		$file = wfFindFile( $title, [ 'time' => $time, 'private' => true ] );
		if ( !$file ) {
			// FixMe This shouldn't happen!
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->debug( "Cannot find file from RC row with title $title" );
			return $vars;
		}

		// This is the same as AbuseFilterHooks::filterUpload, but from a different source
		$vars->setVar( 'file_sha1', Wikimedia\base_convert( $file->getSha1(), 36, 16, 40 ) );
		$vars->setVar( 'file_size', $file->getSize() );

		$vars->setVar( 'file_mime', $file->getMimeType() );
		$vars->setVar(
			'file_mediatype',
			MediaWikiServices::getInstance()->getMimeAnalyzer()
				->getMediaType( null, $file->getMimeType() )
		);
		$vars->setVar( 'file_width', $file->getWidth() );
		$vars->setVar( 'file_height', $file->getHeight() );

		$mwProps = new MWFileProps( MediaWikiServices::getInstance()->getMimeAnalyzer() );
		$bits = $mwProps->getPropsFromPath( $file->getLocalRefPath(), true )['bits'];
		$vars->setVar( 'file_bits_per_channel', $bits );

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
			self::generateUserVars( $user, $row ),
			self::generateTitleVars( $title, 'page', $row )
		);

		$vars->setVar( 'action', 'edit' );
		$vars->setVar( 'summary', CommentStore::getStore()->getComment( 'rc_comment', $row )->text );

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
			self::generateUserVars( $user, $row ),
			self::generateTitleVars( $oldTitle, 'moved_from', $row ),
			self::generateTitleVars( $newTitle, 'moved_to', $row )
		);

		$vars->setVar( 'summary', CommentStore::getStore()->getComment( 'rc_comment', $row )->text );
		$vars->setVar( 'action', 'move' );

		return $vars;
	}

	/**
	 * @param Title $title
	 * @param Page|null $page
	 * @return AbuseFilterVariableHolder
	 */
	public static function getEditVars( Title $title, Page $page = null ) {
		$vars = new AbuseFilterVariableHolder;

		// NOTE: $page may end up remaining null, e.g. if $title points to a special page.
		if ( !$page && $title->canExist() ) {
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
	 * @param Language $lang
	 * @return string
	 */
	public static function formatAction( $action, $parameters, $lang ) {
		if ( count( $parameters ) === 0 ||
			( $action === 'block' && count( $parameters ) !== 3 ) ) {
			$displayAction = self::getActionDisplay( $action );
		} else {
			if ( $action === 'block' ) {
				// Needs to be treated separately since the message is more complex
				$messages = [
					wfMessage( 'abusefilter-block-anon' )->escaped() .
					wfMessage( 'colon-separator' )->escaped() .
					$lang->translateBlockExpiry( $parameters[1] ),
					wfMessage( 'abusefilter-block-user' )->escaped() .
					wfMessage( 'colon-separator' )->escaped() .
					$lang->translateBlockExpiry( $parameters[2] )
				];
				if ( $parameters[0] === 'blocktalk' ) {
					$messages[] = wfMessage( 'abusefilter-block-talk' )->escaped();
				}
				$displayAction = $lang->commaList( $messages );
			} elseif ( $action === 'throttle' ) {
				array_shift( $parameters );
				list( $actions, $time ) = explode( ',', array_shift( $parameters ) );

				// Join comma-separated groups in a commaList with a final "and", and convert to messages.
				// Messages used here: abusefilter-throttle-ip, abusefilter-throttle-user,
				// abusefilter-throttle-site, abusefilter-throttle-creationdate, abusefilter-throttle-editcount
				// abusefilter-throttle-range, abusefilter-throttle-page, abusefilter-throttle-none
				foreach ( $parameters as &$val ) {
					if ( strpos( $val, ',' ) !== false ) {
						$subGroups = explode( ',', $val );
						foreach ( $subGroups as &$group ) {
							$msg = wfMessage( "abusefilter-throttle-$group" );
							// We previously accepted literally everything in this field, so old entries
							// may have weird stuff.
							$group = $msg->exists() ? $msg->text() : $group;
						}
						unset( $group );
						$val = $lang->listToText( $subGroups );
					} else {
						$msg = wfMessage( "abusefilter-throttle-$val" );
						$val = $msg->exists() ? $msg->text() : $val;
					}
				}
				unset( $val );
				$groups = $lang->semicolonList( $parameters );

				$displayAction = self::getActionDisplay( $action ) .
				wfMessage( 'colon-separator' )->escaped() .
				wfMessage( 'abusefilter-throttle-details' )->params( $actions, $time, $groups )->escaped();
			} else {
				$displayAction = self::getActionDisplay( $action ) .
				wfMessage( 'colon-separator' )->escaped() .
				$lang->semicolonList( array_map( 'htmlspecialchars', $parameters ) );
			}
		}

		return $displayAction;
	}

	/**
	 * @param string $value
	 * @param Language $lang
	 * @return string
	 */
	public static function formatFlags( $value, $lang ) {
		$flags = array_filter( explode( ',', $value ) );
		$flags_display = [];
		foreach ( $flags as $flag ) {
			$flags_display[] = wfMessage( "abusefilter-history-$flag" )->escaped();
		}

		return $lang->commaList( $flags_display );
	}

	/**
	 * @param int $filterID
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

		$fdb = self::getCentralDB( DB_REPLICA );

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
	 * For now, this returns all the revision's slots, concatenated together.
	 * In future, this will be replaced by a better solution. See T208769 for
	 * discussion.
	 *
	 * @internal
	 *
	 * @param Revision|RevisionRecord|null $revision a valid revision
	 * @param User $user the user instance to check for privileged access
	 * @return string the content of the revision as some kind of string,
	 *        or an empty string if it can not be found
	 */
	public static function revisionToString( $revision, User $user ) {
		if ( $revision instanceof Revision ) {
			$revision = $revision->getRevisionRecord();
		}
		if ( !$revision instanceof RevisionRecord ) {
			return '';
		}

		$strings = [];

		foreach ( $revision->getSlotRoles() as $role ) {
			$content = $revision->getContent( $role, RevisionRecord::FOR_THIS_USER, $user );
			if ( $content === null ) {
				continue;
			}
			$strings[$role] = self::contentToString( $content );
		}

		$result = implode( "\n\n", $strings );
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
	 * @internal
	 *
	 * @param Content $content
	 *
	 * @return string a suitable string representation of the content.
	 */
	public static function contentToString( Content $content ) {
		$text = null;

		if ( Hooks::run( 'AbuseFilter-contentToString', [ $content, &$text ] ) ) {
			$text = $content instanceof TextContent
				? $content->getText()
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
	 * @return string
	 */
	public static function getFirstFilterChange( $filterID ) {
		static $firstChanges = [];

		if ( !isset( $firstChanges[$filterID] ) ) {
			$dbr = wfGetDB( DB_REPLICA );
			$historyID = $dbr->selectField(
				'abuse_filter_history',
				'afh_id',
				[
					'afh_filter' => $filterID,
				],
				__METHOD__,
				[ 'ORDER BY' => 'afh_timestamp ASC' ]
			);
			$firstChanges[$filterID] = $historyID;
		}

		return $firstChanges[$filterID];
	}

	/**
	 * @param int $index DB_MASTER/DB_REPLICA
	 * @return IDatabase
	 * @throws DBerror
	 * @throws RuntimeException
	 */
	public static function getCentralDB( $index ) {
		global $wgAbuseFilterCentralDB;

		if ( !is_string( $wgAbuseFilterCentralDB ) ) {
			throw new RuntimeException( '$wgAbuseFilterCentralDB is not configured' );
		}

		return MediaWikiServices::getInstance()
			->getDBLoadBalancerFactory()
			->getMainLB( $wgAbuseFilterCentralDB )
			->getConnectionRef( $index, [], $wgAbuseFilterCentralDB );
	}
}
