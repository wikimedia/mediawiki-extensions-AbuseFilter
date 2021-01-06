<?php

use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Extension\AbuseFilter\Consequences\Consequence\BlockAutopromote;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\User\UserIdentityValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Consequences\Consequence\BlockAutopromote
 * @covers ::__construct
 * @todo with MessageLocalizer injected, this can be a unit test
 */
class BlockAutopromoteTest extends MediaWikiIntegrationTestCase {
	use ConsequenceGetMessageTestTrait;

	/**
	 * @covers ::execute
	 */
	public function testExecute_anonymous() {
		$user = new UserIdentityValue( 0, 'Anonymous user', 1 );
		$params = $this->provideGetMessageParameters( $user )->current()[0];
		$blockAutopromoteStore = $this->createMock( BlockAutopromoteStore::class );
		$blockAutopromoteStore->expects( $this->never() )
			->method( 'blockAutoPromote' );
		$blockAutopromote = new BlockAutopromote(
			$params,
			5 * 86400,
			$blockAutopromoteStore
		);
		$this->assertFalse( $blockAutopromote->execute() );
	}

	/**
	 * @covers ::execute
	 * @dataProvider provideExecute
	 */
	public function testExecute( bool $success ) {
		$target = new UserIdentityValue( 1, 'A new user', 2 );
		$params = $this->provideGetMessageParameters( $target )->current()[0];
		$duration = 5 * 86400;
		$blockAutopromoteStore = $this->createMock( BlockAutopromoteStore::class );
		$blockAutopromoteStore->expects( $this->once() )
			->method( 'blockAutoPromote' )
			->with( $target, $this->anything(), $duration )
			->willReturn( $success );
		$blockAutopromote = new BlockAutopromote( $params, $duration, $blockAutopromoteStore );
		$this->assertSame( $success, $blockAutopromote->execute() );
	}

	public function provideExecute() : array {
		return [
			[ true ],
			[ false ]
		];
	}

	/**
	 * @covers ::revert
	 * @dataProvider provideExecute
	 */
	public function testRevert( bool $success ) {
		$target = new UserIdentityValue( 1, 'A new user', 2 );
		$performer = new UserIdentityValue( 2, 'Reverting user', 3 );
		$params = $this->provideGetMessageParameters( $target )->current()[0];
		$blockAutopromoteStore = $this->createMock( BlockAutopromoteStore::class );
		$blockAutopromoteStore->expects( $this->once() )
			->method( 'unblockAutoPromote' )
			->with( $target, $performer, $this->anything() )
			->willReturn( $success );
		$blockAutopromote = new BlockAutopromote( $params, 0, $blockAutopromoteStore );
		$this->assertSame( $success, $blockAutopromote->revert( [], $performer, 'reason' ) );
	}

	/**
	 * @covers ::getMessage
	 * @dataProvider provideGetMessageParameters
	 */
	public function testGetMessage( Parameters $params ) {
		$rangeBlock = new BlockAutopromote(
			$params,
			83,
			$this->createMock( BlockAutopromoteStore::class )
		);
		$this->doTestGetMessage( $rangeBlock, $params, 'abusefilter-autopromote-blocked' );
	}
}
