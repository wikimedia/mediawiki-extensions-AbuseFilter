<?php

namespace MediaWiki\Extension\AbuseFilter\Watcher;

use MediaWiki\Deferred\DeferredUpdatesManager;
use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LBFactory;

/**
 * Watcher that updates hit counts of filters
 */
class UpdateHitCountWatcher implements Watcher {
	public const SERVICE_NAME = 'AbuseFilterUpdateHitCountWatcher';

	/** @var LBFactory */
	private $lbFactory;

	/** @var CentralDBManager */
	private $centralDBManager;

	/** @var DeferredUpdatesManager */
	private DeferredUpdatesManager $deferredUpdatesManager;

	/**
	 * @param LBFactory $lbFactory
	 * @param CentralDBManager $centralDBManager
	 * @param DeferredUpdatesManager $deferredUpdatesManager
	 */
	public function __construct(
		LBFactory $lbFactory,
		CentralDBManager $centralDBManager,
		DeferredUpdatesManager $deferredUpdatesManager
	) {
		$this->lbFactory = $lbFactory;
		$this->centralDBManager = $centralDBManager;
		$this->deferredUpdatesManager = $deferredUpdatesManager;
	}

	/**
	 * @inheritDoc
	 */
	public function run( array $localFilters, array $globalFilters, string $group ): void {
		// Run in a DeferredUpdate to avoid primary database queries on raw/view requests (T274455)
		$this->deferredUpdatesManager->addCallableUpdate( function () use ( $localFilters, $globalFilters ) {
			if ( $localFilters ) {
				$this->updateHitCounts( $this->lbFactory->getPrimaryDatabase(), $localFilters );
			}

			if ( $globalFilters ) {
				$fdb = $this->centralDBManager->getConnection( DB_PRIMARY );
				$this->updateHitCounts( $fdb, $globalFilters );
			}
		} );
	}

	/**
	 * @param IDatabase $dbw
	 * @param array $loggedFilters
	 */
	private function updateHitCounts( IDatabase $dbw, array $loggedFilters ): void {
		$dbw->update(
			'abuse_filter',
			[ 'af_hit_count=af_hit_count+1' ],
			[ 'af_id' => $loggedFilters ],
			__METHOD__
		);
	}
}
