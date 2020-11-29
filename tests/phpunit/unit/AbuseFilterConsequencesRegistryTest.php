<?php

use MediaWiki\Extension\AbuseFilter\ConsequencesRegistry;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;

/**
 * @group Test
 * @group AbuseFilter
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\ConsequencesRegistry
 */
class AbuseFilterConsequencesRegistryTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$hookRunner = $this->createMock( AbuseFilterHookRunner::class );
		$this->assertInstanceOf(
			ConsequencesRegistry::class,
			new ConsequencesRegistry( $hookRunner, [], [] )
		);
	}

	/**
	 * @covers ::getAllActionNames
	 */
	public function testGetAllActionNames() {
		$configActions = [ 'nothing' => false, 'rickroll' => true ];
		$customHandlers = [ 'blahblah' => 'strlen' ];
		$expected = [ 'nothing', 'rickroll', 'blahblah' ];
		$registry = new ConsequencesRegistry(
			$this->createMock( AbuseFilterHookRunner::class ),
			$configActions,
			$customHandlers
		);
		$this->assertSame( $expected, $registry->getAllActionNames() );
	}

	/**
	 * @covers ::getDangerousActionNames
	 */
	public function testGetDangerousActionNames() {
		// Cheat a bit
		$regReflection = new ReflectionClass( ConsequencesRegistry::class );
		$expected = $regReflection->getConstant( 'DANGEROUS_ACTIONS' );

		$registry = new ConsequencesRegistry( $this->createMock( AbuseFilterHookRunner::class ), [], [] );
		$this->assertSame( $expected, $registry->getDangerousActionNames() );
	}

	/**
	 * @covers ::getDangerousActionNames
	 */
	public function testGetDangerousActionNames_hook() {
		$extraDangerous = 'rickroll';
		$hookRunner = $this->createMock( AbuseFilterHookRunner::class );
		$hookRunner->method( 'onAbuseFilterGetDangerousActions' )->willReturnCallback(
			function ( &$array ) use ( $extraDangerous ) {
				$array[] = $extraDangerous;
			}
		);
		$registry = new ConsequencesRegistry( $hookRunner, [], [] );
		$this->assertContains( $extraDangerous, $registry->getDangerousActionNames() );
	}
}
