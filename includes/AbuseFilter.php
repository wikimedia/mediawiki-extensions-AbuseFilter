<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IDatabase;

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
	public const GLOBAL_FILTER_PREFIX = 'global-';

	/**
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
	 * @internal
	 */
	public const ALL_ABUSE_FILTER_FIELDS = [
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

	public const HISTORY_MAPPINGS = [
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

	/**
	 * @deprecated Since 1.35 Use VariableGenerator::addUserVars()
	 * @param User $user
	 * @param RecentChange|null $entry
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateUserVars( User $user, RecentChange $entry = null ) {
		wfDeprecated( __METHOD__, '1.35' );
		$vars = new AbuseFilterVariableHolder();
		$generator = new VariableGenerator( $vars );
		return $generator->addUserVars( $user, $entry )->getVariableHolder();
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
	 * @deprecated Since 1.35 Use VariableGenerator::addTitleVars
	 * @param Title|null $title
	 * @param string $prefix
	 * @param RecentChange|null $entry
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateTitleVars( $title, $prefix, RecentChange $entry = null ) {
		wfDeprecated( __METHOD__, '1.35' );
		$vars = new AbuseFilterVariableHolder();
		if ( !( $title instanceof Title ) ) {
			return $vars;
		}
		$generator = new VariableGenerator( $vars );
		return $generator->addTitleVars( $title, $prefix, $entry )->getVariableHolder();
	}

	/**
	 * @deprecated Since 1.35 Use VariableGenerator::addGenericVars
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateGenericVars() {
		wfDeprecated( __METHOD__, '1.35' );
		$vars = new AbuseFilterVariableHolder();
		$generator = new VariableGenerator( $vars );
		return $generator->addGenericVars()->getVariableHolder();
	}

	/**
	 * Returns an associative array of filters which were tripped
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param Title $title
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @param string $mode 'execute' for edits and logs, 'stash' for cached matches
	 * @return bool[] Map of (integer filter ID => bool)
	 * @deprecated Since 1.34 See comment on AbuseFilterRunner::checkAllFilters
	 */
	public static function checkAllFilters(
		AbuseFilterVariableHolder $vars,
		Title $title,
		$group = 'default',
		$mode = 'execute'
	) {
		$parser = self::getDefaultParser( $vars );
		$user = RequestContext::getMain()->getUser();

		$runner = new AbuseFilterRunner( $user, $title, $vars, $group );
		$runner->parser = $parser;
		return $runner->checkAllFilters();
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
	 * @return (string|array)[][][]
	 * @phan-return array<string,array<string,array{action:string,parameters:string[]}>>
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
	 * @return (string|array)[][][]
	 * @phan-return array<string,array<string,array{action:string,parameters:string[]}>>
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
	 * @deprecated Since 1.34 Build an AbuseFilterRunner instance and call run() on that.
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
				self::ALL_ABUSE_FILTER_FIELDS,
				[ 'af_id' => $filterID ],
				__METHOD__
			);
			self::$filterCache[$filter] = $row ?: null;
		}

		return self::$filterCache[$filter];
	}

	/**
	 * Checks whether the given object represents a full abuse_filter DB row
	 * @param stdClass $row
	 * @return bool
	 */
	public static function isFullAbuseFilterRow( stdClass $row ) {
		$actual = array_keys( get_object_vars( $row ) );

		if (
			count( $actual ) !== count( self::ALL_ABUSE_FILTER_FIELDS )
			|| array_diff( self::ALL_ABUSE_FILTER_FIELDS, $actual )
		) {
			return false;
		}
		return true;
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
		if ( !self::isFullAbuseFilterRow( $row ) ) {
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
	 * @return int The insert ID.
	 */
	public static function storeVarDump( AbuseFilterVariableHolder $vars, $global = false ) {
		global $wgCompressRevisions;

		// Get all variables yet set and compute old and new wikitext if not yet done
		// as those are needed for the diff view on top of the abuse log pages
		$vars = $vars->dumpAllVars( [ 'old_wikitext', 'new_wikitext' ] );

		// Vars is an array with native PHP data types (non-objects) now
		$text = FormatJson::encode( $vars );
		$flags = [ 'utf-8' ];

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
	 * @return AbuseFilterVariableHolder
	 */
	public static function loadVarDump( $stored_dump ) : AbuseFilterVariableHolder {
		// Backward compatibility for (old) blobs stored in the abuse_filter_log table
		if ( !is_numeric( $stored_dump ) &&
			substr( $stored_dump, 0, strlen( 'stored-text:' ) ) !== 'stored-text:' &&
			substr( $stored_dump, 0, strlen( 'tt:' ) ) !== 'tt:'
		) {
			$data = unserialize( $stored_dump );
			return is_array( $data ) ? AbuseFilterVariableHolder::newFromArray( $data ) : $data;
		}

		if ( is_numeric( $stored_dump ) ) {
			$text_id = (int)$stored_dump;
		} elseif ( strpos( $stored_dump, 'stored-text:' ) !== false ) {
			$text_id = (int)str_replace( 'stored-text:', '', $stored_dump );
		} elseif ( strpos( $stored_dump, 'tt:' ) !== false ) {
			$text_id = (int)str_replace( 'tt:', '', $stored_dump );
		} else {
			throw new LogicException( "Cannot understand format: $stored_dump" );
		}

		$dbr = wfGetDB( DB_REPLICA );

		$text_row = $dbr->selectRow(
			'text',
			[ 'old_text', 'old_flags' ],
			[ 'old_id' => $text_id ],
			__METHOD__
		);

		if ( !$text_row ) {
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->warning( __METHOD__ . ": no text row found for input $stored_dump." );
			return new AbuseFilterVariableHolder;
		}

		$flags = $text_row->old_flags === '' ? [] : explode( ',', $text_row->old_flags );
		$text = $text_row->old_text;

		if ( in_array( 'external', $flags ) ) {
			$text = ExternalStore::fetchFromURL( $text );
		}

		if ( in_array( 'gzip', $flags ) ) {
			$text = gzinflate( $text );
		}

		$obj = FormatJson::decode( $text, true );
		if ( $obj === null ) {
			// Temporary code until all rows will be JSON-encoded
			$obj = unserialize( $text );
		}

		if ( in_array( 'nativeDataArray', $flags ) ||
			// Temporary condition: we don't add the flag anymore, but the updateVarDump
			// script could be still running and we cannot assume that this branch is the default.
			( is_array( $obj ) && array_key_exists( 'action', $obj ) )
		) {
			$vars = $obj;
			$obj = AbuseFilterVariableHolder::newFromArray( $vars );
			$obj->translateDeprecatedVars();
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
		foreach ( $tagsByAction as $actionID => $tags ) {
			if ( !isset( self::$tagsToSet[ $actionID ] ) ) {
				self::$tagsToSet[ $actionID ] = $tags;
			} else {
				self::$tagsToSet[ $actionID ] = array_unique(
					array_merge( self::$tagsToSet[ $actionID ], $tags )
				);
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
	 * Gets the autopromotion block status for the given user
	 *
	 * @param User $target
	 * @return int
	 */
	public static function getAutoPromoteBlockStatus( User $target ) {
		$store = ObjectCache::getInstance( 'db-replicated' );

		return (int)$store->get( self::autoPromoteBlockKey( $store, $target ) );
	}

	/**
	 * Blocks autopromotion for the given user
	 *
	 * @param User $target
	 * @param string $msg The message to show in the log
	 * @param int $duration Duration for which autopromotion is blocked, in seconds
	 * @return bool True on success, false on failure
	 */
	public static function blockAutoPromote( User $target, $msg, int $duration ) {
		$store = ObjectCache::getInstance( 'db-replicated' );
		if ( !$store->set(
			self::autoPromoteBlockKey( $store, $target ),
			1,
			$duration
		) ) {
			// Failed to set key
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->warning(
				"Failed to block autopromotion for $target. Error: " . $store->getLastError()
			);
			return false;
		}

		$logEntry = new ManualLogEntry( 'rights', 'blockautopromote' );
		$performer = self::getFilterUser();
		$logEntry->setPerformer( $performer );
		$logEntry->setTarget( $target->getUserPage() );

		$logEntry->setParameters( [
			'7::duration' => $duration,
			// These parameters are unused in our message, but some parts of the code check for them
			'4::oldgroups' => [],
			'5::newgroups' => []
		] );
		$logEntry->setComment( $msg );
		$logEntry->publish( $logEntry->insert() );

		return true;
	}

	/**
	 * Unblocks autopromotion for the given user
	 *
	 * @param User $target
	 * @param User $performer
	 * @param string $msg The message to show in the log
	 * @return bool True on success, false on failure
	 */
	public static function unblockAutopromote( User $target, User $performer, $msg ) {
		// Immediately expire (delete) the key, failing if it does not exist
		$store = ObjectCache::getInstance( 'db-replicated' );
		$expireAt = time() - $store::TTL_HOUR;
		if ( !$store->changeTTL( self::autoPromoteBlockKey( $store, $target ), $expireAt ) ) {
			// Key did not exist to begin with; nothing to do
			return false;
		}

		$logEntry = new ManualLogEntry( 'rights', 'restoreautopromote' );
		$logEntry->setTarget( Title::makeTitle( NS_USER, $target->getName() ) );
		$logEntry->setComment( $msg );
		// These parameters are unused in our message, but some parts of the code check for them
		$logEntry->setParameters( [
			'4::oldgroups' => [],
			'5::newgroups' => []
		] );
		$logEntry->setPerformer( $performer );
		$logEntry->publish( $logEntry->insert() );

		return true;
	}

	/**
	 * @param BagOStuff $store
	 * @param User $target
	 * @return string
	 * @internal
	 */
	public static function autoPromoteBlockKey( BagOStuff $store, User $target ) {
		return $store->makeKey( 'abusefilter', 'block-autopromote', $target->getId() );
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
	 * @return User
	 */
	public static function getFilterUser() : User {
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
		'@phan-var User $user';

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
	public static function getAceConfig( bool $canEdit ): array {
		$keywordsManager = AbuseFilterServices::getKeywordsManager();
		$values = $keywordsManager->getBuilderValues();
		$deprecatedVars = $keywordsManager->getDeprecatedVariables();

		$builderVariables = implode( '|', array_keys( $values['vars'] ) );
		$builderFunctions = implode( '|', array_keys( AbuseFilterParser::FUNCTIONS ) );
		// AbuseFilterTokenizer::KEYWORDS also includes constants (true, false and null),
		// but Ace redefines these constants afterwards so this will not be an issue
		$builderKeywords = implode( '|', AbuseFilterTokenizer::KEYWORDS );
		// Extract operators from tokenizer like we do in AbuseFilterParserTest
		$operators = implode( '|', array_map( function ( $op ) {
			return preg_quote( $op, '/' );
		}, AbuseFilterTokenizer::OPERATORS ) );
		$deprecatedVariables = implode( '|', array_keys( $deprecatedVars ) );
		$disabledVariables = implode( '|', array_keys( $keywordsManager->getDisabledVariables() ) );

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
	 * Checks whether user input for the filter editing form is valid and if so saves the filter.
	 * Returns a Status object which can be:
	 *  - Good with [ new_filter_id, history_id ] as value if the filter was successfully saved
	 *  - Good with value = false if everything went fine but the filter is unchanged
	 *  - OK with errors if a validation error occurred
	 *  - Fatal in case of a permission-related error
	 *
	 * @param User $user
	 * @param int|null $filter
	 * @param stdClass $newRow
	 * @param array $actions
	 * @param stdClass $originalRow
	 * @param array $originalActions
	 * @param IDatabase $dbw DB_MASTER Where the filter should be saved
	 * @param Config $config
	 * @return Status
	 * @internal
	 */
	public static function saveFilter(
		User $user,
		?int $filter,
		stdClass $newRow,
		array $actions,
		stdClass $originalRow,
		array $originalActions,
		IDatabase $dbw,
		Config $config
	) {
		$afPermManager = AbuseFilterServices::getPermissionManager();
		$validationStatus = Status::newGood();

		// Check the syntax
		$syntaxerr = self::getDefaultParser()->checkSyntax( $newRow->af_pattern );
		if ( $syntaxerr !== true ) {
			$validationStatus->error( 'abusefilter-edit-badsyntax', $syntaxerr[0] );
			return $validationStatus;
		}
		// Check for missing required fields (title and pattern)
		$missing = [];
		if ( !$newRow->af_pattern || trim( $newRow->af_pattern ) === '' ) {
			$missing[] = new Message( 'abusefilter-edit-field-conditions' );
		}
		if ( !$newRow->af_public_comments ) {
			$missing[] = new Message( 'abusefilter-edit-field-description' );
		}
		if ( count( $missing ) !== 0 ) {
			$validationStatus->error(
				'abusefilter-edit-missingfields',
				Message::listParam( $missing, 'comma' )
			);
			return $validationStatus;
		}

		// Don't allow setting as deleted an active filter
		if ( $newRow->af_enabled && $newRow->af_deleted ) {
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

		$availableActions = array_keys(
			array_filter( $config->get( 'AbuseFilterActions' ) )
		);
		$differences = self::compareVersions(
			[ $newRow, $actions ],
			[ $originalRow, $originalActions ],
			$availableActions
		);

		// Don't allow adding a new global rule, or updating a
		// rule that is currently global, without permissions.
		if (
			!$afPermManager->canEditFilter( $user, $newRow ) ||
			!$afPermManager->canEditFilter( $user, $originalRow )
		) {
			$validationStatus->fatal( 'abusefilter-edit-notallowed-global' );
			return $validationStatus;
		}

		// Don't allow custom messages on global rules
		if ( $newRow->af_global == 1 && (
				( isset( $actions['warn'] ) && $actions['warn'][0] !== 'abusefilter-warning' ) ||
				( isset( $actions['disallow'] ) && $actions['disallow'][0] !== 'abusefilter-disallowed' )
		) ) {
			$validationStatus->fatal( 'abusefilter-edit-notallowed-global-custom-msg' );
			return $validationStatus;
		}

		$wasGlobal = (bool)$originalRow->af_global;

		// Check for non-changes
		if ( !count( $differences ) ) {
			$validationStatus->setResult( true, false );
			return $validationStatus;
		}

		// Check for restricted actions
		$restrictions = $config->get( 'AbuseFilterRestrictions' );
		if ( count( array_intersect_key(
				array_filter( $restrictions ),
				array_merge( $actions, $originalActions )
			) )
			&& !$afPermManager->canEditFilterWithRestrictedActions( $user )
		) {
			$validationStatus->error( 'abusefilter-edit-restricted' );
			return $validationStatus;
		}

		// Everything went fine, so let's save the filter
		list( $new_id, $history_id ) =
			self::doSaveFilter( $user, $newRow, $differences, $filter, $actions, $wasGlobal, $dbw, $config );
		$validationStatus->setResult( true, [ $new_id, $history_id ] );
		return $validationStatus;
	}

	/**
	 * Saves new filter's info to DB
	 *
	 * @param User $user
	 * @param stdClass $newRow
	 * @param array $differences
	 * @param int|null $filter
	 * @param array $actions
	 * @param bool $wasGlobal
	 * @param IDatabase $dbw DB_MASTER where the filter will be saved
	 * @param Config $config
	 * @return int[] first element is new ID, second is history ID
	 */
	private static function doSaveFilter(
		User $user,
		$newRow,
		$differences,
		?int $filter,
		$actions,
		$wasGlobal,
		IDatabase $dbw,
		Config $config
	) {
		// Convert from object to array
		$newRow = get_object_vars( $newRow );

		// Set last modifier.
		$newRow['af_timestamp'] = $dbw->timestamp();
		$newRow['af_user'] = $user->getId();
		$newRow['af_user_text'] = $user->getName();

		$dbw->startAtomic( __METHOD__ );

		// Insert MAIN row.
		$is_new = $filter === null;
		$new_id = $filter;

		// Reset throttled marker, if we're re-enabling it.
		$newRow['af_throttled'] = $newRow['af_throttled'] && !$newRow['af_enabled'];
		$newRow['af_id'] = $new_id;

		// T67807: integer 1's & 0's might be better understood than booleans
		$newRow['af_enabled'] = (int)$newRow['af_enabled'];
		$newRow['af_hidden'] = (int)$newRow['af_hidden'];
		$newRow['af_throttled'] = (int)$newRow['af_throttled'];
		$newRow['af_deleted'] = (int)$newRow['af_deleted'];
		$newRow['af_global'] = (int)$newRow['af_global'];

		$dbw->replace( 'abuse_filter', 'af_id', $newRow, __METHOD__ );

		if ( $is_new ) {
			$new_id = $dbw->insertId();
		}
		'@phan-var int $new_id';

		$availableActions = $config->get( 'AbuseFilterActions' );
		$actionsRows = [];
		foreach ( array_filter( $availableActions ) as $action => $_ ) {
			// Check if it's set
			$enabled = isset( $actions[$action] );

			if ( $enabled ) {
				$parameters = $actions[$action];
				if ( $action === 'throttle' && $parameters[0] === null ) {
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

		foreach ( self::HISTORY_MAPPINGS as $af_col => $afh_col ) {
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
		if ( $filter !== null ) {
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
		$subtype = $filter === null ? 'create' : 'modify';
		$logEntry = new ManualLogEntry( 'abusefilter', $subtype );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( SpecialAbuseFilter::getTitleForSubpage( (string)$new_id ) );
		$logEntry->setParameters( [
			'historyId' => $history_id,
			'newId' => $new_id
		] );
		$logid = $logEntry->insert( $dbw );
		$logEntry->publish( $logid );

		// Purge the tag list cache so the fetchAllTags hook applies tag changes
		if ( isset( $actions['tag'] ) ) {
			AbuseFilterHooks::purgeTagCache();
		}

		AbuseFilterServices::getFilterProfiler()->resetFilterProfile( $new_id );
		return [ $new_id, $history_id ];
	}

	/**
	 * Each version is expected to be an array( $row, $actions )
	 * Returns an array of fields that are different.
	 *
	 * @param array $version_1
	 * @param array $version_2
	 * @param string[] $availableActions All actions enabled in the AF config
	 *
	 * @return array
	 */
	public static function compareVersions(
		array $version_1,
		array $version_2,
		array $availableActions
	) {
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

		foreach ( $availableActions as $action ) {
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

		foreach ( self::HISTORY_MAPPINGS as $af_col => $afh_col ) {
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
	 * @param MessageLocalizer|null $localizer
	 * @return string HTML
	 */
	public static function getActionDisplay( $action, MessageLocalizer $localizer = null ) {
		$msgCallback = $localizer != null ? [ $localizer, 'msg' ] : 'wfMessage';
		// Give grep a chance to find the usages:
		// abusefilter-action-tag, abusefilter-action-throttle, abusefilter-action-warn,
		// abusefilter-action-blockautopromote, abusefilter-action-block, abusefilter-action-degroup,
		// abusefilter-action-rangeblock, abusefilter-action-disallow
		$msg = $msgCallback( "abusefilter-action-$action" );
		return $msg->isDisabled() ? htmlspecialchars( $action ) : $msg->escaped();
	}

	/**
	 * @param mixed $var
	 * @param string $indent
	 * @return string
	 */
	public static function formatVar( $var, string $indent = '' ) {
		if ( $var === [] ) {
			return '[]';
		} elseif ( is_array( $var ) ) {
			$ret = '[';
			$indent .= "\t";
			foreach ( $var as $key => $val ) {
				$ret .= "\n$indent" . self::formatVar( $key, $indent ) .
					' => ' . self::formatVar( $val, $indent ) . ',';
			}
			// Strip trailing commas
			return substr( $ret, 0, -1 ) . "\n" . substr( $indent, 0, -1 ) . ']';
		} elseif ( is_string( $var ) ) {
			// Don't escape the string (specifically backslashes) to avoid displaying wrong stuff
			return "'$var'";
		} elseif ( $var === null ) {
			return 'null';
		} elseif ( is_float( $var ) ) {
			// Don't let float precision produce weirdness
			return (string)$var;
		}
		return var_export( $var, true );
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

		$keywordsManager = AbuseFilterServices::getKeywordsManager();
		// Now, build the body of the table.
		foreach ( $vars as $key => $value ) {
			$key = strtolower( $key );

			$varMsgKey = $keywordsManager->getMessageKeyForVar( $key );
			if ( $varMsgKey ) {
				$keyDisplay = $context->msg( $varMsgKey )->parse() .
					' ' . Html::element( 'code', [], $context->msg( 'parentheses' )->rawParams( $key )->text() );
			} else {
				$keyDisplay = Html::element( 'code', [], $key );
			}

			if ( $value === null ) {
				$value = '';
			}
			$value = Html::element(
				'div',
				[ 'class' => 'mw-abuselog-var-value' ],
				self::formatVar( $value )
			);

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
	 * @return string|null
	 */
	public static function getGlobalFilterDescription( $filterID ) : ?string {
		global $wgAbuseFilterCentralDB;

		if ( !$wgAbuseFilterCentralDB ) {
			return null;
		}

		static $cache = [];
		if ( isset( $cache[$filterID] ) ) {
			return $cache[$filterID];
		}

		$fdb = self::getCentralDB( DB_REPLICA );

		$cache[$filterID] = (string)$fdb->selectField(
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
	 * @todo Move elsewhere. VariableGenerator is a good candidate
	 *
	 * @param RevisionRecord|null $revision a valid revision
	 * @param User $user the user instance to check for privileged access
	 * @return string the content of the revision as some kind of string,
	 *        or an empty string if it can not be found
	 */
	public static function revisionToString( ?RevisionRecord $revision, User $user ) {
		if ( !$revision ) {
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
	 * @todo Move elsewhere. VariableGenerator is a good candidate
	 *
	 * @param Content $content
	 *
	 * @return string a suitable string representation of the content.
	 */
	public static function contentToString( Content $content ) {
		$text = null;

		$hookRunner = AbuseFilterHookRunner::getRunner();
		if ( $hookRunner->onAbuseFilterContentToString(
			$content,
			$text
		) ) {
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

	/**
	 * Get a parser instance using default options. This should mostly be intended as a wrapper
	 * around $wgAbuseFilterParserClass and for choosing the right type of cache. It also has the
	 * benefit of typehinting the return value, thus making IDEs and static analysis tools happier.
	 *
	 * @param AbuseFilterVariableHolder|null $vars
	 * @return AbuseFilterParser
	 * @throws InvalidArgumentException if $wgAbuseFilterParserClass is not valid
	 */
	public static function getDefaultParser(
		AbuseFilterVariableHolder $vars = null
	) : AbuseFilterParser {
		global $wgAbuseFilterParserClass;

		$allowedValues = [ AbuseFilterParser::class, AbuseFilterCachingParser::class ];
		if ( !in_array( $wgAbuseFilterParserClass, $allowedValues ) ) {
			throw new InvalidArgumentException(
				"Invalid value $wgAbuseFilterParserClass for \$wgAbuseFilterParserClass."
			);
		}

		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$cache = ObjectCache::getLocalServerInstance( 'hash' );
		$logger = LoggerFactory::getInstance( 'AbuseFilter' );
		$keywordsManager = AbuseFilterServices::getKeywordsManager();
		return new $wgAbuseFilterParserClass( $contLang, $cache, $logger, $keywordsManager, $vars );
	}

	/**
	 * Shortcut for checking whether $user can view the given revision, with mask
	 *  SUPPRESSED_ALL.
	 *
	 * @note This assumes that a revision with the given ID exists
	 *
	 * @param RevisionRecord $revRec
	 * @param User $user
	 * @return bool
	 */
	public static function userCanViewRev( RevisionRecord $revRec, User $user ) : bool {
		return $revRec->audienceCan(
			RevisionRecord::SUPPRESSED_ALL,
			RevisionRecord::FOR_THIS_USER,
			$user
		);
	}
}
