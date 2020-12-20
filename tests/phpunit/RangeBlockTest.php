<?php

use MediaWiki\Block\BlockUser;
use MediaWiki\Block\BlockUserFactory;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Consequences\Consequence\RangeBlock;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Consequences\Consequence\RangeBlock
 */
class RangeBlockTest extends MediaWikiIntegrationTestCase {

	private const CIDR_LIMIT = [
		'IPv4' => 16,
		'IPv6' => 19,
	];

	private function getParameters( UserIdentity $user ) : Parameters {
		$filter = MutableFilter::newDefault();
		$filter->setID( 1 );
		$filter->setName( 'Degrouping filter' );
		return new Parameters(
			$filter,
			false,
			$user,
			$this->createMock( LinkTarget::class ),
			'edit'
		);
	}

	public function provideExecute() : iterable {
		yield 'IPv4 range block' => [
			'1.2.3.4',
			[
				'IPv4' => 16,
				'IPv6' => 18,
			],
			'1.2.0.0/16',
			true
		];
		yield 'IPv6 range block' => [
			// random IP from https://en.wikipedia.org/w/index.php?title=IPv6&oldid=989727833
			'2001:0db8:0000:0000:0000:ff00:0042:8329',
			[
				'IPv4' => 15,
				'IPv6' => 19,
			],
			'2001:0:0:0:0:0:0:0/19',
			true
		];
		yield 'IPv4 range block constrained by core limits' => [
			'1.2.3.4',
			[
				'IPv4' => 15,
				'IPv6' => 19,
			],
			'1.2.0.0/16',
			true
		];
		yield 'IPv6 range block constrained by core limits' => [
			'2001:0db8:0000:0000:0000:ff00:0042:8329',
			[
				'IPv4' => 16,
				'IPv6' => 18,
			],
			'2001:0:0:0:0:0:0:0/19',
			true
		];
		yield 'failure' => [
			'1.2.3.4',
			self::CIDR_LIMIT,
			'1.2.0.0/16',
			false
		];
	}

	/**
	 * @dataProvider provideExecute
	 * @covers ::__construct
	 * @covers ::execute
	 */
	public function testExecute(
		string $requestIP, array $rangeBlockSize, string $target, bool $result
	) {
		$user = new UserIdentityValue( 1, 'Degrouped user', 2 );
		$params = $this->getParameters( $user );
		/*
		$filterUser = $this->createMock( FilterUser::class );
		$filterUser->method( 'getUser' )
			->willReturn( new UserIdentityValue( 2, 'FilterUser', 3 ) );
		*/
		$filterUser = AbuseFilterServices::getFilterUser();
		$blockUser = $this->createMock( BlockUser::class );
		$blockUser->expects( $this->once() )
			->method( 'placeBlockUnsafe' )
			->willReturn( $result ? Status::newGood() : Status::newFatal( 'error' ) );
		$blockUserFactory = $this->createMock( BlockUserFactory::class );
		$blockUserFactory->expects( $this->once() )
			->method( 'newBlockUser' )
			->with(
				$target,
				$this->anything(),
				'1 week',
				$this->anything(),
				$this->anything()
			)
			->willReturn( $blockUser );

		$rangeBlock = new RangeBlock(
			$params,
			'1 week',
			$blockUserFactory,
			$filterUser,
			$rangeBlockSize,
			self::CIDR_LIMIT,
			$requestIP
		);
		$this->assertSame( $result, $rangeBlock->execute() );
	}

}
