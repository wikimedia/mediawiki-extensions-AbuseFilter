<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\FilterProfiler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\FilterProfiler
 * @covers ::__construct
 * @fixme convert to pure unit test when DI for DeferredUpdates is possible
 */
class AbuseFilterFilterProfilerTest extends MediaWikiIntegrationTestCase {

	private function getFilterProfiler( array $options = null, LoggerInterface $logger = null ) : FilterProfiler {
		if ( $options === null ) {
			$options = [
				'AbuseFilterProfileActionsCap' => 10000,
				'AbuseFilterConditionLimit' => 1000,
				'AbuseFilterSlowFilterRuntimeLimit' => 500,
			];
		}
		return new FilterProfiler(
			new HashBagOStuff(),
			new ServiceOptions( FilterProfiler::CONSTRUCTOR_OPTIONS, $options ),
			'wiki',
			$this->createMock( IBufferingStatsdDataFactory::class ),
			$logger ?: new NullLogger()
		);
	}

	/**
	 * @covers ::getFilterProfile
	 */
	public function testGetFilterProfile_noData() {
		$profiler = $this->getFilterProfiler();
		$this->assertSame( [ 0, 0, 0.0, 0.0 ], $profiler->getFilterProfile( 1 ) );
	}

	/**
	 * @covers ::getFilterProfile
	 * @covers ::recordPerFilterProfiling
	 */
	public function testGetFilterProfile() {
		$profiler = $this->getFilterProfiler();
		$profiler->recordPerFilterProfiling(
			$this->createMock( Title::class ),
			[
				'1' => [
					'time' => 12.3,
					'conds' => 5,
					'result' => false
				],
			]
		);
		DeferredUpdates::doUpdates();
		$this->assertSame( [ 1, 0, 12.3, 5.0 ], $profiler->getFilterProfile( 1 ) );
	}

	/**
	 * @covers ::getFilterProfile
	 * @covers ::recordPerFilterProfiling
	 */
	public function testRecordPerFilterProfiling_mergesResults() {
		$profiler = $this->getFilterProfiler();
		$profiler->recordPerFilterProfiling(
			$this->createMock( Title::class ),
			[
				'1' => [
					'time' => 12.5,
					'conds' => 5,
					'result' => false
				],
			]
		);
		$profiler->recordPerFilterProfiling(
			$this->createMock( Title::class ),
			[
				'1' => [
					'time' => 34.5,
					'conds' => 3,
					'result' => true
				],
			]
		);
		DeferredUpdates::doUpdates();
		$this->assertSame( [ 2, 1, 23.50, 4.0 ], $profiler->getFilterProfile( 1 ) );
	}

	/**
	 * @covers ::recordPerFilterProfiling
	 */
	public function testRecordPerFilterProfiling_reportsSlowFilter() {
		$logger = new TestLogger();
		$logger->setCollect( true );
		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedText' )->willReturn( 'title' );

		$profiler = $this->getFilterProfiler( null, $logger );
		$profiler->recordPerFilterProfiling(
			$title,
			[
				'1' => [
					'time' => 501,
					'conds' => 20,
					'result' => false
				],
			]
		);

		$found = false;
		foreach ( $logger->getBuffer() as list( , $entry ) ) {
			$check = preg_match(
				"/^Edit filter .+ on .+ is taking longer than expected$/",
				$entry
			);
			if ( $check ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue(
			$found,
			"Test that FilterProfiler logs the slow filter."
		);
	}

	/**
	 * @covers ::resetFilterProfile
	 */
	public function testResetFilterProfile() {
		$profiler = $this->getFilterProfiler();
		$profiler->recordPerFilterProfiling(
			$this->createMock( Title::class ),
			[
				'1' => [
					'time' => 12.5,
					'conds' => 5,
					'result' => false
				],
				'2' => [
					'time' => 34.5,
					'conds' => 3,
					'result' => true
				],
			]
		);
		DeferredUpdates::doUpdates();
		$profiler->resetFilterProfile( 1 );
		$this->assertSame( [ 0, 0, 0.0, 0.0 ], $profiler->getFilterProfile( 1 ) );
		$this->assertNotSame( [ 0, 0, 0.0, 0.0 ], $profiler->getFilterProfile( 2 ) );
	}

	/**
	 * @covers ::recordStats
	 * @covers ::getGroupProfile
	 */
	public function testGetGroupProfile_noData() {
		$profiler = $this->getFilterProfiler();
		$this->assertFalse( $profiler->getGroupProfile( 'default' ) );
	}

	/**
	 * @covers ::recordStats
	 * @covers ::getGroupProfile
	 */
	public function testRecordStats() {
		$profiler = $this->getFilterProfiler();
		$profiler->recordStats( 'default', 100, 333.3, true );
		$this->assertSame(
			[
				'total' => 1,
				'overflow' => 0,
				'total-time' => 333.3,
				'total-cond' => 100,
				'matches' => 1
			],
			$profiler->getGroupProfile( 'default' )
		);
	}

	/**
	 * @covers ::recordStats
	 * @covers ::getGroupProfile
	 */
	public function testRecordStats_mergesResults() {
		$profiler = $this->getFilterProfiler();
		$profiler->recordStats( 'default', 100, 256.5, true );
		$profiler->recordStats( 'default', 200, 512.5, false );
		$this->assertSame(
			[
				'total' => 2,
				'overflow' => 0,
				'total-time' => 769.0,
				'total-cond' => 300,
				'matches' => 1
			],
			$profiler->getGroupProfile( 'default' )
		);
	}

	/**
	 * @covers ::checkResetProfiling
	 */
	public function testCheckResetProfiling() {
		$profiler = $this->getFilterProfiler( [
			'AbuseFilterProfileActionsCap' => 1,
			'AbuseFilterConditionLimit' => 1000,
			'AbuseFilterSlowFilterRuntimeLimit' => 500,
		] );

		$profiler->recordPerFilterProfiling(
			$this->createMock( Title::class ),
			[
				'1' => [
					'time' => 12.5,
					'conds' => 5,
					'result' => false
				],
				'2' => [
					'time' => 34.5,
					'conds' => 3,
					'result' => true
				],
				'3' => [
					'time' => 34.5,
					'conds' => 5,
					'result' => true
				],
			]
		);
		DeferredUpdates::doUpdates();

		$profiler->recordStats( 'default', 100, 256.5, true );
		$profiler->recordStats( 'default', 200, 512.5, false );

		$profiler->checkResetProfiling( 'default', [ '1', '2' ] );

		$this->assertFalse( $profiler->getGroupProfile( 'default' ) );
		$this->assertSame( [ 0, 0, 0.0, 0.0 ], $profiler->getFilterProfile( 1 ) );
		$this->assertSame( [ 0, 0, 0.0, 0.0 ], $profiler->getFilterProfile( 2 ) );
		$this->assertNotFalse( $profiler->getFilterProfile( 3 ) );
	}

}
