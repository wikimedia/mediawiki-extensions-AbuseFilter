<?php

namespace MediaWiki\Extension\AbuseFilter;

use ManualLogEntry;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
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
	 * @var string
	 */
	private const LOG_TYPE = 'abusefilter-protected-vars';

	private LoggerInterface $logger;
	private IConnectionProvider $lbFactory;

	/**
	 * @param LoggerInterface $logger
	 * @param IConnectionProvider $lbFactory
	 */
	public function __construct(
		LoggerInterface $logger,
		IConnectionProvider $lbFactory
	) {
		$this->logger = $logger;
		$this->lbFactory = $lbFactory;
	}

	/**
	 * Log when the user enables their own access
	 *
	 * @param UserIdentity $performer
	 */
	public function logAccessEnabled( UserIdentity $performer ): void {
		$this->log( $performer, $performer->getName(), self::ACTION_CHANGE_ACCESS_ENABLED );
	}

	/**
	 * Log when the user disables their own access
	 *
	 * @param UserIdentity $performer
	 */
	public function logAccessDisabled( UserIdentity $performer ): void {
		$this->log( $performer, $performer->getName(), self::ACTION_CHANGE_ACCESS_DISABLED );
	}

	/**
	 * @param UserIdentity $performer
	 * @param string $target
	 * @param string $action
	 * @param array|null $params
	 */
	private function log(
		UserIdentity $performer,
		string $target,
		string $action,
		?array $params = []
	): void {
		$logEntry = $this->createManualLogEntry( $action );
		$logEntry->setPerformer( $performer );
		$logEntry->setTarget( Title::makeTitle( NS_USER, $target ) );
		$logEntry->setParameters( $params );

		try {
			$dbw = $this->lbFactory->getPrimaryDatabase();
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
