<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Watcher;

use IDatabase;
use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use MediaWiki\Extension\AbuseFilter\Watcher\UpdateHitCountWatcher;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Watcher\UpdateHitCountWatcher
 * @covers ::__construct
 */
class UpdateHitCountWatcherTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::run
	 * @covers ::updateHitCounts
	 */
	public function testRun() {
		$localFilters = [ 1, 2, 3 ];
		$globalFilters = [ 4, 5, 6 ];
		$onTransactionCB = function ( $cb ) {
			$cb();
		};

		$localDB = $this->createMock( IDatabase::class );
		$localDB->expects( $this->once() )->method( 'update' )->with(
			'abuse_filter',
			[ 'af_hit_count=af_hit_count+1' ],
			[ 'af_id' => $localFilters ]
		);
		$localDB->method( 'onTransactionPreCommitOrIdle' )->willReturnCallback( $onTransactionCB );
		$lb = $this->createMock( ILoadBalancer::class );
		$lb->method( 'getConnectionRef' )->willReturn( $localDB );

		$globalDB = $this->createMock( IDatabase::class );
		$globalDB->expects( $this->once() )->method( 'update' )->with(
			'abuse_filter',
			[ 'af_hit_count=af_hit_count+1' ],
			[ 'af_id' => $globalFilters ]
		);
		$globalDB->method( 'onTransactionPreCommitOrIdle' )->willReturnCallback( $onTransactionCB );
		$centralDBManager = $this->createMock( CentralDBManager::class );
		$centralDBManager->method( 'getConnection' )->willReturn( $globalDB );

		$watcher = new UpdateHitCountWatcher( $lb, $centralDBManager );
		$watcher->run( $localFilters, $globalFilters, 'default' );
		// Two soft assertions done above
		$this->addToAssertionCount( 2 );
	}
}
