<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\Rdbms\IDatabase;

/**
 * This class contains most of the business logic of AbuseFilter. It consists of
 * static functions for generic use (mostly utility functions).
 */
class AbuseFilter {

	/** @var string The prefix to use for global filters */
	public const GLOBAL_FILTER_PREFIX = 'global-';

	/**
	 * @var array IDs of logged filters like [ page title => [ 'local' => [ids], 'global' => [ids] ] ].
	 * @fixme avoid global state
	 */
	public static $logIds = [];

	/**
	 * @var array Actions which may harm the user. Only retrieve via self::getDangerousActions
	 * @internal
	 */
	public const DANGEROUS_ACTIONS = [
		'block',
		'blockautopromote',
		'degroup',
		'rangeblock'
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
	 * Get an array of action which harm the user.
	 *
	 * @return string[]
	 * @internal Temporary hack
	 */
	public static function getDangerousActions() : array {
		static $actions = null;

		if ( !$actions ) {
			$extActions = [];
			AbuseFilterHookRunner::getRunner()->onAbuseFilterGetDangerousActions( $extActions );
			$actions = array_unique(
				array_merge( $extActions, self::DANGEROUS_ACTIONS )
			);
		}
		return $actions;
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
		$parser = AbuseFilterServices::getParserFactory()->newParser( $vars );
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
	 * @return array[][]
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
			$consequences = self::loadConsequencesFromDB( $dbr, $localFilters, '' );
		}

		if ( count( $globalFilters ) ) {
			$consequences += self::loadConsequencesFromDB(
				AbuseFilterServices::getCentralDBManager()->getConnection( DB_REPLICA ),
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
	 * @return array[][]
	 */
	private static function loadConsequencesFromDB( IDatabase $dbr, $filters, $prefix = '' ) {
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
		foreach ( $res as $row ) {
			if ( $row->af_throttled
				&& in_array( $row->afa_consequence, self::getDangerousActions() )
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
				continue;
			} else {
				$actionsByFilter[$prefix . $row->afa_filter][$row->afa_consequence] =
					array_filter( explode( "\n", $row->afa_parameters ) );
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
			$dbw = AbuseFilterServices::getCentralDBManager()->getConnection( DB_MASTER );
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
			is_array( $obj )
		) {
			$vars = $obj;
			$obj = AbuseFilterVariableHolder::newFromArray( $vars );
			$obj->translateDeprecatedVars();
		}

		return $obj;
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
