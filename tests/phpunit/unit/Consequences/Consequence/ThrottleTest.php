<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Consequences\Consequence;

use BagOStuff;
use HashBagOStuff;
use MediaWiki\Extension\AbuseFilter\Consequences\Consequence\Throttle;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequenceNotPrecheckedException;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserFactory;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Wikimedia\TestingAccessWrapper;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Consequences\Consequence\Throttle
 * @covers ::__construct
 */
class ThrottleTest extends MediaWikiUnitTestCase {

	private function getThrottle( array $throttleParams = [], BagOStuff $cache = null, bool $globalFilter = false ) {
		$params = $this->createMock( Parameters::class );
		$params->method( 'getIsGlobalFilter' )->willReturn( $globalFilter );
		return new Throttle(
			$params,
			$throttleParams + [ 'groups' => [ 'user' ], 'count' => 3, 'period' => 60, 'id' => 1 ],
			$cache ?? new HashBagOStuff(),
			$this->createMock( UserEditTracker::class ),
			$this->createMock( UserFactory::class ),
			new NullLogger(),
			'1.2.3.4',
			false,
			$globalFilter ? 'foo-db' : null
		);
	}

	/**
	 * @covers ::execute
	 */
	public function testExecute_notPrechecked() {
		$throttle = $this->getThrottle();
		$this->expectException( ConsequenceNotPrecheckedException::class );
		$throttle->execute();
	}

	public function provideThrottle() {
		foreach ( [ false, true ] as $global ) {
			$globalStr = $global ? 'global' : 'local';
			yield "no groups, $globalStr" => [ $this->getThrottle( [ 'groups' => [] ], null, $global ), true ];

			$cache = $this->getMockBuilder( HashBagOStuff::class )->onlyMethods( [ 'incrWithInit' ] )->getMock();
			yield "no cache value set, $globalStr" => [ $this->getThrottle( [], $cache, $global ), true, $cache ];

			$groups = [ 'ip', 'user', 'range', 'creationdate', 'editcount', 'site', 'page' ];
			foreach ( $groups as $group ) {
				$throttle = $this->getThrottle( [ 'groups' => [ $group ], 'count' => 0 ], null, $global );
				$throttleWr = TestingAccessWrapper::newFromObject( $throttle );
				$throttleWr->setThrottled( $group );
				yield "$group set, $globalStr" => [ $throttle, false ];
			}
		}
	}

	/**
	 * @covers ::shouldDisableOtherConsequences
	 * @covers ::isThrottled
	 * @covers ::throttleKey
	 * @covers ::throttleIdentifier
	 * @dataProvider provideThrottle
	 */
	public function testShouldDisableOtherConsequences( Throttle $throttle, bool $shouldDisable ) {
		$this->assertSame( $shouldDisable, $throttle->shouldDisableOtherConsequences() );
	}

	/**
	 * @covers ::execute
	 * @covers ::setThrottled
	 * @covers ::throttleKey
	 * @covers ::throttleIdentifier
	 * @dataProvider provideThrottle
	 */
	public function testExecute( Throttle $throttle, bool $shouldDisable, MockObject $cache = null ) {
		if ( $cache ) {
			$groupCount = count( TestingAccessWrapper::newFromObject( $throttle )->throttleParams['groups'] );
			$cache->expects( $this->exactly( $groupCount ) )->method( 'incrWithInit' );
		}
		$throttle->shouldDisableOtherConsequences();
		$this->assertSame( $shouldDisable, $throttle->execute() );
	}
}
