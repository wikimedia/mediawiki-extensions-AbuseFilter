<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Hooks;

use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesRegistry;
use MediaWiki\Extension\AbuseFilter\Hooks\Handlers\AutoPromoteGroupsHandler;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Hooks\Handlers\AutoPromoteGroupsHandler
 */
class AutoPromoteGroupsHandlerTest extends MediaWikiUnitTestCase {

	private function getConsequencesRegistry( bool $enabled = true ): ConsequencesRegistry {
		$registry = $this->createMock( ConsequencesRegistry::class );
		$registry->method( 'getAllEnabledActionNames' )
			->willReturn( $enabled ? [ 'tag', 'blockautopromote' ] : [ 'tag' ] );
		return $registry;
	}

	public static function provideOnGetAutoPromoteGroups_nothingToDo(): array {
		return [
			[ true, [] ],
			[ false, [] ],
			[ false, [ 'autoconfirmed' ] ]
		];
	}

	/**
	 * @dataProvider provideOnGetAutoPromoteGroups_nothingToDo
	 */
	public function testOnGetAutoPromoteGroups_nothingToDo( bool $enabled, array $groups ) {
		$cache = new HashBagOStuff();
		$store = $this->createNoOpMock( BlockAutopromoteStore::class );
		$registry = $this->getConsequencesRegistry( $enabled );
		$handler = new AutoPromoteGroupsHandler( $registry, $store, $cache );

		$user = new UserIdentityValue( 1, 'User' );
		$copy = $groups;
		$handler->onGetAutoPromoteGroups( $user, $copy );
		$this->assertSame( $groups, $copy );
		$this->assertFalse( $cache->hasKey( 'local:abusefilter:blockautopromote:quick:1' ) );
	}

	public static function provideOnGetAutoPromoteGroups(): array {
		return [
			[ 0, [ 'autoconfirmed' ], [ 'autoconfirmed' ] ],
			[ 1000, [ 'autoconfirmed' ], [] ],
		];
	}

	/**
	 * @dataProvider provideOnGetAutoPromoteGroups
	 */
	public function testOnGetAutoPromoteGroups_cacheHit(
		int $status, array $groups, array $expected
	) {
		$user = new UserIdentityValue( 1, 'User' );
		$cache = new HashBagOStuff();
		$cache->set( 'local:abusefilter:blockautopromote:quick:1', $status );
		$store = $this->createNoOpMock( BlockAutopromoteStore::class );
		$registry = $this->getConsequencesRegistry();

		$handler = new AutoPromoteGroupsHandler( $registry, $store, $cache );
		$handler->onGetAutoPromoteGroups( $user, $groups );

		$this->assertSame( $expected, $groups );
	}

	/**
	 * @dataProvider provideOnGetAutoPromoteGroups
	 */
	public function testOnGetAutoPromoteGroups_cacheMiss(
		int $status, array $groups, array $expected
	) {
		$user = new UserIdentityValue( 1, 'User' );
		$cache = new HashBagOStuff();
		$store = $this->createMock( BlockAutopromoteStore::class );
		$store->expects( $this->once() )->method( 'getAutoPromoteBlockStatus' )
			->with( $user )
			->willReturn( $status );
		$registry = $this->getConsequencesRegistry();

		$handler = new AutoPromoteGroupsHandler( $registry, $store, $cache );
		$handler->onGetAutoPromoteGroups( $user, $groups );

		$this->assertSame( $expected, $groups );
		$this->assertTrue( $cache->hasKey( 'local:abusefilter:blockautopromote:quick:1' ) );
	}
}
