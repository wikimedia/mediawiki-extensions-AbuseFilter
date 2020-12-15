<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\BlobAccessException;
use MediaWiki\Storage\BlobStore;

/**
 * This class contains most of the business logic of AbuseFilter. It consists of
 * static functions for generic use (mostly utility functions).
 */
class AbuseFilter {

	/**
	 * @var array IDs of logged filters like [ page title => [ 'local' => [ids], 'global' => [ids] ] ].
	 * @fixme avoid global state
	 */
	public static $logIds = [];

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
	 * Store a var dump to a BlobStore.
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param bool $global
	 *
	 * @return string Address of the record
	 */
	public static function storeVarDump( AbuseFilterVariableHolder $vars, $global = false ) {
		global $wgAbuseFilterCentralDB;

		// Get all variables yet set and compute old and new wikitext if not yet done
		// as those are needed for the diff view on top of the abuse log pages
		$vars = $vars->dumpAllVars( [ 'old_wikitext', 'new_wikitext' ] );

		// Vars is an array with native PHP data types (non-objects) now
		$text = FormatJson::encode( $vars );

		$blobStoreFactory = \MediaWiki\MediaWikiServices::getInstance()->getBlobStoreFactory();
		$dbDomain = $global ? $wgAbuseFilterCentralDB : false;
		$blobStore = $blobStoreFactory->newBlobStore( $dbDomain );

		$hints = [
			BlobStore::DESIGNATION_HINT => 'AbuseFilter',
			BlobStore::MODEL_HINT => 'AbuseFilter',
		];
		return $blobStore->storeBlob( $text, $hints );
	}

	/**
	 * Retrieve a var dump from a BlobStore.
	 *
	 * @param string $address
	 *
	 * @return AbuseFilterVariableHolder
	 */
	public static function loadVarDump( string $address ) : AbuseFilterVariableHolder {
		$blobStore = \MediaWiki\MediaWikiServices::getInstance()->getBlobStore();

		try {
			$blob = $blobStore->getBlob( $address );
		} catch ( BlobAccessException $ex ) {
			return new AbuseFilterVariableHolder;
		}

		$vars = FormatJson::decode( $blob, true );
		$obj = AbuseFilterVariableHolder::newFromArray( $vars );
		$obj->translateDeprecatedVars();
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
