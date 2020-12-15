<?php

use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Extension\AbuseFilter\ConsequencesRegistry;
use MediaWiki\Extension\AbuseFilter\Hooks\Handlers\AutoPromoteGroupsHandler;
use MediaWiki\User\UserIdentityValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Hooks\Handlers\AutoPromoteGroupsHandler
 * @covers ::__construct
 * @covers ::onGetAutoPromoteGroups
 */
class AutoPromoteGroupsHandlerTest extends MediaWikiUnitTestCase {

	private function getConsequencesRegistry( bool $enabled = true ) : ConsequencesRegistry {
		$registry = $this->createMock( ConsequencesRegistry::class );
		$registry->method( 'getAllEnabledActionNames' )
			->willReturn( $enabled ? [ 'tag', 'blockautopromote' ] : [ 'tag' ] );
		return $registry;
	}

	public function provideOnGetAutoPromoteGroups_nothingToDo() : array {
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
		$store = $this->createMock( BlockAutopromoteStore::class );
		$store->expects( $this->never() )->method( $this->anything() );
		$registry = $this->getConsequencesRegistry( $enabled );
		$handler = new AutoPromoteGroupsHandler( $cache, $registry, $store );

		$user = new UserIdentityValue( 1, 'User', 1 );
		$copy = $groups;
		$handler->onGetAutoPromoteGroups( $user, $copy );
		$this->assertSame( $groups, $copy );
		$this->assertFalse( $cache->hasKey( 'local:abusefilter:blockautopromote:quick:1' ) );
	}

	public function provideOnGetAutoPromoteGroups() : array {
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
		$user = new UserIdentityValue( 1, 'User', 1 );
		$cache = new HashBagOStuff();
		$cache->set( 'local:abusefilter:blockautopromote:quick:1', $status );
		$store = $this->createMock( BlockAutopromoteStore::class );
		$store->expects( $this->never() )->method( $this->anything() );
		$registry = $this->getConsequencesRegistry();

		$handler = new AutoPromoteGroupsHandler( $cache, $registry, $store );
		$handler->onGetAutoPromoteGroups( $user, $groups );

		$this->assertSame( $expected, $groups );
	}

	/**
	 * @dataProvider provideOnGetAutoPromoteGroups
	 */
	public function testOnGetAutoPromoteGroups_cacheMiss(
		int $status, array $groups, array $expected
	) {
		$user = new UserIdentityValue( 1, 'User', 1 );
		$cache = new HashBagOStuff();
		$store = $this->createMock( BlockAutopromoteStore::class );
		$store->expects( $this->once() )->method( 'getAutoPromoteBlockStatus' )
			->with( $user )
			->willReturn( $status );
		$registry = $this->getConsequencesRegistry();

		$handler = new AutoPromoteGroupsHandler( $cache, $registry, $store );
		$handler->onGetAutoPromoteGroups( $user, $groups );

		$this->assertSame( $expected, $groups );
		$this->assertTrue( $cache->hasKey( 'local:abusefilter:blockautopromote:quick:1' ) );
	}

}
