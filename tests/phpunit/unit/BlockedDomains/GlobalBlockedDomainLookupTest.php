<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\BlockedDomains;

use MediaWiki\Content\JsonContent;
use MediaWiki\Extension\AbuseFilter\BlockedDomains\BlockedDomainValidator;
use MediaWiki\Extension\AbuseFilter\BlockedDomains\GlobalBlockedDomainLookup;
use MediaWiki\Page\PageReference;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Utils\UrlUtils;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\Rdbms\DBConnectionError;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\BlockedDomains\GlobalBlockedDomainLookup
 */
class GlobalBlockedDomainLookupTest extends MediaWikiUnitTestCase {

	private function getLookup( RevisionStoreFactory $factory, string|false $centralWiki ): GlobalBlockedDomainLookup {
		return new GlobalBlockedDomainLookup(
			new HashBagOStuff(),
			$factory,
			new BlockedDomainValidator( new UrlUtils() ),
			new NullLogger(),
			$centralWiki
		);
	}

	public function testDisabled() {
		$lookup = $this->getLookup( $this->createNoOpMock( RevisionStoreFactory::class ), false );
		$this->assertSame( [], $lookup->loadComputed() );
	}

	public function testLoadComputed() {
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getContent' )->willReturn( new JsonContent( json_encode( [
			[ 'domain' => 'example.com', 'notes' => 'spam' ],
			[ 'domain' => 'sub.example.org', 'notes' => '' ],
			[ 'domain' => 'invalid', 'notes' => 'no dot' ],
			[ 'notes' => 'missing domain' ],
		] ) ) );
		$store = $this->createMock( RevisionStore::class );
		$store->expects( $this->once() )
			->method( 'getRevisionByTitle' )
			->with( $this->callback( static fn ( PageReference $page ) =>
				$page->getWikiId() === 'metawiki' &&
				$page->getNamespace() === NS_MEDIAWIKI &&
				$page->getDBkey() === 'BlockedExternalDomains.json'
			) )
			->willReturn( $revision );
		$factory = $this->createMock( RevisionStoreFactory::class );
		$factory->method( 'getRevisionStore' )->with( 'metawiki' )->willReturn( $store );

		$lookup = $this->getLookup( $factory, 'metawiki' );
		$expected = [ 'example.com' => true, 'sub.example.org' => true ];
		$this->assertSame( $expected, $lookup->loadComputed() );
		// Second call is served from cache
		$this->assertSame( $expected, $lookup->loadComputed() );
	}

	public function testMissingPage() {
		$store = $this->createMock( RevisionStore::class );
		$store->method( 'getRevisionByTitle' )->willReturn( null );
		$factory = $this->createMock( RevisionStoreFactory::class );
		$factory->method( 'getRevisionStore' )->willReturn( $store );

		$this->assertSame( [], $this->getLookup( $factory, 'metawiki' )->loadComputed() );
	}

	public function testDBError() {
		$store = $this->createMock( RevisionStore::class );
		$store->method( 'getRevisionByTitle' )->willThrowException( new DBConnectionError() );
		$factory = $this->createMock( RevisionStoreFactory::class );
		$factory->method( 'getRevisionStore' )->willReturn( $store );

		$this->assertSame( [], $this->getLookup( $factory, 'metawiki' )->loadComputed() );
	}
}
