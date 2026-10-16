<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\BlockedDomains;

use MediaWiki\Extension\AbuseFilter\BlockedDomains\BlockedDomainAllowListLookup;
use MediaWiki\Extension\AbuseFilter\BlockedDomains\BlockedDomainFilter;
use MediaWiki\Extension\AbuseFilter\BlockedDomains\BlockedDomainValidator;
use MediaWiki\Extension\AbuseFilter\BlockedDomains\GlobalBlockedDomainLookup;
use MediaWiki\Extension\AbuseFilter\BlockedDomains\IBlockedDomainStorage;
use MediaWiki\Extension\AbuseFilter\Parser\AFPData;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Page\PageReference;
use MediaWiki\Utils\UrlUtils;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AbuseFilter\BlockedDomains\BlockedDomainFilter
 */
class BlockedDomainFilterTest extends MediaWikiIntegrationTestCase {

	/**
	 * @dataProvider provideDomains
	 */
	public function test( string $domain, ?string $expectedError ) {
		$this->assertFilterResult( [ "https://$domain" ], $expectedError );
	}

	/**
	 * @dataProvider provideAllowList
	 */
	public function testAllowList( array $urls, ?string $expectedError ) {
		$this->assertFilterResult( $urls, $expectedError );
	}

	private function assertFilterResult( array $urls, ?string $expectedError ) {
		$manager = $this->createMock( VariablesManager::class );
		$manager->method( 'getVar' )->willReturn(
			AFPData::newFromPHPVar( $urls )
		);

		$storage = $this->createMock( IBlockedDomainStorage::class );
		$storage->method( 'loadComputed' )->willReturn( array_flip( [
			'blocked',
			'bad.tld',
		] ) );

		$globalLookup = $this->createMock( GlobalBlockedDomainLookup::class );
		$globalLookup->method( 'loadComputed' )->willReturn( array_flip( [
			'global.tld',
		] ) );

		$validator = new BlockedDomainValidator( new UrlUtils() );
		$allowListLookup = $this->createMock( BlockedDomainAllowListLookup::class );
		$allowListLookup->method( 'loadComputed' )->willReturn( [
			'domains' => array_flip( [ 'good.bad.tld', 'good.global.tld' ] ),
			'urls' => array_flip( [
				$validator->normalizeUrl( 'https://bad.tld/allowed' ),
				$validator->normalizeUrl( 'https://global.tld/allowed?a=b' ),
			] ),
		] );
		$allowListLookup->method( 'normalizeUrl' )->willReturnCallback( $validator->normalizeUrl( ... ) );

		$filter = new BlockedDomainFilter( $manager, $storage, $globalLookup, $allowListLookup );
		$status = $filter->filter(
			new VariableHolder(),
			$this->getTestUser()->getUserIdentity(),
			$this->createMock( PageReference::class )
		);

		if ( $expectedError ) {
			$this->assertStatusError( $expectedError, $status );
		} else {
			$this->assertStatusGood( $status );
		}
	}

	public function testAllowListNotLoadedWithoutBlockedDomain() {
		$manager = $this->createMock( VariablesManager::class );
		$manager->method( 'getVar' )->willReturn(
			AFPData::newFromPHPVar( [ 'https://fine.tld', 'https://also.fine.tld/page' ] )
		);
		$storage = $this->createMock( IBlockedDomainStorage::class );
		$storage->method( 'loadComputed' )->willReturn( [ 'bad.tld' => true ] );
		$allowListLookup = $this->createMock( BlockedDomainAllowListLookup::class );
		$allowListLookup->expects( $this->never() )->method( 'loadComputed' );

		$filter = new BlockedDomainFilter( $manager, $storage, null, $allowListLookup );
		$status = $filter->filter(
			new VariableHolder(),
			$this->getTestUser()->getUserIdentity(),
			$this->createMock( PageReference::class )
		);
		$this->assertStatusGood( $status );
	}

	public static function provideDomains() {
		return [
			[ '', null ],
			[ 'a', null ],

			[ 'blocked', 'abusefilter-blocked-domains-attempted' ],
			[ 'also.blocked', 'abusefilter-blocked-domains-attempted' ],
			[ '.blocked', 'abusefilter-blocked-domains-attempted' ],
			[ 'blocked.not', null ],

			[ 'bad', null ],
			[ 'bad.tld', 'abusefilter-blocked-domains-attempted' ],
			[ 'also.bad.tld', 'abusefilter-blocked-domains-attempted' ],
			[ '.bad.tld', 'abusefilter-blocked-domains-attempted' ],
			[ 'bad.tld.not', null ],

			[ 'global.tld', 'abusefilter-blocked-domains-attempted' ],
			[ 'also.global.tld', 'abusefilter-blocked-domains-attempted' ],
			[ 'global.tld.not', null ],
		];
	}

	public static function provideAllowList() {
		return [
			'allowed domain' => [ [ 'https://good.bad.tld/foo' ], null ],
			'subdomain of allowed domain' => [ [ 'https://sub.good.bad.tld' ], null ],
			'allowed domain, global list' => [ [ 'https://good.global.tld' ], null ],
			'sibling of allowed domain' => [ [ 'https://evil.bad.tld' ], 'abusefilter-blocked-domains-attempted' ],
			'parent of allowed domain' => [ [ 'https://bad.tld' ], 'abusefilter-blocked-domains-attempted' ],

			'allowed URL' => [ [ 'https://bad.tld/allowed' ], null ],
			'allowed URL, different case and scheme' => [ [ 'http://BAD.tld/allowed#top' ], null ],
			'allowed URL, global list' => [ [ 'https://global.tld/allowed?a=b' ], null ],
			'other path on allowed URL domain' => [
				[ 'https://bad.tld/allowed/more' ], 'abusefilter-blocked-domains-attempted'
			],
			'other query on allowed URL domain' => [
				[ 'https://global.tld/allowed?a=c' ], 'abusefilter-blocked-domains-attempted'
			],

			'allowed and blocked links together' => [
				[ 'https://bad.tld/allowed', 'https://bad.tld/other' ], 'abusefilter-blocked-domains-attempted'
			],
			'only allowed links' => [ [ 'https://bad.tld/allowed', 'https://good.bad.tld' ], null ],
		];
	}

}
