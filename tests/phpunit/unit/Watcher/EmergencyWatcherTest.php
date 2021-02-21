<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Watcher;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\EchoNotifier;
use MediaWiki\Extension\AbuseFilter\Filter\ExistingFilter;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\FilterProfiler;
use MediaWiki\Extension\AbuseFilter\Watcher\EmergencyWatcher;
use MediaWikiUnitTestCase;
use MWTimestamp;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Watcher\EmergencyWatcher
 */
class EmergencyWatcherTest extends MediaWikiUnitTestCase {

	private function getOptions() : ServiceOptions {
		return new ServiceOptions(
			EmergencyWatcher::CONSTRUCTOR_OPTIONS,
			[
				'AbuseFilterEmergencyDisableAge' => [
					'default' => 86400,
					'other' => 3600,
				],
				'AbuseFilterEmergencyDisableCount' => [
					'default' => 2,
				],
				'AbuseFilterEmergencyDisableThreshold' => [
					'default' => 0.05,
					'other' => 0.01,
				],
			]
		);
	}

	private function getFilterProfiler( array $profilerData ) : FilterProfiler {
		$profiler = $this->createMock( FilterProfiler::class );
		$profiler->method( 'getGroupProfile' )
			->willReturnCallback( function ( $group ) use ( $profilerData ) {
				return [ 'total' => $profilerData['total'][$group] ];
			} );
		$profiler->method( 'getFilterProfile' )
			->willReturn( [ 'matches' => $profilerData['matches'] ] );
		return $profiler;
	}

	private function getFilterLookup( array $filterData ) : FilterLookup {
		$lookup = $this->createMock( FilterLookup::class );
		$lookup->method( 'getFilter' )
			->with( 1, false )
			->willReturnCallback( function () use ( $filterData ) {
				$filterObj = $this->createMock( ExistingFilter::class );
				$filterObj->method( 'getTimestamp' )->willReturn( $filterData['timestamp'] );
				$filterObj->method( 'isThrottled' )->willReturn( $filterData['throttled'] ?? false );
				return $filterObj;
			} );
		return $lookup;
	}

	public function provideFiltersToThrottle() : array {
		return [
			'throttled, default group' => [
				/* timestamp */ '20201016010000',
				/* filterData */ [
					'timestamp' => '20201016000000'
				],
				/* profilerData */ [
					'total' => [
						'default' => 100,
					],
					'matches' => 10
				],
				/* willThrottle */ true
			],
			'throttled, other group' => [
				/* timestamp */ '20201016003000',
				/* filterData */ [
					'timestamp' => '20201016000000'
				],
				/* profilerData */ [
					'total' => [
						'default' => 200,
						'other' => 100
					],
					'matches' => 5
				],
				/* willThrottle */ true,
				/* group */ 'other'
			],
			'not throttled, already is' => [
				/* timestamp */ '20201016010000',
				/* filterData */ [
					'timestamp' => '20201016000000',
					'throttled' => true,
				],
				/* profilerData */ [
					'total' => [
						'default' => 100,
					],
					'matches' => 10
				],
				/* willThrottle */ false
			],
			'not throttled, not enough actions' => [
				/* timestamp */ '20201016010000',
				/* filterData */ [
					'timestamp' => '20201016000000'
				],
				/* profilerData */ [
					'total' => [
						'default' => 5,
					],
					'matches' => 2
				],
				/* willThrottle */ false
			],
			'not throttled, too few matches' => [
				/* timestamp */ '20201016010000',
				/* filterData */ [
					'timestamp' => '20201016000000'
				],
				/* profilerData */ [
					'total' => [
						'default' => 100,
					],
					'matches' => 5
				],
				/* willThrottle */ false
			],
			'not throttled, too long period' => [
				/* timestamp */ '20201017010000',
				/* filterData */ [
					'timestamp' => '20201016000000'
				],
				/* profilerData */ [
					'total' => [
						'default' => 1000,
					],
					'matches' => 100
				],
				/* willThrottle */ false
			],
			'not throttled, profiler reset' => [
				/* timestamp */ '20201016010000',
				/* filterData */ [
					'timestamp' => '20201016000000'
				],
				/* profilerData */ [
					'total' => [
						'default' => 0,
					],
					'matches' => 0
				],
				/* willThrottle */ false
			],
		];
	}

	/**
	 * @covers ::getFiltersToThrottle
	 * @covers ::getEmergencyValue
	 * @dataProvider provideFiltersToThrottle
	 */
	public function testGetFiltersToThrottle(
		string $timestamp,
		array $filterData,
		array $profilerData,
		bool $willThrottle,
		string $group = 'default'
	) {
		MWTimestamp::setFakeTime( $timestamp );
		$watcher = new EmergencyWatcher(
			$this->getFilterProfiler( $profilerData ),
			$this->createMock( ILoadBalancer::class ),
			$this->getFilterLookup( $filterData ),
			$this->createMock( EchoNotifier::class ),
			$this->getOptions()
		);
		$toThrottle = $watcher->getFiltersToThrottle(
			[ 1 ],
			$group
		);
		$this->assertSame(
			$willThrottle ? [ 1 ] : [],
			$toThrottle
		);
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$watcher = new EmergencyWatcher(
			$this->createMock( FilterProfiler::class ),
			$this->createMock( ILoadBalancer::class ),
			$this->createMock( FilterLookup::class ),
			$this->createMock( EchoNotifier::class ),
			$this->getOptions()
		);
		$this->assertInstanceOf( EmergencyWatcher::class, $watcher );
	}
}
