<?php

use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Extension\AbuseFilter\Consequences\Consequence\BlockAutopromote;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\Filter\Filter;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\User\UserIdentityValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Consequences\Consequence\BlockAutopromote
 * @covers ::__construct
 * @todo with MessageLocalizer injected, this can be a unit test
 */
class BlockAutopromoteTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers ::execute
	 */
	public function testExecute_anonymous() {
		$params = new Parameters(
			$this->createMock( Filter::class ),
			false,
			new UserIdentityValue( 0, 'Anonymous user', 1 ),
			$this->createMock( LinkTarget::class ),
			'edit'
		);
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
		$params = new Parameters(
			$this->createMock( Filter::class ),
			false,
			new UserIdentityValue( 1, 'A new user', 2 ),
			$this->createMock( LinkTarget::class ),
			'edit'
		);
		$blockAutopromoteStore = $this->createMock( BlockAutopromoteStore::class );
		$blockAutopromoteStore->expects( $this->once() )
			->method( 'blockAutoPromote' )
			->willReturn( $success );
		$blockAutopromote = new BlockAutopromote(
			$params,
			5 * 86400,
			$blockAutopromoteStore
		);
		$this->assertSame( $success, $blockAutopromote->execute() );
	}

	public function provideExecute() : array {
		return [
			[ true ],
			[ false ]
		];
	}

}
