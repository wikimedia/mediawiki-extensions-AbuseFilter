<?php

namespace MediaWiki\Extension\AbuseFilter;

use ManualLogEntry;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Title\Title;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserIdentity;
use Profiler;
use Psr\Log\LoggerInterface;
use Wikimedia\Assert\Assert;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Defines the API for the component responsible for logging the following interactions:
 *
 * - A user enables protected variable viewing
 * - A user disables protected variable viewing
 */
class ProtectedVarsAccessLogger {
	/**
	 * Represents a user enabling their own access to view protected variables
	 *
	 * @var string
	 */
	public const ACTION_CHANGE_ACCESS_ENABLED = 'change-access-enable';

	/**
	 * Represents a user disabling their own access to view protected variables
	 *
	 * @var string
	 */
	public const ACTION_CHANGE_ACCESS_DISABLED = 'change-access-disable';

	/**
	 * Represents a user viewing the value of a protected variable
	 *
	 * @var string
	 */
	public const ACTION_VIEW_PROTECTED_VARIABLE_VALUE = 'view-protected-var-value';

	/**
	 * @var string
	 */
	public const LOG_TYPE = 'abusefilter-protected-vars';

	private LoggerInterface $logger;
	private IConnectionProvider $lbFactory;
	private ActorStore $actorStore;
	private AbuseFilterHookRunner $hookRunner;
	private int $delay;

	/**
	 * @param LoggerInterface $logger
	 * @param IConnectionProvider $lbFactory
	 * @param ActorStore $actorStore
	 * @param AbuseFilterHookRunner $hookRunner
	 * @param int $delay The number of seconds after which a duplicate log entry can be
	 *  created for a debounced log
	 */
	public function __construct(
		LoggerInterface $logger,
		IConnectionProvider $lbFactory,
		ActorStore $actorStore,
		AbuseFilterHookRunner $hookRunner,
		int $delay
	) {
		Assert::parameter( $delay > 0, 'delay', 'delay must be positive' );

		$this->logger = $logger;
		$this->lbFactory = $lbFactory;
		$this->actorStore = $actorStore;
		$this->hookRunner = $hookRunner;
		$this->delay = $delay;
	}

	/**
	 * Log when the user enables their own access
	 *
	 * @param UserIdentity $performer
	 */
	public function logAccessEnabled( UserIdentity $performer ): void {
		$this->log( $performer, $performer->getName(), self::ACTION_CHANGE_ACCESS_ENABLED, false );
	}

	/**
	 * Log when the user disables their own access
	 *
	 * @param UserIdentity $performer
	 */
	public function logAccessDisabled( UserIdentity $performer ): void {
		$this->log( $performer, $performer->getName(), self::ACTION_CHANGE_ACCESS_DISABLED, false );
	}

	/**
	 * Log when the user views the values of protected variables
	 *
	 * @param UserIdentity $performer
	 * @param string $target
	 * @param int|null $timestamp
	 */
	public function logViewProtectedVariableValue(
		UserIdentity $performer,
		string $target,
		?int $timestamp = null
	): void {
		if ( !$timestamp ) {
			$timestamp = (int)wfTimestamp();
		}
		// Create the log on POSTSEND, as this can be called in a context of a GET request through the
		// QueryAbuseLog API (T379083).
		DeferredUpdates::addCallableUpdate( function () use ( $performer, $target, $timestamp ) {
			// We need to create a log entry and PostSend-GET expects no writes are performed, so we need to
			// silence the warnings created by this.
			$trxProfiler = Profiler::instance()->getTransactionProfiler();
			$scope = $trxProfiler->silenceForScope( $trxProfiler::EXPECTATION_REPLICAS_ONLY );
			$this->log(
				$performer,
				$target,
				self::ACTION_VIEW_PROTECTED_VARIABLE_VALUE,
				true,
				$timestamp
			);
		} );
	}

	/**
	 * @param UserIdentity $performer
	 * @param string $target
	 * @param string $action
	 * @param bool $shouldDebounce
	 * @param int|null $timestamp
	 * @param array|null $params
	 */
	private function log(
		UserIdentity $performer,
		string $target,
		string $action,
		bool $shouldDebounce,
		?int $timestamp = null,
		?array $params = []
	): void {
		if ( !$timestamp ) {
			$timestamp = (int)wfTimestamp();
		}

		// Allow external extensions to hook into this logger and pass along all known
		// values. External extensions can abort this hook to stop additional logging
		if ( !$this->hookRunner->onAbuseFilterLogProtectedVariableValueAccess(
			$performer,
			$target,
			$action,
			$shouldDebounce,
			$timestamp,
			$params
		) ) {
			// Don't continue if the hook returns false
			return;
		}

		$dbw = $this->lbFactory->getPrimaryDatabase();
		$shouldLog = false;

		// If the log is debounced, check against the logging table before logging
		if ( $shouldDebounce ) {
			$timestampMinusDelay = $timestamp - $this->delay;
			$actorId = $this->actorStore->findActorId( $performer, $dbw );
			if ( !$actorId ) {
				$shouldLog = true;
			} else {
				$logline = $dbw->newSelectQueryBuilder()
					->select( '*' )
					->from( 'logging' )
					->where( [
						'log_type' => self::LOG_TYPE,
						'log_action' => $action,
						'log_actor' => $actorId,
						'log_namespace' => NS_USER,
						'log_title' => $target,
						$dbw->expr( 'log_timestamp', '>', $dbw->timestamp( $timestampMinusDelay ) ),
					] )
					->caller( __METHOD__ )
					->fetchRow();

				if ( !$logline ) {
					$shouldLog = true;
				}
			}
		} else {
			// If the log isn't debounced then it should always be logged
			$shouldLog = true;
		}

		// Actually write to logging table
		if ( $shouldLog ) {
			$logEntry = $this->createManualLogEntry( $action );
			$logEntry->setPerformer( $performer );
			$logEntry->setTarget( Title::makeTitle( NS_USER, $target ) );
			$logEntry->setParameters( $params );
			$logEntry->setTimestamp( wfTimestamp( TS_MW, $timestamp ) );

			try {
				$logEntry->insert( $dbw );
			} catch ( DBError $e ) {
				$this->logger->critical(
					'AbuseFilter proctected variable log entry was not recorded. ' .
					'This means access to IPs can occur without being auditable. ' .
					'Immediate fix required.'
				);

				throw $e;
			}
		}
	}

	/**
	 * @internal
	 *
	 * @param string $subtype
	 * @return ManualLogEntry
	 */
	protected function createManualLogEntry( string $subtype ): ManualLogEntry {
		return new ManualLogEntry( self::LOG_TYPE, $subtype );
	}
}
