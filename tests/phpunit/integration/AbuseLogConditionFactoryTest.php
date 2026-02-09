<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Extension\AbuseFilter\AbuseLogConditionFactory;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\OrExpressionGroup;

/**
 * @group AbuseFilter
 * @group Database
 * @covers \MediaWiki\Extension\AbuseFilter\AbuseLogConditionFactory
 */
class AbuseLogConditionFactoryTest extends MediaWikiIntegrationTestCase {

	use TempUserTestTrait;

	private AbuseLogConditionFactory $sut;

	public function setUp(): void {
		$services = $this->getServiceContainer();

		// Set it to a known state
		$this->enableAutoCreateTempUser();

		$this->sut = new AbuseLogConditionFactory(
			$services->getConnectionProvider(),
			$services->getTempUserConfig()
		);
	}

	/**
	 * @dataProvider getUserFilterByIPAddressDataProvider
	 */
	public function testGetUserFilterByIPAddress(
		?string $expectedGeneralizedSql,
		?string $expectedSql,
		string $value
	): void {
		$expression = $this->sut->getUserFilterByIPAddress( $value );

		if ( $expectedGeneralizedSql === null ) {
			$this->assertNull( $expression );
		} else {
			$this->assertEquals(
				$expectedGeneralizedSql,
				$expression->toGeneralizedSql()
			);
			$this->assertEquals(
				$expectedSql,
				$expression->toSql( $this->getDb() )
			);
		}
	}

	public static function getUserFilterByIPAddressDataProvider(): array {
		// The lookup is made by IP in afl_ip_hex, while also looking up
		// for legacy anonymous users for that IP.

		return [
			'single IP' => [
				'expectedGeneralizedSql' =>
					'((afl_ip_hex = ? AND afl_user_text LIKE ?) OR ' .
					'(afl_user_text = ? AND afl_user = ?))',
				'expectedSql' => '(' .
					"(afl_ip_hex = '01020304'" .
					" AND afl_user_text LIKE '~%' ESCAPE '`') OR " .
					"(afl_user_text = '1.2.3.4' AND afl_user = 0))",
				'value' => '1.2.3.4',
			],
			'Valid IP range' => [
				'expectedGeneralizedSql' =>
					'(afl_ip_hex >= ? AND afl_ip_hex <= ? AND afl_user_text LIKE ?)',
				'expectedSql' => '(' .
					"afl_ip_hex >= 'AC100000'" .
					" AND afl_ip_hex <= 'AC1FFFFF' AND " .
					"afl_user_text LIKE '~%' ESCAPE '`')",
				'value' => '172.16.0.0/12',
			],
			'Malformed IP range' => [
				'expectedGeneralizedSql' => null,
				'expectedSql' => null,
				'value' => '172.16.0.0/12/13',
			],
		];
	}

	/**
	 * @dataProvider provideGetUserFilterByUserIdentity
	 */
	public function testGetUserFilterByUserIdentity( int $id, string $username ): void {
		$conds = $this->sut->getUserFilterByUserIdentity(
			new UserIdentityValue( $id, $username )
		);

		$this->assertArrayHasKey( 'afl_user_text', $conds );
		$this->assertSame( $username, $conds['afl_user_text'] );

		if ( IPUtils::isIPAddress( $username ) ) {
			$this->assertArrayHasKey( 'afl_user', $conds );
			$this->assertSame( $id, $conds['afl_user'] );
		} else {
			$this->assertArrayNotHasKey( 'afl_user', $conds );

			$orConds = array_values( $conds )[0];
			$this->assertInstanceOf( OrExpressionGroup::class, $orConds );

			$actions = "'createaccount','autocreateaccount'";
			$this->assertSame(
				"(afl_user = $id OR (afl_user = 0 AND afl_action IN ($actions)))",
				$orConds->toSql( $this->getDb() )
			);
		}
	}

	public static function provideGetUserFilterByUserIdentity(): array {
		return [
			'IP user identity' => [
				'id' => 0,
				'username' => '1.2.3.4'
			],
			'Registered user identity' => [
				'id' => 123,
				'username' => 'User Name'
			],
		];
	}
}
