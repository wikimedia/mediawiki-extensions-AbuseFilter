<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use Language;
use LogicException;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\Parser\AFPData;
use MediaWiki\Extension\AbuseFilter\Parser\AFPException;
use MediaWiki\Extension\AbuseFilter\TextExtractor;
use MediaWiki\Extension\AbuseFilter\Variables\LazyLoadedVariable;
use MediaWiki\Extension\AbuseFilter\Variables\LazyVariableComputer;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionStore;
use MediaWikiUnitTestCase;
use Parser;
use Psr\Log\NullLogger;
use TitleFactory;
use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Variables\LazyVariableComputer
 * @covers ::__construct
 */
class LazyVariableComputerTest extends MediaWikiUnitTestCase {

	private function getComputer(
		Language $contentLanguage = null,
		array $hookHandlers = [],
		string $wikiID = ''
	) : LazyVariableComputer {
		return new LazyVariableComputer(
			$this->createMock( TextExtractor::class ),
			new AbuseFilterHookRunner( $this->createHookContainer( $hookHandlers ) ),
			$this->createMock( TitleFactory::class ),
			new NullLogger(),
			$this->createMock( ILoadBalancer::class ),
			$this->createMock( WANObjectCache::class ),
			$this->createMock( RevisionLookup::class ),
			$this->createMock( RevisionStore::class ),
			$contentLanguage ?? $this->createMock( Language::class ),
			$this->createMock( Parser::class ),
			$wikiID
		);
	}

	private function getForbidComputeCB() : callable {
		return function () {
			throw new LogicException( 'Not expected to be called' );
		};
	}

	/**
	 * @covers ::compute
	 */
	public function testWikiNameVar() {
		$fakeID = 'some-wiki-ID';
		$var = new LazyLoadedVariable( 'get-wiki-name', [] );
		$computer = $this->getComputer( null, [], $fakeID );
		$this->assertSame(
			$fakeID,
			$computer->compute( $var, new VariableHolder(), $this->getForbidComputeCB() )->toNative()
		);
	}

	/**
	 * @covers ::compute
	 */
	public function testWikiLanguageVar() {
		$fakeCode = 'foobar';
		$fakeLang = $this->createMock( Language::class );
		$fakeLang->method( 'getCode' )->willReturn( $fakeCode );
		$computer = $this->getComputer( $fakeLang );
		$var = new LazyLoadedVariable( 'get-wiki-language', [] );
		$this->assertSame(
			$fakeCode,
			$computer->compute( $var, new VariableHolder(), $this->getForbidComputeCB() )->toNative()
		);
	}

	/**
	 * @covers ::compute
	 */
	public function testCompute_invalidName() {
		$computer = $this->getComputer();
		$this->expectException( AFPException::class );
		$computer->compute(
			new LazyLoadedVariable( 'method-does-not-exist', [] ),
			new VariableHolder(),
			$this->getForbidComputeCB()
		);
	}

	/**
	 * @covers ::compute
	 */
	public function testInterceptVariableHook() {
		$expected = new AFPData( AFPData::DSTRING, 'foobar' );
		$handler = function ( $method, $vars, $params, &$result ) use ( $expected ) {
			$result = $expected;
			return false;
		};
		$computer = $this->getComputer( null, [ 'AbuseFilter-interceptVariable' => $handler ] );
		$actual = $computer->compute(
			new LazyLoadedVariable( 'get-wiki-name', [] ),
			new VariableHolder(),
			$this->getForbidComputeCB()
		);
		$this->assertSame( $expected, $actual );
	}

	/**
	 * @covers ::compute
	 */
	public function testComputeVariableHook() {
		$expected = new AFPData( AFPData::DSTRING, 'foobar' );
		$handler = function ( $method, $vars, $params, &$result ) use ( $expected ) {
			$result = $expected;
			return false;
		};
		$computer = $this->getComputer( null, [ 'AbuseFilter-computeVariable' => $handler ] );
		$actual = $computer->compute(
			new LazyLoadedVariable( 'method-does-not-exist', [] ),
			new VariableHolder(),
			$this->getForbidComputeCB()
		);
		$this->assertSame( $expected, $actual );
	}
}
