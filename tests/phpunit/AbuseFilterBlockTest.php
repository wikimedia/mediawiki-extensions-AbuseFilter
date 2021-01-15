<?php

use MediaWiki\Block\BlockUser;
use MediaWiki\Block\BlockUserFactory;
use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Extension\AbuseFilter\Consequences\Consequence\Block;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Consequences\Consequence\Block
 * @covers \MediaWiki\Extension\AbuseFilter\Consequences\Consequence\BlockingConsequence
 * @todo Make this a unit test once T266409 is resolved
 */
class AbuseFilterBlockTest extends MediaWikiIntegrationTestCase {
	use ConsequenceGetMessageTestTrait;

	private function getMsgLocalizer() : MessageLocalizer {
		$ml = $this->createMock( MessageLocalizer::class );
		$ml->method( 'msg' )->willReturnCallback( function ( $k, $p ) {
			return $this->getMockMessage( $k, $p );
		} );
		return $ml;
	}

	private function getFilterUser() : FilterUser {
		$filterUser = $this->createMock( FilterUser::class );
		$filterUser->method( 'getUser' )
			->willReturn( new UserIdentityValue( 2, 'FilterUser', 3 ) );
		return $filterUser;
	}

	public function provideExecute() : iterable {
		foreach ( [ true, false ] as $result ) {
			$resStr = wfBoolToStr( $result );
			yield "IPv4, $resStr" => [ new UserIdentityValue( 0, '1.2.3.4', 42 ), $result ];
			yield "IPv6, $resStr" => [
				// random IP from https://en.wikipedia.org/w/index.php?title=IPv6&oldid=989727833
				new UserIdentityValue( 0, '2001:0db8:0000:0000:0000:ff00:0042:8329', 42 ),
				$result
			];
			yield "Registered, $resStr" => [ new UserIdentityValue( 3, 'Some random user', 42 ), $result ];
		}
	}

	/**
	 * @dataProvider provideExecute
	 * @covers ::__construct
	 * @covers ::execute
	 */
	public function testExecute( UserIdentity $target, bool $result ) {
		$expiry = '1 day';
		$params = $this->provideGetMessageParameters( $target )->current()[0];
		$blockUser = $this->createMock( BlockUser::class );
		$blockUser->expects( $this->once() )
			->method( 'placeBlockUnsafe' )
			->willReturn( $result ? Status::newGood() : Status::newFatal( 'error' ) );
		$blockUserFactory = $this->createMock( BlockUserFactory::class );
		$blockUserFactory->expects( $this->once() )
			->method( 'newBlockUser' )
			->with(
				$target->getName(),
				$this->anything(),
				$expiry,
				$this->anything(),
				$this->anything()
			)
			->willReturn( $blockUser );

		$block = new Block(
			$params,
			$expiry,
			$preventsTalkEdit = true,
			$blockUserFactory,
			$this->createMock( DatabaseBlockStore::class ),
			$this->getFilterUser(),
			$this->getMsgLocalizer()
		);
		$this->assertSame( $result, $block->execute() );
	}

	/**
	 * @covers ::getMessage
	 * @dataProvider provideGetMessageParameters
	 */
	public function testGetMessage( Parameters $params ) {
		$block = new Block(
			$params,
			'0',
			false,
			$this->createMock( BlockUserFactory::class ),
			$this->createMock( DatabaseBlockStore::class ),
			$this->createMock( FilterUser::class ),
			$this->getMsgLocalizer()
		);
		$this->doTestGetMessage( $block, $params, 'abusefilter-blocked-display' );
	}

	public function provideRevert() {
		yield 'no block to revert' => [ null, null, false ];

		$getMockRow = function ( UserIdentity $performer ) {
			return new class( $performer ) extends stdClass {
				private $performer;

				public function __construct( UserIdentity $performer ) {
					$this->performer = $performer;
				}

				public function __get( string $prop ) {
					switch ( $prop ) {
						case 'ipb_by':
							return $this->performer->getId();
						case 'ipb_by_text':
							return $this->performer->getName();
						case 'ipb_by_actor':
							return $this->performer->getActorId();
						case 'ipb_auto':
							return false;
						default:
							return 'foo';
					}
				}
			};
		};

		$randomUser = new UserIdentityValue( 1234, 'Some other user', 3456 );
		yield 'not blocked by AF user' => [ $getMockRow( $randomUser ), null, false ];

		$filterUserIdentity = $this->getFilterUser()->getUser();
		$failBlockStore = $this->createMock( DatabaseBlockStore::class );
		$failBlockStore->expects( $this->once() )->method( 'deleteBlock' )->willReturn( false );
		yield 'cannot delete block' => [ $getMockRow( $filterUserIdentity ), $failBlockStore, false ];

		$succeedBlockStore = $this->createMock( DatabaseBlockStore::class );
		$succeedBlockStore->expects( $this->once() )->method( 'deleteBlock' )->willReturn( true );
		yield 'succeed' => [ $getMockRow( $filterUserIdentity ), $succeedBlockStore, true ];
	}

	/**
	 * @covers ::revert
	 * @dataProvider provideRevert
	 * @todo This sucks. Clean it up once T255433 and T253717 are resolved
	 */
	public function testRevert( ?stdClass $blockRow, ?DatabaseBlockStore $blockStore, bool $expected ) {
		// Unset all hook handlers per T272124
		$this->setService( 'HookContainer', $this->createHookContainer() );
		$db = $this->createMock( IDatabase::class );
		$db->method( 'select' )->willReturnCallback( function ( $tables ) use ( $blockRow ) {
			return in_array( 'ipblocks', $tables, true ) && $blockRow ? [ $blockRow ] : [];
		} );
		$lb = $this->createMock( ILoadBalancer::class );
		$lb->method( 'getMaintenanceConnectionRef' )->willReturn( $db );
		$this->setService( 'DBLoadBalancer', $lb );
		$commentStore = $this->createMock( CommentStore::class );
		$migrationData = [ 'tables' => [], 'fields' => [], 'joins' => [] ];
		$commentStore->method( 'getJoin' )->willReturn( $migrationData );
		$commentStore->method( 'insert' )->willReturn( [] );
		$this->setService( 'CommentStore', $commentStore );
		$actorMigration = $this->createMock( ActorMigration::class );
		$actorMigration->method( 'getInsertValues' )->willReturn( [] );
		$actorMigration->method( 'getJoin' )->willReturn( $migrationData );
		$this->setService( 'ActorMigration', $actorMigration );
		$this->setService( 'LinkCache', $this->createMock( LinkCache::class ) );

		$params = $this->createMock( Parameters::class );
		$params->method( 'getUser' )->willReturn( new UserIdentityValue( 1, 'Foobar', 2 ) );
		$block = new Block(
			$params,
			'0',
			false,
			$this->createMock( BlockUserFactory::class ),
			$blockStore ?? $this->createMock( DatabaseBlockStore::class ),
			$this->getFilterUser(),
			$this->getMsgLocalizer()
		);
		$this->assertSame( $expected, $block->revert( [], $this->createMock( UserIdentity::class ), '' ) );
	}
}
