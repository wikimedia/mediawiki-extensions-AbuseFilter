<?php

/**
 * Generic tests for utility functions in AbuseFilter that do NOT require DB access
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
 */

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterGeneric
 */
class AbuseFilterTest extends MediaWikiUnitTestCase {
	/**
	 * @param string $name The name of a filter
	 * @param array|null $expected If array, the expected result like [ id, isGlobal ].
	 *   If null it means that we're expecting an exception.
	 * @covers AbuseFilter::splitGlobalName
	 * @dataProvider provideGlobalNames
	 */
	public function testSplitGlobalName( $name, $expected ) {
		if ( $expected !== null ) {
			$actual = AbuseFilter::splitGlobalName( $name );
			$this->assertSame( $expected, $actual );
		} else {
			$this->expectException( InvalidArgumentException::class );
			AbuseFilter::splitGlobalName( $name );
		}
	}

	/**
	 * Data provider for testSplitGlobalName
	 *
	 * @return array
	 */
	public function provideGlobalNames() {
		return [
			[ '15', [ 15, false ] ],
			[ 15, [ 15, false ] ],
			[ 'global-1', [ 1, true ] ],
			[ 'new', null ],
			[ false, null ],
			[ 'global-15-global', null ],
			[ 0, [ 0, false ] ],
			[ 'global-', null ],
			[ 'global-lol', null ],
			[ 'global-17.2', null ],
			[ '17,2', null ],
		];
	}

	/**
	 * @param $var
	 * @param string $expected
	 * @covers AbuseFilter::formatVar
	 * @dataProvider provideFormatVar
	 */
	public function testFormatVar( $var, string $expected ) {
		$this->assertSame( $expected, AbuseFilter::formatVar( $var ) );
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
