<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Watcher;

use MediaWiki\Deferred\DeferredUpdatesManager;
use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use MediaWiki\Extension\AbuseFilter\Watcher\UpdateHitCountWatcher;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LBFactory;

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

		$localDB = $this->createMock( DBConnRef::class );
		$localDB->expects( $this->once() )->method( 'update' )->with(
			'abuse_filter',
			[ 'af_hit_count=af_hit_count+1' ],
			[ 'af_id' => $localFilters ]
		);
		$lb = $this->createMock( LBFactory::class );
		$lb->method( 'getPrimaryDatabase' )->willReturn( $localDB );

		$globalDB = $this->createMock( IDatabase::class );
		$globalDB->expects( $this->once() )->method( 'update' )->with(
			'abuse_filter',
			[ 'af_hit_count=af_hit_count+1' ],
			[ 'af_id' => $globalFilters ]
		);
		$centralDBManager = $this->createMock( CentralDBManager::class );
		$centralDBManager->method( 'getConnection' )->willReturn( $globalDB );

		$deferredUpdatesManager = $this->createMock( DeferredUpdatesManager::class );
		$deferredUpdatesManager->method( 'addCallableUpdate' )
			->willReturnCallback( static fn ( $cb ) => $cb() );

		$watcher = new UpdateHitCountWatcher( $lb, $centralDBManager, $deferredUpdatesManager );
		$watcher->run( $localFilters, $globalFilters, 'default' );
		// Two soft assertions done above
		$this->addToAssertionCount( 2 );
	}
}
