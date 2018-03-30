<?php
/**
 * Tests for the AbuseFilter parser
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 *
 * @license GPL-2.0-or-later
 * @author Marius Hoch < hoo@online.de >
 */

/**
 * @group Test
 * @group AbuseFilter
 *
 * @covers AbuseFilterCachingParser
 * @covers AbuseFilterParser
 * @covers AbuseFilterTokenizer
 */
class AbuseFilterParserTest extends MediaWikiTestCase {
	/**
	 * @return AbuseFilterParser
	 */
	static function getParser() {
		static $parser = null;
		if ( !$parser ) {
			$parser = new AbuseFilterParser();
		}
		return $parser;
	}

	/**
	 * @return AbuseFilterParser[]
	 */
	static function getParsers() {
		static $parsers = null;
		if ( !$parsers ) {
			$parsers = [
				new AbuseFilterParser(),
				new AbuseFilterCachingParser()
			];
		}
		return $parsers;
	}

	/**
	 * @dataProvider readTests
	 */
	public function testParser( $testName, $rule, $expected ) {
		foreach ( self::getParsers() as $parser ) {
			$actual = $parser->parse( $rule );
			$this->assertEquals( $expected, $actual, 'Running parser test ' . $testName );
		}
	}

	/**
	 * @return array
	 */
	public function readTests() {
		$tests = [];
		$testPath = __DIR__ . "/../parserTests";
		$testFiles = glob( $testPath . "/*.t" );

		foreach ( $testFiles as $testFile ) {
			$testName = substr( $testFile, 0, -2 );

			$resultFile = $testName . '.r';
			$rule = trim( file_get_contents( $testFile ) );
			$result = trim( file_get_contents( $resultFile ) ) == 'MATCH';

			$tests[] = [
				basename( $testName ),
				$rule,
				$result
			];
		}

		return $tests;
	}

	/**
	 * Ensure that AbuseFilterTokenizer::OPERATOR_RE matches the contents
	 * and order of AbuseFilterTokenizer::$operators.
	 */
	public function testOperatorRe() {
		$operatorRe = '/(' . implode( '|', array_map( function ( $op ) {
			return preg_quote( $op, '/' );
		}, AbuseFilterTokenizer::$operators ) ) . ')/A';
		$this->assertEquals( $operatorRe, AbuseFilterTokenizer::OPERATOR_RE );
	}

	/**
	 * Ensure that AbuseFilterTokenizer::RADIX_RE matches the contents
	 * and order of AbuseFilterTokenizer::$bases.
	 */
	public function testRadixRe() {
		$baseClass = implode( '', array_keys( AbuseFilterTokenizer::$bases ) );
		$radixRe = "/([0-9A-Fa-f]+(?:\.\d*)?|\.\d+)([$baseClass])?/Au";
		$this->assertEquals( $radixRe, AbuseFilterTokenizer::RADIX_RE );
	}

	/**
	 * Ensure the number of conditions counted for given expressions is right.
	 *
	 * @dataProvider condCountCases
	 */
	public function testCondCount( $rule, $expected ) {
		$parser = self::getParser();
		// Set some variables for convenience writing test cases
		$parser->setVars( array_combine( range( 'a', 'f' ), range( 'a', 'f' ) ) );
		$countBefore = AbuseFilter::$condCount;
		$parser->parse( $rule );
		$countAfter = AbuseFilter::$condCount;
		$actual = $countAfter - $countBefore;
		$this->assertEquals( $expected, $actual, 'Condition count for ' . $rule );
	}

	/**
	 * Data provider for testCondCount method.
	 * @return array
	 */
	public function condCountCases() {
		return [
			[ '(((a == b)))', 1 ],
			[ 'contains_any(a, b, c)', 1 ],
			[ 'a == b == c', 2 ],
			[ 'a in b + c in d + e in f', 3 ],
			[ 'true', 0 ],
			[ 'a == a | c == d', 1 ],
			[ 'a == b & c == d', 1 ],
		];
	}

	/**
	 * get_matches should throw an exception with an invalid number of arguments.
	 * @expectedException AFPUserVisibleException
	 * @covers AbuseFilterParser::funcGetMatches
	 */
	public function testGetMatchesInvalidArgs() {
		$parser = self::getParser();
		$parser->parse( "get_matches('')" );
	}

	/**
	 * get_matches should throw an exception when given an invalid regular expression.
	 * @expectedException AFPUserVisibleException
	 * @covers AbuseFilterParser::funcGetMatches
	 */
	public function testGetMatchesInvalidRegex() {
		$parser = self::getParser();
		$parser->parse( "get_matches('this (should fail')" );
	}

	/**
	 * Ensure get_matches function captures returns expected output.
	 * @param string $needle Regex to pass to get_matches.
	 * @param string $haystack String to run regex against.
	 * @param string[] $expected The expected values of the matched groups.
	 * @covers AbuseFilterParser::funcGetMatches
	 * @dataProvider getMatchesCases
	 */
	public function testGetMatches( $needle, $haystack, $expected ) {
		$parser = self::getParser();
		$afpData = $parser->intEval( "get_matches('$needle', '$haystack')" )->data;

		// Extract matches from AFPData.
		$matches = array_map( function ( $afpDatum ) {
			return $afpDatum->data;
		}, $afpData );

		$this->assertEquals( $expected, $matches );
	}

	/**
	 * Data provider for get_matches method.
	 * @return array
	 */
	public function getMatchesCases() {
		return [
			[
				'You say (.*) \(and I say (.*)\)\.',
				'You say hello (and I say goodbye).',
				[
					'You say hello (and I say goodbye).',
					'hello',
					'goodbye',
				],
			],
			[
				'I(?: am)? the ((walrus|egg man).*)\!',
				'I am the egg man, I am the walrus !',
				[
					'I am the egg man, I am the walrus !',
					'egg man, I am the walrus ',
					'egg man',
				],
			],
			[
				'this (does) not match',
				'foo bar',
				[
					false,
					false,
				],
			],
		];
	}
}
