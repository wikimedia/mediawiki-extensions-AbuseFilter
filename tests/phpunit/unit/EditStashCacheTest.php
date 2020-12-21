<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use HashBagOStuff;
use MediaWiki\Extension\AbuseFilter\EditStashCache;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\Variables\LazyVariableComputer;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWikiUnitTestCase;
use NullStatsdDataFactory;
use Psr\Log\NullLogger;
use TestLogger;
use Title;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\EditStashCache
 */
class EditStashCacheTest extends MediaWikiUnitTestCase {

	private function getTitle() : Title {
		$title = $this->createMock( Title::class );
		$title->method( '__toString' )->willReturn( 'Prefixed:Text' );
		return $title;
	}

	private function getVariablesManager() : VariablesManager {
		return new VariablesManager(
			$this->createMock( KeywordsManager::class ),
			$this->createMock( LazyVariableComputer::class ),
			new NullLogger()
		);
	}

	/**
	 * @covers ::store
	 * @covers ::logCache
	 * @covers ::getStashKey
	 */
	public function testStore() {
		$cache = $this->getMockBuilder( HashBagOStuff::class )
			->onlyMethods( [ 'set' ] )
			->getMock();
		$cache->expects( $this->once() )->method( 'set' );
		$logger = new TestLogger( true );
		$stash = new EditStashCache(
			$cache,
			new NullStatsdDataFactory(),
			$this->getVariablesManager(),
			$logger,
			$this->getTitle(),
			'default'
		);
		$vars = VariableHolder::newFromArray( [ 'page_title' => 'Title' ] );
		$data = [ 'foo' => 'bar' ];
		$stash->store( $vars, $data );

		$found = false;
		foreach ( $logger->getBuffer() as list( , $entry ) ) {
			$check = preg_match(
				"/^.+: cache store for 'Prefixed:Text' \(key .+\)\.$/",
				$entry
			);
			if ( $check ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, "Test that store operation is logged." );
	}

	public function provideRoundTrip() {
		$simple = [ 'page_title' => 'Title', 'new_wikitext' => 'Foo Bar' ];
		yield 'simple' => [ $simple, $simple ];
		yield 'noisy' => [
			$simple + [ 'user_age' => 100 ],
			$simple + [ 'user_age' => 200 ],
		];
		$reverse = [
			'new_wikitext' => $simple['new_wikitext'],
			'page_title' => $simple['page_title'],
		];
		yield 'different order' => [
			$reverse + [ 'page_age' => 100 ],
			$reverse + [ 'page_age' => 200 ],
		];
	}

	/**
	 * @covers ::store
	 * @covers ::logCache
	 * @covers ::seek
	 * @covers ::getStashKey
	 * @dataProvider provideRoundTrip
	 */
	public function testRoundTrip( array $storeVars, array $seekVars ) {
		$cache = new HashBagOStuff();
		$logger = new TestLogger( true );
		$stash = new EditStashCache(
			$cache,
			new NullStatsdDataFactory(),
			$this->getVariablesManager(),
			$logger,
			$this->getTitle(),
			'default'
		);
		$storeHolder = VariableHolder::newFromArray( $storeVars );
		$data = [ 'foo' => 'bar' ];
		$stash->store( $storeHolder, $data );

		$seekHolder = VariableHolder::newFromArray( $seekVars );
		$value = $stash->seek( $seekHolder );
		$this->assertArrayEquals( $data, $value );

		$found = false;
		foreach ( $logger->getBuffer() as list( , $entry ) ) {
			$check = preg_match(
				"/^.+: cache hit for 'Prefixed:Text' \(key .+\)\.$/",
				$entry
			);
			if ( $check ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, "Test that cache hit is logged." );
	}

	/**
	 * @covers ::seek
	 * @covers ::logCache
	 * @covers ::getStashKey
	 */
	public function testSeek_miss() {
		$cache = new HashBagOStuff();
		$logger = new TestLogger( true );
		$stash = new EditStashCache(
			$cache,
			new NullStatsdDataFactory(),
			$this->getVariablesManager(),
			$logger,
			$this->getTitle(),
			'default'
		);
		$vars = VariableHolder::newFromArray( [ 'page_title' => 'Title' ] );
		$value = $stash->seek( $vars );
		$this->assertFalse( $value );

		$found = false;
		foreach ( $logger->getBuffer() as list( , $entry ) ) {
			$check = preg_match(
				"/^.+: cache miss for 'Prefixed:Text' \(key .+\)\.$/",
				$entry
			);
			if ( $check ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, "Test that cache miss is logged." );
	}

}
