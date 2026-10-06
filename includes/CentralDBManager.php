<?php

namespace MediaWiki\Extension\AbuseFilter;

use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

class CentralDBManager {
	public const SERVICE_NAME = ServiceNames::CentralDBManager;

	/** @var string|false */
	private $dbName;

	/**
	 * @param IConnectionProvider $connectionProvider
	 * @param string|false|null $dbName
	 * @param bool $filterIsCentral
	 */
	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		$dbName,
		private readonly bool $filterIsCentral
	) {
		// Use false to agree with LoadBalancer
		$this->dbName = $dbName ?: false;
	}

	/**
	 * @return IDatabase
	 * @throws DBError
	 * @throws CentralDBNotAvailableException
	 */
	public function getPrimaryDatabase(): IDatabase {
		return $this->connectionProvider->getPrimaryDatabase( $this->getCentralDBName() );
	}

	/**
	 * @return IReadableDatabase
	 * @throws DBError
	 * @throws CentralDBNotAvailableException
	 */
	public function getReplicaDatabase(): IReadableDatabase {
		return $this->connectionProvider->getReplicaDatabase( $this->getCentralDBName() );
	}

	/**
	 * @return string
	 * @throws CentralDBNotAvailableException
	 */
	public function getCentralDBName(): string {
		if ( !is_string( $this->dbName ) ) {
			throw new CentralDBNotAvailableException( '$wgAbuseFilterCentralDB is not configured' );
		}
		return $this->dbName;
	}

	/**
	 * Whether this database is the central one.
	 * @todo Deprecate the config in favour of just checking whether the current DB is the same
	 *  as $wgAbuseFilterCentralDB.
	 * @return bool
	 */
	public function filterIsCentral(): bool {
		return $this->filterIsCentral;
	}
}
