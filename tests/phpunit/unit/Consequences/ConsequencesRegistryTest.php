<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Consequences;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\Consequences\Consequence\Consequence;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesRegistry;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWikiUnitTestCase;
use ReflectionClass;
use RuntimeException;

/**
 * @group Test
 * @group AbuseFilter
 * @covers \MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesRegistry
 */
class ConsequencesRegistryTest extends MediaWikiUnitTestCase {

	public function testConstruct() {
		$hookRunner = $this->createMock( AbuseFilterHookRunner::class );
		$serviceOptions = new ServiceOptions(
			ConsequencesRegistry::CONSTRUCTOR_OPTIONS,
			[
				'AbuseFilterActions' => [],
				'AbuseFilterActionRestrictions' => [],
			]
		);
		$this->assertInstanceOf(
			ConsequencesRegistry::class,
			new ConsequencesRegistry( $hookRunner, $serviceOptions )
		);
	}

	private function getRegistry(
		?AbuseFilterHookRunner $hookRunner = null,
		?array $configActions = null,
		?array $restrictedActions = null,
	): ConsequencesRegistry {
		return new ConsequencesRegistry(
			$hookRunner ?? $this->createMock( AbuseFilterHookRunner::class ),
			new ServiceOptions(
				ConsequencesRegistry::CONSTRUCTOR_OPTIONS,
				[
					'AbuseFilterActions' => $configActions ?? [
						'throttle' => true,
						'warn' => true,
						'disallow' => true,
						'blockautopromote' => true,
						'block' => true,
						'rangeblock' => false,
						'degroup' => false,
						'tag' => true,
					],
					'AbuseFilterActionRestrictions' => $restrictedActions ?? [
						'throttle' => false,
						'warn' => false,
						'disallow' => false,
						'blockautopromote' => true,
						'block' => true,
						'rangeblock' => true,
						'degroup' => true,
						'tag' => false,
					],
				]
			)
		);
	}

	public function testGetAllActionNames() {
		$configActions = [ 'nothing' => false, 'rickroll' => true ];
		$customActionName = 'spell';
		$hookRunner = $this->createMock( AbuseFilterHookRunner::class );
		$hookRunner->method( 'onAbuseFilterCustomActions' )->willReturnCallback(
			static function ( &$actions ) use ( $customActionName ) {
				$actions[$customActionName] = 'strlen';
			}
		);
		$expected = [ 'nothing', 'rickroll', 'spell' ];
		$registry = $this->getRegistry( $hookRunner, $configActions );
		$this->assertSame( $expected, $registry->getAllActionNames() );
	}

	public function testGetAllEnabledActionNames() {
		$configActions = [ 'nothing' => false, 'zero' => 0, 'rickroll' => true ];
		$customActionName = 'spell';
		$hookRunner = $this->createMock( AbuseFilterHookRunner::class );
		$hookRunner->method( 'onAbuseFilterCustomActions' )->willReturnCallback(
			static function ( &$actions ) use ( $customActionName ) {
				$actions[$customActionName] = 'strlen';
			}
		);
		$expected = [ 'rickroll', 'spell' ];
		$registry = $this->getRegistry( $hookRunner, $configActions );
		$this->assertSame( $expected, $registry->getAllEnabledActionNames() );
	}

	public function testGetDangerousActionNames() {
		// Cheat a bit
		$regReflection = new ReflectionClass( ConsequencesRegistry::class );
		$expected = $regReflection->getConstant( 'DANGEROUS_ACTIONS' );

		$registry = $this->getRegistry();
		$this->assertSame( $expected, $registry->getDangerousActionNames() );
	}

	public function testGetDangerousActionNames_hook() {
		$extraDangerous = 'rickroll';
		$hookRunner = $this->createMock( AbuseFilterHookRunner::class );
		$hookRunner->method( 'onAbuseFilterGetDangerousActions' )->willReturnCallback(
			static function ( &$array ) use ( $extraDangerous ) {
				$array[] = $extraDangerous;
			}
		);
		$registry = $this->getRegistry( $hookRunner );
		$this->assertContains( $extraDangerous, $registry->getDangerousActionNames() );
	}

	public function testFilterDangerousActionNames() {
		$regReflection = new ReflectionClass( ConsequencesRegistry::class );
		$expected = $regReflection->getConstant( 'DANGEROUS_ACTIONS' );
		$inputActions = array_merge( [ 'tag', 'throttle', 'warn', 'disallow' ], $expected );

		$registry = $this->getRegistry();
		$this->assertSame( $expected, $registry->filterDangerousActionNames( $inputActions ) );
	}

	public function testGetCustomActions() {
		$customActionName = 'rickroll';
		$customAction = 'strlen';
		$hookRunner = $this->createMock( AbuseFilterHookRunner::class );
		$hookRunner->method( 'onAbuseFilterCustomActions' )->willReturnCallback(
			static function ( &$actions ) use ( $customActionName, $customAction ) {
				$actions[$customActionName] = $customAction;
			}
		);
		$registry = $this->getRegistry( $hookRunner );
		$this->assertSame( [ $customActionName => $customAction ], $registry->getCustomActions() );
	}

	public function testGetCustomActions_invalidKey() {
		$hookRunner = $this->createMock( AbuseFilterHookRunner::class );
		$hookRunner->method( 'onAbuseFilterCustomActions' )->willReturnCallback(
			function ( &$actions ) {
				$invalidKey = 42;
				$actions[$invalidKey] = $this->createMock( Consequence::class );
			}
		);
		$registry = $this->getRegistry( $hookRunner );
		$this->expectException( RuntimeException::class );
		$registry->getCustomActions();
	}

	public function testGetCustomActions_invalidValue() {
		$hookRunner = $this->createMock( AbuseFilterHookRunner::class );
		$hookRunner->method( 'onAbuseFilterCustomActions' )->willReturnCallback(
			static function ( &$actions ) {
				$invalidValue = 42;
				$actions['myaction'] = $invalidValue;
			}
		);
		$registry = $this->getRegistry( $hookRunner );
		$this->expectException( RuntimeException::class );
		$registry->getCustomActions();
	}

	public function testGetRestrictedActionNames(): void {
		$registry = $this->getRegistry(
			null,
			[ 'block' => false, 'degroup' => true, 'tag' => false, 'rickroll' => true ],
			[ 'block' => 0, 'degroup' => false, 'undefined' => true, 'tag' => 1, 'rickroll' => true ]
		);
		$this->assertSame(
			[ 'undefined', 'tag', 'rickroll' ],
			$registry->getRestrictedActionNames()
		);
	}

	/**
	 * @dataProvider provideFilterRestrictedActionNames
	 */
	public function testFilterRestrictedActionNames( array $actionNames, array $expected ): void {
		$this->assertSame(
			$expected, $this->getRegistry()->filterRestrictedActionNames( $actionNames )
		);
	}

	public static function provideFilterRestrictedActionNames(): array {
		return [
			'no restricted actions' => [
				'actionNames' => [ 'throttle', 'warn', 'disallow', 'tag' ],
				'expected' => [],
			],
			'all restricted actions' => [
				'actionNames' => [ 'blockautopromote', 'block', 'rangeblock', 'degroup' ],
				'expected' => [ 'blockautopromote', 'block', 'rangeblock', 'degroup' ],
			],
			'mixed actions' => [
				'actionNames' => [ 'throttle', 'block', 'warn', 'degroup', 'tag' ],
				'expected' => [ 'block', 'degroup' ],
			],
			'unknown actions' => [
				'actionNames' => [ 'unknown', 'block', 'another-unknown' ],
				'expected' => [ 'block' ],
			],
		];
	}

	/**
	 * @dataProvider provideIsRestrictedActionName
	 */
	public function testIsRestrictedActionName( string $actionName, bool $expected ): void {
		$this->assertSame(
			$expected, $this->getRegistry()->isRestrictedActionName( $actionName )
		);
	}

	public static function provideIsRestrictedActionName(): array {
		return [
			'throttle' => [ 'throttle', false ],
			'warn' => [ 'warn', false ],
			'disallow' => [ 'disallow', false ],
			'blockautopromote' => [ 'blockautopromote', true ],
			'block' => [ 'block', true ],
			'rangeblock' => [ 'rangeblock', true ],
			'degroup' => [ 'degroup', true ],
			'tag' => [ 'tag', false ],
			'unknown' => [ 'unknown', false ],
		];
	}
}
