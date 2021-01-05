<?php

namespace MediaWiki\Extension\AbuseFilter;

use ApiMessage;
use Content;
use DeferredUpdates;
use EchoAttributeManager;
use EchoUserLocator;
use IContextSource;
use InvalidArgumentException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\UserIdentity;
use ParserOutput;
use RecentChange;
use RenameuserSQL;
use Status;
use Title;
use UploadBase;
use User;
use WikiPage;

class AbuseFilterHooks {

	/**
	 * Called right after configuration has been loaded.
	 * @codeCoverageIgnore Mainly deprecation warnings and other things that can be tested by running the updater
	 */
	public static function onRegistration() {
		global $wgAuthManagerAutoConfig, $wgActionFilteredLogs, $wgAbuseFilterProfile,
			$wgAbuseFilterProfiling, $wgAbuseFilterPrivateLog, $wgAbuseFilterForceSummary,
			$wgGroupPermissions, $wgAbuseFilterRestrictions, $wgAbuseFilterDisallowGlobalLocalBlocks,
			$wgAbuseFilterActionRestrictions, $wgAbuseFilterLocallyDisabledGlobalActions,
			$wgAbuseFilterAflFilterMigrationStage;

		// @todo Remove this in a future release (added in 1.33)
		if ( isset( $wgAbuseFilterProfile ) || isset( $wgAbuseFilterProfiling ) ) {
			wfWarn( '$wgAbuseFilterProfile and $wgAbuseFilterProfiling have been removed and ' .
				'profiling is now enabled by default.' );
		}

		if ( isset( $wgAbuseFilterPrivateLog ) ) {
			global $wgAbuseFilterLogPrivateDetailsAccess;
			$wgAbuseFilterLogPrivateDetailsAccess = $wgAbuseFilterPrivateLog;
			wfWarn( '$wgAbuseFilterPrivateLog has been renamed to $wgAbuseFilterLogPrivateDetailsAccess. ' .
				'Please make the change in your settings; the format is identical.'
			);
		}
		if ( isset( $wgAbuseFilterForceSummary ) ) {
			global $wgAbuseFilterPrivateDetailsForceReason;
			$wgAbuseFilterPrivateDetailsForceReason = $wgAbuseFilterForceSummary;
			wfWarn( '$wgAbuseFilterForceSummary has been renamed to ' .
				'$wgAbuseFilterPrivateDetailsForceReason. Please make the change in your settings; ' .
				'the format is identical.'
			);
		}

		$found = false;
		foreach ( $wgGroupPermissions as &$perms ) {
			if ( array_key_exists( 'abusefilter-private', $perms ) ) {
				$perms['abusefilter-privatedetails'] = $perms[ 'abusefilter-private' ];
				unset( $perms[ 'abusefilter-private' ] );
				$found = true;
			}
			if ( array_key_exists( 'abusefilter-private-log', $perms ) ) {
				$perms['abusefilter-privatedetails-log'] = $perms[ 'abusefilter-private-log' ];
				unset( $perms[ 'abusefilter-private-log' ] );
				$found = true;
			}
		}
		unset( $perms );

		if ( $found ) {
			wfWarn( 'The group permissions "abusefilter-private-log" and "abusefilter-private" have ' .
				'been renamed, respectively, to "abusefilter-privatedetails-log" and ' .
				'"abusefilter-privatedetails". Please update the names in your settings.'
			);
		}

		// @todo Remove this in a future release (added in 1.36)
		if ( isset( $wgAbuseFilterDisallowGlobalLocalBlocks ) ) {
			wfWarn( '$wgAbuseFilterDisallowGlobalLocalBlocks has been removed and replaced by ' .
				'$wgAbuseFilterLocallyDisabledGlobalActions. You can now specify which actions to disable. ' .
				'If you had set the former to true, you should set to true all of the actions in ' .
				'$wgAbuseFilterRestrictions (if you were manually setting the variable) or ' .
				'ConsequencesRegistry::DANGEROUS_ACTIONS. ' .
				'If you had set it to false (or left the default), just remove it from your wiki settings.'
			);
			if ( $wgAbuseFilterDisallowGlobalLocalBlocks === true ) {
				$wgAbuseFilterLocallyDisabledGlobalActions = [
					'throttle' => false,
					'warn' => false,
					'disallow' => false,
					'blockautopromote' => true,
					'block' => true,
					'rangeblock' => true,
					'degroup' => true,
					'tag' => false
				];
			}
		}

		// @todo Remove this in a future release (added in 1.36)
		if ( isset( $wgAbuseFilterRestrictions ) ) {
			wfWarn( '$wgAbuseFilterRestrictions has been renamed to $wgAbuseFilterActionRestrictions.' );
			$wgAbuseFilterActionRestrictions = $wgAbuseFilterRestrictions;
		}

		$wgAuthManagerAutoConfig['preauth'][AbuseFilterPreAuthenticationProvider::class] = [
			'class' => AbuseFilterPreAuthenticationProvider::class,
			// Run after normal preauth providers to keep the log cleaner
			'sort' => 5,
		];

		$wgActionFilteredLogs['suppress'] = array_merge(
			$wgActionFilteredLogs['suppress'],
			// Message: log-action-filter-suppress-abuselog
			[ 'abuselog' => [ 'hide-afl', 'unhide-afl' ] ]
		);
		$wgActionFilteredLogs['rights'] = array_merge(
			$wgActionFilteredLogs['rights'],
			// Messages: log-action-filter-rights-blockautopromote,
			// log-action-filter-rights-restoreautopromote
			[
				'blockautopromote' => [ 'blockautopromote' ],
				'restoreautopromote' => [ 'restoreautopromote' ]
			]
		);

		if ( strpos( $wgAbuseFilterAflFilterMigrationStage, 'Bogus value' ) !== false ) {
			// Set the value here, because extension.json is very unfriendly towards PHP constants
			$wgAbuseFilterAflFilterMigrationStage = SCHEMA_COMPAT_WRITE_BOTH | SCHEMA_COMPAT_READ_OLD;
		}
		$stage = $wgAbuseFilterAflFilterMigrationStage;
		// Validation for the afl_filter migration stage, stolen from ActorMigration
		if ( ( $stage & SCHEMA_COMPAT_WRITE_BOTH ) === 0 ) {
			throw new InvalidArgumentException( '$stage must include a write mode' );
		}
		if ( ( $stage & SCHEMA_COMPAT_READ_BOTH ) === 0 ) {
			throw new InvalidArgumentException( '$stage must include a read mode' );
		}
		if ( ( $stage & SCHEMA_COMPAT_READ_BOTH ) === SCHEMA_COMPAT_READ_BOTH ) {
			throw new InvalidArgumentException( 'Cannot read both schemas' );
		}
		if ( ( $stage & SCHEMA_COMPAT_READ_OLD ) && !( $stage & SCHEMA_COMPAT_WRITE_OLD ) ) {
			throw new InvalidArgumentException( 'Cannot read the old schema without also writing it' );
		}
		if ( ( $stage & SCHEMA_COMPAT_READ_NEW ) && !( $stage & SCHEMA_COMPAT_WRITE_NEW ) ) {
			throw new InvalidArgumentException( 'Cannot read the new schema without also writing it' );
		}
	}

	/**
	 * Entry point for the EditFilterMergedContent hook.
	 *
	 * @param IContextSource $context the context of the edit
	 * @param Content $content the new Content generated by the edit
	 * @param Status $status Error message to return
	 * @param string $summary Edit summary for page
	 * @param User $user the user performing the edit
	 * @param bool $minoredit whether this is a minor edit according to the user.
	 * @param string $slot slot role for the content
	 */
	public static function onEditFilterMergedContent( IContextSource $context, Content $content,
		Status $status, $summary, User $user, $minoredit, $slot = SlotRecord::MAIN
	) {
		$startTime = microtime( true );

		$filterResult = self::filterEdit( $context, $user, $content, $summary, $slot );

		if ( !$filterResult->isOK() ) {
			// Produce a useful error message for API edits
			$filterResultApi = self::getApiStatus( $filterResult );
			$status->merge( $filterResultApi );
		}
		MediaWikiServices::getInstance()->getStatsdDataFactory()
			->timing( 'timing.editAbuseFilter', microtime( true ) - $startTime );
	}

	/**
	 * Implementation for EditFilterMergedContent hook.
	 *
	 * @param IContextSource $context the context of the edit
	 * @param User $user
	 * @param Content $content the new Content generated by the edit
	 * @param string $summary Edit summary for page
	 * @param string $slot slot role for the content
	 * @return Status
	 */
	public static function filterEdit(
		IContextSource $context,
		User $user,
		Content $content,
		$summary, $slot = SlotRecord::MAIN
	) : Status {
		$revUpdater = AbuseFilterServices::getEditRevUpdater();
		$revUpdater->clearLastEditPage();

		// @todo is there any real point in passing this in?
		$text = AbuseFilterServices::getTextExtractor()->contentToString( $content );

		$title = $context->getTitle();
		$logger = LoggerFactory::getInstance( 'AbuseFilter' );
		if ( $title === null ) {
			// T144265: This *should* never happen.
			$logger->warning( __METHOD__ . ' received a null title.' );
			return Status::newGood();
		}
		if ( !$title->canExist() ) {
			// This also should be handled in EditPage or whoever is calling the hook.
			$logger->warning( __METHOD__ . ' received a Title that cannot exist.' );
			// Note that if the title cannot exist, there's no much point in filtering the edit anyway
			return Status::newGood();
		}

		$page = $context->getWikiPage();

		$builder = AbuseFilterServices::getVariableGeneratorFactory()->newRunGenerator( $user, $title );
		$vars = $builder->getEditVars( $content, $text, $summary, $slot, $page );
		if ( $vars === null ) {
			// We don't have to filter the edit
			return Status::newGood();
		}
		$runnerFactory = AbuseFilterServices::getFilterRunnerFactory();
		$runner = $runnerFactory->newRunner( $user, $title, $vars, 'default' );
		$filterResult = $runner->run();
		if ( !$filterResult->isOK() ) {
			return $filterResult;
		}

		$revUpdater->setLastEditPage( $page );

		return Status::newGood();
	}

	/**
	 * @param Status $status Error message details
	 * @return Status Status containing the same error messages with extra data for the API
	 */
	private static function getApiStatus( Status $status ) {
		$allActionsTaken = $status->getValue();
		$statusForApi = Status::newGood();

		foreach ( $status->getErrors() as $error ) {
			list( $filterDescription, $filter ) = $error['params'];
			$actionsTaken = $allActionsTaken[ $filter ];

			$code = ( $actionsTaken === [ 'warn' ] ) ? 'abusefilter-warning' : 'abusefilter-disallowed';
			$data = [
				'abusefilter' => [
					'id' => $filter,
					'description' => $filterDescription,
					'actions' => $actionsTaken,
				],
			];

			$message = ApiMessage::create( $error, $code, $data );
			$statusForApi->fatal( $message );
		}

		return $statusForApi;
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $userIdentity
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 */
	public static function onPageSaveComplete(
		WikiPage $wikiPage,
		UserIdentity $userIdentity,
		string $summary,
		int $flags,
		RevisionRecord $revisionRecord
	) {
		AbuseFilterServices::getEditRevUpdater()->updateRev( $wikiPage, $revisionRecord );
	}

	/**
	 * @param Title $oldTitle
	 * @param Title $newTitle
	 * @param User $user
	 * @param string $reason
	 * @param Status &$status
	 */
	public static function onTitleMove(
		Title $oldTitle,
		Title $newTitle,
		User $user,
		$reason,
		Status &$status
	) {
		$builder = AbuseFilterServices::getVariableGeneratorFactory()->newRunGenerator( $user, $oldTitle );
		$vars = $builder->getMoveVars( $newTitle, $reason );
		$runnerFactory = AbuseFilterServices::getFilterRunnerFactory();
		$runner = $runnerFactory->newRunner( $user, $oldTitle, $vars, 'default' );
		$result = $runner->run();
		$status->merge( $result );
	}

	/**
	 * @param WikiPage $article
	 * @param User $user
	 * @param string $reason
	 * @param string &$error
	 * @param Status $status
	 * @return bool
	 */
	public static function onArticleDelete( WikiPage $article, User $user, $reason, &$error,
		Status $status ) {
		$builder = AbuseFilterServices::getVariableGeneratorFactory()->newRunGenerator( $user, $article->getTitle() );
		$vars = $builder->getDeleteVars( $reason );
		$runnerFactory = AbuseFilterServices::getFilterRunnerFactory();
		$runner = $runnerFactory->newRunner( $user, $article->getTitle(), $vars, 'default' );
		$filterResult = $runner->run();

		$status->merge( $filterResult );
		$error = $filterResult->isOK() ? '' : $filterResult->getHTML();

		return $filterResult->isOK();
	}

	/**
	 * @param RecentChange $recentChange
	 */
	public static function onRecentChangeSave( RecentChange $recentChange ) {
		$tagger = AbuseFilterServices::getChangeTagger();
		$tags = $tagger->getTagsForRecentChange( $recentChange );
		if ( $tags ) {
			$recentChange->addTags( $tags );
		}
	}

	/**
	 * Filter an upload.
	 *
	 * @param UploadBase $upload
	 * @param User $user
	 * @param array|null $props
	 * @param string $comment
	 * @param string $pageText
	 * @param array|ApiMessage &$error
	 * @return bool
	 */
	public static function onUploadVerifyUpload( UploadBase $upload, User $user,
		$props, $comment, $pageText, &$error
	) {
		return self::filterUpload( 'upload', $upload, $user, $props, $comment, $pageText, $error );
	}

	/**
	 * Filter an upload to stash. If a filter doesn't need to check the page contents or
	 * upload comment, it can use `action='stashupload'` to provide better experience to e.g.
	 * UploadWizard (rejecting files immediately, rather than after the user adds the details).
	 *
	 * @param UploadBase $upload
	 * @param User $user
	 * @param array $props
	 * @param array|ApiMessage &$error
	 * @return bool
	 */
	public static function onUploadStashFile( UploadBase $upload, User $user,
		array $props, &$error
	) {
		return self::filterUpload( 'stashupload', $upload, $user, $props, null, null, $error );
	}

	/**
	 * Implementation for UploadStashFile and UploadVerifyUpload hooks.
	 *
	 * @param string $action 'upload' or 'stashupload'
	 * @param UploadBase $upload
	 * @param User $user User performing the action
	 * @param array|null $props File properties, as returned by MWFileProps::getPropsFromPath().
	 * @param string|null $summary Upload log comment (also used as edit summary)
	 * @param string|null $text File description page text (only used for new uploads)
	 * @param array|ApiMessage &$error
	 * @return bool
	 */
	public static function filterUpload( $action, UploadBase $upload, User $user,
		$props, $summary, $text, &$error
	) {
		$title = $upload->getTitle();
		if ( $title === null ) {
			// T144265: This could happen for 'stashupload' if the specified title is invalid.
			// Let UploadBase warn the user about that, and we'll filter later.
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->warning( __METHOD__ . " received a null title. Action: $action." );
			return true;
		}

		$builder = AbuseFilterServices::getVariableGeneratorFactory()->newRunGenerator( $user, $title );
		$vars = $builder->getUploadVars( $action, $upload, $summary, $text, $props );
		if ( $vars === null ) {
			return true;
		}
		$runnerFactory = AbuseFilterServices::getFilterRunnerFactory();
		$runner = $runnerFactory->newRunner( $user, $title, $vars, 'default' );
		$filterResult = $runner->run();

		if ( !$filterResult->isOK() ) {
			// Produce a useful error message for API edits
			$filterResultApi = self::getApiStatus( $filterResult );
			// @todo Return all errors instead of only the first one
			$error = $filterResultApi->getErrors()[0]['message'];
		}

		return $filterResult->isOK();
	}

	/**
	 * For integration with the Renameuser extension.
	 *
	 * @param RenameuserSQL $renameUserSQL
	 */
	public static function onRenameUserSQL( RenameuserSQL $renameUserSQL ) {
		$renameUserSQL->tablesJob['abuse_filter'] = [
			RenameuserSQL::NAME_COL => 'af_user_text',
			RenameuserSQL::UID_COL => 'af_user',
			RenameuserSQL::TIME_COL => 'af_timestamp',
			'uniqueKey' => 'af_id'
		];
		$renameUserSQL->tablesJob['abuse_filter_history'] = [
			RenameuserSQL::NAME_COL => 'afh_user_text',
			RenameuserSQL::UID_COL => 'afh_user',
			RenameuserSQL::TIME_COL => 'afh_timestamp',
			'uniqueKey' => 'afh_id'
		];
	}

	/**
	 * Tables that Extension:UserMerge needs to update
	 *
	 * @param array &$updateFields
	 */
	public static function onUserMergeAccountFields( array &$updateFields ) {
		$updateFields[] = [ 'abuse_filter', 'af_user', 'af_user_text' ];
		$updateFields[] = [ 'abuse_filter_log', 'afl_user', 'afl_user_text' ];
		$updateFields[] = [ 'abuse_filter_history', 'afh_user', 'afh_user_text' ];
	}

	/**
	 * Warms the cache for getLastPageAuthors() - T116557
	 *
	 * @param WikiPage $page
	 * @param Content $content
	 * @param ParserOutput $output
	 * @param string $summary
	 * @param User $user
	 */
	public static function onParserOutputStashForEdit(
		WikiPage $page, Content $content, ParserOutput $output, string $summary, User $user
	) {
		// XXX: This makes the assumption that this method is only ever called for the main slot.
		// Which right now holds true, but any more fancy MCR stuff will likely break here...
		$slot = SlotRecord::MAIN;

		// Cache any resulting filter matches.
		// Do this outside the synchronous stash lock to avoid any chance of slowdown.
		DeferredUpdates::addCallableUpdate(
			function () use (
				$user,
				$page,
				$summary,
				$content,
				$slot
			) {
				$startTime = microtime( true );
				$generator = AbuseFilterServices::getVariableGeneratorFactory()->newRunGenerator(
					$user,
					$page->getTitle()
				);
				$vars = $generator->getStashEditVars( $content, $summary, $slot, $page );
				if ( !$vars ) {
					return;
				}
				$runnerFactory = AbuseFilterServices::getFilterRunnerFactory();
				$runner = $runnerFactory->newRunner( $user, $page->getTitle(), $vars, 'default' );
				$runner->runForStash();
				$totalTime = microtime( true ) - $startTime;
				MediaWikiServices::getInstance()->getStatsdDataFactory()
					->timing( 'timing.stashAbuseFilter', $totalTime );
			},
			DeferredUpdates::PRESEND
		);
	}

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public static function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$icons
	) {
		$notifications[ EchoNotifier::EVENT_TYPE ] = [
			'category' => 'system',
			'section' => 'alert',
			'group' => 'negative',
			'presentation-model' => ThrottleFilterPresentationModel::class,
			EchoAttributeManager::ATTR_LOCATORS => [
				[
					[ EchoUserLocator::class, 'locateFromEventExtra' ],
					[ 'user' ]
				]
			],
		];
	}

}
