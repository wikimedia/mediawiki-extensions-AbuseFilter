<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Watcher;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\EchoNotifier;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Extension\AbuseFilter\Tests\Integration\FilterFromSpecsTestTrait;
use MediaWiki\Extension\AbuseFilter\Watcher\EmergencyWatcher;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Watcher\EmergencyWatcher
 * @group Database
 */
class EmergencyWatcherTest extends MediaWikiIntegrationTestCase {
	use FilterFromSpecsTestTrait;

	public function testRunNotifiesOncePerFilter(): void {
		$services = $this->getServiceContainer();

		// Create a filter with af_throttled = 0 so ::run() can set it to 1
		$status = AbuseFilterServices::getFilterStore( $services )->saveFilter(
			$this->getTestSysop()->getAuthority(),
			null,
			// saveFilter() ignores the ID on creation, but the trait needs the key
			$this->getFilterFromSpecs( [ 'id' => 1 ] ),
			MutableFilter::newDefault()
		);
		$this->assertStatusGood( $status );
		[ $filterId ] = $status->getValue();

		$notifier = $this->createMock( EchoNotifier::class );
		$notifier->expects( $this->once() )
			->method( 'notifyForFilter' )
			->with( $filterId );

		// Mock ::getFiltersToThrottle(), but keep the original constructor to initialize $lbFactory
		$watcher = $this->getMockBuilder( EmergencyWatcher::class )
			->onlyMethods( [ 'getFiltersToThrottle' ] )
			->setConstructorArgs( [
				AbuseFilterServices::getEmergencyCache( $services ),
				$services->getDBLoadBalancerFactory(),
				AbuseFilterServices::getFilterLookup( $services ),
				$notifier,
				new ServiceOptions( EmergencyWatcher::CONSTRUCTOR_OPTIONS, $services->getMainConfig() )
			] )
			->getMock();
		$watcher
			->method( 'getFiltersToThrottle' )
			->with( [ $filterId ], 'default' )
			->willReturn( [ $filterId ] );

		// Queue multiple updates before executing them
		$args = [ [ $filterId ], [], 'default' ];
		$watcher->run( ...$args );
		$watcher->run( ...$args );

		DeferredUpdates::doUpdates( DeferredUpdates::POSTSEND );

		$this->newSelectQueryBuilder()
			->select( 'af_throttled' )
			->from( 'abuse_filter' )
			->where( [ 'af_id' => $filterId ] )
			->caller( __METHOD__ )
			->assertFieldValue( '1' );
	}
}
