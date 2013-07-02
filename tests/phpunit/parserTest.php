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
 * @group Test
 * @group AbuseFilter
 *
 * @licence GNU GPL v2+
 * @author Marius Hoch < hoo@online.de >
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
	 * @dataProvider readTests
	 */
	public function testParser( $testName, $rule, $expected ) {
		if ( !class_exists( 'AntiSpoof' ) && preg_match( '/(cc)?norm\(/i', $rule ) ) {
			// The norm and ccnorm parser functions aren't working correctly without AntiSpoof
			$this->markTestSkipped( 'Parser test ' . $testName . ' requires the AntiSpoof extension' );
		}

		$parser = self::getParser();
		$actual = $parser->parse( $rule );
		$this->assertEquals( $expected, $actual, 'Running parser test ' . $testName );
	}

	/**
	 * @return array
	 */
	public function readTests() {
		$tests = array();
		$testPath = __DIR__ . "/../parserTests";
		$testFiles = glob( $testPath . "/*.t" );

		foreach ( $testFiles as $testFile ) {
			$testName = substr( $testFile, 0, -2 );

			$resultFile = $testName . '.r';
			$rule = trim( file_get_contents( $testFile ) );
			$result = trim( file_get_contents( $resultFile ) ) == 'MATCH';

			$tests[] = array(
				basename( $testName ),
				$rule,
				$result
			);
		}

		return $tests;
	}
}
