<?php

namespace MediaWiki\Extension\AbuseFilter;

use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LBFactory;

class CentralDBManager {
	public const SERVICE_NAME = 'AbuseFilterCentralDBManager';

	/** @var LBFactory */
	private $loadBalancerFactory;
	/** @var string|null */
	private $dbName;

	/**
	 * @param LBFactory $loadBalancerFactory
	 * @param string|null $dbName
	 */
	public function __construct( LBFactory $loadBalancerFactory, ?string $dbName ) {
		$this->loadBalancerFactory = $loadBalancerFactory;
		$this->dbName = $dbName;
	}

	/**
	 * @param int $index DB_MASTER/DB_REPLICA
	 * @return IDatabase
	 * @throws DBError
	 * @throws CentralDBNotAvailableException
	 */
	public function getConnection( int $index ) : IDatabase {
		if ( !is_string( $this->dbName ) ) {
			throw new CentralDBNotAvailableException( '$wgAbuseFilterCentralDB is not configured' );
		}

		return $this->loadBalancerFactory
			->getMainLB( $this->dbName )
			->getConnectionRef( $index, [], $this->dbName );
	}
}
