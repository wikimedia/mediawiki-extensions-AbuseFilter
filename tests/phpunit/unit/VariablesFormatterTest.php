<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\VariablesFormatter;
use MediaWikiUnitTestCase;
use MessageLocalizer;
use Wikimedia\TestingAccessWrapper;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\VariablesFormatter
 */
class VariablesFormatterTest extends MediaWikiUnitTestCase {
	/**
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$this->assertInstanceOf(
			VariablesFormatter::class,
			new VariablesFormatter(
				$this->createMock( KeywordsManager::class ),
				$this->createMock( MessageLocalizer::class )
			)
		);
	}

	/**
	 * @covers ::setMessageLocalizer
	 */
	public function testSetMessageLocalizer() {
		$formatter = new VariablesFormatter(
			$this->createMock( KeywordsManager::class ),
			$this->createMock( MessageLocalizer::class )
		);
		$ml = $this->createMock( MessageLocalizer::class );
		$formatter->setMessageLocalizer( $ml );
		$this->assertSame( $ml, TestingAccessWrapper::newFromObject( $formatter )->messageLocalizer );
	}

	/**
	 * @param $var
	 * @param string $expected
	 * @covers ::formatVar
	 * @dataProvider provideFormatVar
	 */
	public function testFormatVar( $var, string $expected ) {
		$this->assertSame( $expected, VariablesFormatter::formatVar( $var ) );
	}

	/**
	 * Provider for testFormatVar
	 * @return array
	 */
	public function provideFormatVar() {
		return [
			'boolean' => [ true, 'true' ],
			'single-quote string' => [ 'foo', "'foo'" ],
			'string with quotes' => [ "ba'r'", "'ba'r''" ],
			'integer' => [ 42, '42' ],
			'float' => [ 0.1, '0.1' ],
			'null' => [ null, 'null' ],
			'simple list' => [ [ true, 1, 'foo' ], "[\n\t0 => true,\n\t1 => 1,\n\t2 => 'foo'\n]" ],
			'assoc array' => [ [ 'foo' => 1, 'bar' => 'bar' ], "[\n\t'foo' => 1,\n\t'bar' => 'bar'\n]" ],
			'nested array' => [
				[ 'a1' => 1, [ 'a2' => 2, [ 'a3' => 3, [ 'a4' => 4 ] ] ] ],
				"[\n\t'a1' => 1,\n\t0 => [\n\t\t'a2' => 2,\n\t\t0 => [\n\t\t\t'a3' => 3,\n\t\t\t0 => " .
				"[\n\t\t\t\t'a4' => 4\n\t\t\t]\n\t\t]\n\t]\n]"
			],
			'empty array' => [ [], '[]' ],
			'mixed array' => [
				[ 3 => true, 'foo' => false, 1, [ 1, 'foo' => 42 ] ],
				"[\n\t3 => true,\n\t'foo' => false,\n\t4 => 1,\n\t5 => [\n\t\t0 => 1,\n\t\t'foo' => 42\n\t]\n]"
			]
		];
	}
}
