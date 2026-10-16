<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\BlockedDomains;

use MediaWiki\Content\JsonContent;
use MediaWiki\Extension\AbuseFilter\BlockedDomains\BlockedDomainAllowListLookup;
use MediaWiki\Extension\AbuseFilter\BlockedDomains\BlockedDomainValidator;
use MediaWiki\Page\PageReference;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Utils\UrlUtils;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\BlockedDomains\BlockedDomainAllowListLookup
 * @covers \MediaWiki\Extension\AbuseFilter\BlockedDomains\BlockedDomainValidator
 */
class BlockedDomainAllowListLookupTest extends MediaWikiUnitTestCase {

	private function getLookup( RevisionLookup $revisionLookup ): BlockedDomainAllowListLookup {
		return new BlockedDomainAllowListLookup(
			new HashBagOStuff(),
			$revisionLookup,
			new BlockedDomainValidator( new UrlUtils() )
		);
	}

	public function testLoadComputed() {
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getContent' )->willReturn( new JsonContent( json_encode( [
			[ 'domain' => 'example.com', 'notes' => 'domain' ],
			[ 'domain' => 'Sub.Example.org', 'notes' => 'uppercase domain' ],
			[ 'domain' => 'https://Blocked.example.net/Some/Page?x=1#frag', 'notes' => 'full URL' ],
			[ 'domain' => '//blocked.example.net', 'notes' => 'protocol-relative URL' ],
			[ 'domain' => 'invalid', 'notes' => 'no dot' ],
			[ 'domain' => 'https://invalid', 'notes' => 'no dot in URL' ],
			[ 'notes' => 'missing domain' ],
			'not an object',
		] ) ) );
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionLookup->expects( $this->once() )
			->method( 'getRevisionByTitle' )
			->with( $this->callback( static fn ( PageReference $page ) =>
				$page->getWikiId() === PageReference::LOCAL &&
				$page->getNamespace() === NS_MEDIAWIKI &&
				$page->getDBkey() === 'BlockedExternalDomainsAllowList.json'
			) )
			->willReturn( $revision );

		$lookup = $this->getLookup( $revisionLookup );
		$expected = [
			'domains' => [ 'example.com' => true, 'sub.example.org' => true ],
			'urls' => [ 'blocked.example.net/Some/Page?x=1' => true, 'blocked.example.net/' => true ],
		];
		$this->assertSame( $expected, $lookup->loadComputed() );
		$this->assertSame( $expected, $lookup->loadComputed() );
	}

	public function testMissingPage() {
		$revisionLookup = $this->createMock( RevisionLookup::class );
		$revisionLookup->method( 'getRevisionByTitle' )->willReturn( null );

		$this->assertSame(
			[ 'domains' => [], 'urls' => [] ],
			$this->getLookup( $revisionLookup )->loadComputed()
		);
	}

	/**
	 * @dataProvider provideNormalizeUrl
	 */
	public function testNormalizeUrl( string $url, string|false $expected ) {
		$lookup = $this->getLookup( $this->createNoOpMock( RevisionLookup::class ) );
		$this->assertSame( $expected, $lookup->normalizeUrl( $url ) );
	}

	public static function provideNormalizeUrl() {
		return [
			[ 'https://example.org', 'example.org/' ],
			[ 'http://EXAMPLE.org/', 'example.org/' ],
			[ '//example.org/Path', 'example.org/Path' ],
			[ 'https://example.org:8080/a?b=c#d', 'example.org:8080/a?b=c' ],
			[ 'https://example.org/a?', 'example.org/a' ],
			[ 'example.org/a', false ],
			[ 'https://localhost/a', false ],
			[ '', false ],
		];
	}
}
