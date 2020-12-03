<?php

namespace MediaWiki\Extension\AbuseFilter\Watcher;

use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Watcher that updates hit counts of filters
 */
class UpdateHitCountWatcher implements Watcher {
	public const SERVICE_NAME = 'AbuseFilterUpdateHitCountWatcher';

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var CentralDBManager */
	private $centralDBManager;

	/**
	 * @param ILoadBalancer $loadBalancer
	 * @param CentralDBManager $centralDBManager
	 */
	public function __construct(
		ILoadBalancer $loadBalancer,
		CentralDBManager $centralDBManager
	) {
		$this->loadBalancer = $loadBalancer;
		$this->centralDBManager = $centralDBManager;
	}

	/**
	 * @inheritDoc
	 */
	public function run( array $localFilters, array $globalFilters, string $group ) : void {
		if ( count( $localFilters ) ) {
			$this->updateHitCounts( $this->loadBalancer->getConnectionRef( DB_MASTER ), $localFilters );
		}

		if ( count( $globalFilters ) ) {
			$fdb = $this->centralDBManager->getConnection( DB_MASTER );
			$this->updateHitCounts( $fdb, $globalFilters );
		}
	}

	/**
	 * @param IDatabase $dbw
	 * @param array $loggedFilters
	 */
	private function updateHitCounts( IDatabase $dbw, array $loggedFilters ) : void {
		$method = __METHOD__;
		$dbw->onTransactionPreCommitOrIdle(
			function () use ( $dbw, $loggedFilters, $method ) {
				$dbw->update( 'abuse_filter',
					[ 'af_hit_count=af_hit_count+1' ],
					[ 'af_id' => $loggedFilters ],
					$method
				);
			},
			$method
		);
	}
}
