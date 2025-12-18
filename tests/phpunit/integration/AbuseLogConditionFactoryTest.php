<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Extension\AbuseFilter\AbuseLogConditionFactory;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @group AbuseFilter
 * @covers \MediaWiki\Extension\AbuseFilter\AbuseLogConditionFactory
 */
class AbuseLogConditionFactoryTest extends MediaWikiIntegrationTestCase {

	private AbuseLogConditionFactory $sut;

	public function setUp(): void {
		$this->sut = new AbuseLogConditionFactory();
	}

	/**
	 * @dataProvider getUserFilterForIPAddressDataProvider
	 */
	public function testGetUserFilterForIPAddress(
		array $expected,
		string $value,
	): void {
		$this->assertEquals(
			$expected,
			$this->sut->getUserFilterByIPAddress( $value )
		);
	}

	public static function getUserFilterForIPAddressDataProvider(): array {
		return [
			'single IP' => [
				'expected' => [
					'afl_user' => 0,
					'afl_user_text' => '1.2.3.4'
				],
				'value' => '1.2.3.4',
			],
			// @todo Extend this test once we add support for IP ranges (T412339)
		];
	}

	public function testGetUserFilterByUserIdentity(): void {
		$this->assertEquals(
			[
				'afl_user' => 123,
				'afl_user_text' => 'User Name'
			],
			$this->sut->getUserFilterByUserIdentity(
				new UserIdentityValue( 123, 'User Name' )
			)
		);
	}
}
