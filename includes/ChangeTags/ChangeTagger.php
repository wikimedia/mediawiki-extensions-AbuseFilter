<?php

namespace MediaWiki\Extension\AbuseFilter\ChangeTags;

use LogicException;
use MediaWiki\Linker\LinkTarget;
use RecentChange;
use TitleValue;

/**
 * Class that collects change tags to be later applied
 * @internal This interface should be improved and is not ready for external use
 */
class ChangeTagger {
	public const SERVICE_NAME = 'AbuseFilterChangeTagger';

	/** @var array (Persistent) map of (action ID => string[]) */
	private static $tagsToSet = [];

	/**
	 * @var ChangeTagsManager
	 */
	private $changeTagsManager;

	/**
	 * @param ChangeTagsManager $changeTagsManager
	 */
	public function __construct( ChangeTagsManager $changeTagsManager ) {
		$this->changeTagsManager = $changeTagsManager;
	}

	/**
	 * Clear any buffered tag
	 */
	public function clearBuffer() : void {
		self::$tagsToSet = [];
	}

	/**
	 * @param array $actionSpecs
	 * @phan-param array{action:string,username:string,target:LinkTarget,accountname?:?string} $actionSpecs
	 */
	public function addConditionsLimitTag( array $actionSpecs ) : void {
		$this->addTags( $actionSpecs, [ $this->changeTagsManager->getCondsLimitTag() ] );
	}

	/**
	 * @param array $actionSpecs
	 * @phan-param array{action:string,username:string,target:LinkTarget,accountname?:?string} $actionSpecs
	 * @param array $tags
	 */
	public function addTags( array $actionSpecs, array $tags ) : void {
		$id = $this->getActionID(
			$actionSpecs['action'],
			$actionSpecs['username'],
			$actionSpecs['target'],
			$actionSpecs['accountname'] ?? null
		);
		$this->bufferTagsToSetByAction( [ $id => $tags ] );
	}

	/**
	 * @param string[][] $tagsByAction Map of (string => string[])
	 */
	private function bufferTagsToSetByAction( array $tagsByAction ) : void {
		foreach ( $tagsByAction as $actionID => $tags ) {
			self::$tagsToSet[ $actionID ] = array_unique(
				array_merge( self::$tagsToSet[ $actionID ] ?? [], $tags )
			);
		}
	}

	/**
	 * @param string $id
	 * @param bool $clear
	 * @return array
	 */
	private function getTagsForID( string $id, bool $clear = true ) : array {
		$val = self::$tagsToSet[$id] ?? [];
		if ( $clear ) {
			unset( self::$tagsToSet[$id] );
		}
		return $val;
	}

	/**
	 * @param RecentChange $recentChange
	 * @param bool $clear
	 * @return array
	 */
	public function getTagsForRecentChange( RecentChange $recentChange, bool $clear = true ) : array {
		$id = $this->getIDFromRecentChange( $recentChange );
		return $this->getTagsForID( $id, $clear );
	}

	/**
	 * @param RecentChange $recentChange
	 * @return string
	 */
	private function getIDFromRecentChange( RecentChange $recentChange ) : string {
		$title = new TitleValue(
			$recentChange->getAttribute( 'rc_namespace' ),
			$recentChange->getAttribute( 'rc_title' )
		);

		$logType = $recentChange->getAttribute( 'rc_log_type' ) ?: 'edit';
		if ( $logType === 'newusers' ) {
			$action = $recentChange->getAttribute( 'rc_log_action' ) === 'autocreate' ?
				'autocreateaccount' :
				'createaccount';
		} else {
			$action = $logType;
		}
		return $this->getActionID(
			$action,
			$recentChange->getAttribute( 'rc_user_text' ),
			$title,
			$recentChange->getAttribute( 'rc_user_text' )
		);
	}

	/**
	 * Get a unique identifier for the given action
	 *
	 * @param string $action Action being filtered (e.g. 'edit' or 'createaccount')
	 * @param string $username Of the context user, will be ignored in favour of the specified account name
	 *   for account creations.
	 * @param LinkTarget $title Where the current action is executed. This is the user page
	 *   for account creations.
	 * @param string|null $accountname Required if the action is an account creation
	 * @return string
	 */
	private function getActionID(
		string $action,
		string $username,
		LinkTarget $title,
		?string $accountname = null
	) : string {
		if ( strpos( $action, 'createaccount' ) !== false ) {
			if ( $accountname === null ) {
				// @codeCoverageIgnoreStart
				throw new LogicException( '$accountname required for account creations' );
				// @codeCoverageIgnoreEnd
			}
			$username = $accountname;
			$title = new TitleValue( NS_USER, $username );
		}

		// Use a character that's not allowed in titles and usernames
		$glue = '|';
		return implode(
			$glue,
			[
				$title->getNamespace() . ':' . $title->getText(),
				$username,
				$action
			]
		);
	}
}
