<?php

namespace MediaWiki\Extension\AbuseFilter;

use ApiMessage;
use Content;
use DeferredUpdates;
use IContextSource;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use ParserOutput;
use Status;
use Title;
use UploadBase;
use User;
use WikiPage;

class AbuseFilterHooks {

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
	 * @return bool
	 */
	public static function onEditFilterMergedContent( IContextSource $context, Content $content,
		Status $status, $summary, User $user, $minoredit, $slot = SlotRecord::MAIN
	) {
		$startTime = microtime( true );

		if ( !$status->isOK() ) {
			// Investigate what happens if we skip filtering here (T211680)
			LoggerFactory::getInstance( 'AbuseFilter' )->info(
				'Status is already not OK',
				[ 'status' => (string)$status ]
			);
		}

		$filterResult = self::filterEdit( $context, $user, $content, $summary, $slot );

		if ( !$filterResult->isOK() ) {
			// Produce a useful error message for API edits
			$filterResultApi = self::getApiStatus( $filterResult );
			$status->merge( $filterResultApi );
		}
		MediaWikiServices::getInstance()->getStatsdDataFactory()
			->timing( 'timing.editAbuseFilter', microtime( true ) - $startTime );

		return $status->isOK();
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
		$vars = $builder->getEditVars( $content, null, $summary, $slot, $page );
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
	 * @param bool $suppress
	 * @return bool
	 */
	public static function onArticleDelete( WikiPage $article, User $user, $reason, &$error,
		Status $status, $suppress ) {
		if ( $suppress ) {
			// Don't filter suppressions, T71617
			return true;
		}
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
			static function () use (
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

}
