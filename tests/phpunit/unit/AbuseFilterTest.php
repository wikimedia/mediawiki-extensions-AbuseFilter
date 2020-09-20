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

use MediaWiki\Extension\AbuseFilter\Filter\Filter;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Filter\LastEditInfo;
use MediaWiki\Extension\AbuseFilter\Filter\Specs;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterGeneric
 */
class AbuseFilterTest extends MediaWikiUnitTestCase {
	/**
	 * @param Filter $firstVersion
	 * @param Filter $secondVersion
	 * @param array $expected The differences
	 * @covers AbuseFilter::compareVersions
	 * @dataProvider provideVersions
	 */
	public function testCompareVersions(
		Filter $firstVersion,
		Filter $secondVersion,
		array $expected
	) {
		$allActions = [
			'throttle', 'warn', 'disallow', 'blockautopromote', 'block', 'rangeblock', 'degroup', 'tag'
		];

		$this->assertSame( $expected, AbuseFilter::compareVersions( $firstVersion, $secondVersion, $allActions ) );
	}

	/**
	 * Data provider for testCompareVersions
	 * @return array
	 */
	public function provideVersions() {
		$baseSpecs = [
			'actions' => [],
			'user' => 1,
			'user_text' => 'Foo',
			'timestamp' => '20181016155634',
			'id' => 42
		];
		$makeFilter = function ( $specs ) use ( $baseSpecs ) {
			$specs += $baseSpecs;
			return new Filter(
				new Specs(
					$specs['rules'],
					$specs['comments'],
					$specs['name'],
					array_keys( $specs['actions'] ),
					$specs['group']
				),
				new Flags(
					$specs['enabled'],
					$specs['deleted'],
					$specs['hidden'],
					$specs['global']
				),
				$specs['actions'],
				new LastEditInfo(
					$specs['user'],
					$specs['user_text'],
					$specs['timestamp']
				),
				$specs['id']
			);
		};

		return [
			[
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'disallow' => [] ]
				] ),
				$makeFilter( [
					'name' => 'OtherComments',
					'rules' => '/*Other pattern*/',
					'comments' => 'Other comments',
					'deleted' => 1,
					'enabled' => 0,
					'hidden' => 1,
					'global' => 1,
					'group' => 'flow',
					'actions' => [ 'disallow' => [] ]
				] ),
				[
					'af_public_comments',
					'af_pattern',
					'af_comments',
					'af_deleted',
					'af_enabled',
					'af_hidden',
					'af_global',
					'af_group',
				]
			],
			[
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'disallow' => [] ]
				] ),
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'disallow' => [] ]
				] ),
				[]
			],
			[
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'disallow' => [] ]
				] ),
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'degroup' => [] ]
				] ),
				[ 'actions' ]
			],
			[
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'disallow' => [] ]
				] ),
				$makeFilter( [
					'name' => 'OtherComments',
					'rules' => '/*Other pattern*/',
					'comments' => 'Other comments',
					'deleted' => 1,
					'enabled' => 0,
					'hidden' => 1,
					'global' => 1,
					'group' => 'flow',
					'actions' => [ 'blockautopromote' => [] ]
				] ),
				[
					'af_public_comments',
					'af_pattern',
					'af_comments',
					'af_deleted',
					'af_enabled',
					'af_hidden',
					'af_global',
					'af_group',
					'actions'
				]
			],
			[
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'disallow' => [] ]
				] ),
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'warn' => [ 'abusefilter-warning' ] ]
				] ),
				[ 'actions' ]
			],
			[
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'warn' => [ 'abusefilter-warning' ] ]
				] ),
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'disallow' => [] ]
				] ),
				[ 'actions' ]
			],
			[
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'warn' => [ 'abusefilter-warning' ] ]
				] ),
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [
						'warn' => [ 'abusefilter-my-best-warning' ],
						'degroup' => []
					]
				] ),
				[ 'actions' ]
			],
			[
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'warn' => [ 'abusefilter-warning' ] ]
				] ),
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Other Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 1,
					'global' => 0,
					'group' => 'flow',
					'actions' => [ 'warn' => [ 'abusefilter-my-best-warning' ] ]
				] ),
				[
					'af_pattern',
					'af_hidden',
					'af_group',
					'actions'
				]
			],
			[
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'default',
					'actions' => [ 'warn' => [ 'abusefilter-beautiful-warning' ] ]
				] ),
				$makeFilter( [
					'name' => 'Comments',
					'rules' => '/*Pattern*/',
					'comments' => 'Comments',
					'deleted' => 0,
					'enabled' => 1,
					'hidden' => 0,
					'global' => 0,
					'group' => 'flow',
					'actions' => [ 'warn' => [ 'abusefilter-my-best-warning' ] ]
				] ),
				[
					'af_group',
					'actions'
				]
			],
		];
	}

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
	 * Check that throttle parameters validation works fine
	 *
	 * @param array $params Throttle parameters
	 * @param string|null $error The expected error message. Null if validations should pass
	 * @covers AbuseFilter::checkThrottleParameters
	 * @dataProvider provideThrottleParameters
	 */
	public function testCheckThrottleParameters( $params, $error ) {
		$result = AbuseFilter::checkThrottleParameters( $params );
		$this->assertSame( $error, $result, 'Throttle parameter validation does not work as expected.' );
	}

	/**
	 * Data provider for testCheckThrottleParameters
	 * @return array
	 */
	public function provideThrottleParameters() {
		return [
			[ [ '1', '5,23', 'user', 'ip', 'page,range', 'ip,user', 'range,ip' ], null ],
			[ [ '1', '5.3,23', 'user', 'ip' ], 'abusefilter-edit-invalid-throttlecount' ],
			[ [ '1', '-3,23', 'user', 'ip' ], 'abusefilter-edit-invalid-throttlecount' ],
			[ [ '1', '5,2.3', 'user', 'ip' ], 'abusefilter-edit-invalid-throttleperiod' ],
			[ [ '1', '4,-14', 'user', 'ip' ], 'abusefilter-edit-invalid-throttleperiod' ],
			[ [ '1', '3,33,44', 'user', 'ip' ], 'abusefilter-edit-invalid-throttleperiod' ],
			[ [ '1', '3,33' ], 'abusefilter-edit-empty-throttlegroups' ],
			[ [ '1', '3,33', 'user', 'ip,foo,user' ], 'abusefilter-edit-invalid-throttlegroups' ],
			[ [ '1', '3,33', 'foo', 'ip,user' ], 'abusefilter-edit-invalid-throttlegroups' ],
			[ [ '1', '3,33', 'foo', 'ip,user,bar' ], 'abusefilter-edit-invalid-throttlegroups' ],
			[ [ '1', '3,33', 'user', 'ip,page,user' ], null ],
			[
				[ '1', '3,33', 'ip', 'user','user,ip', 'ip,user', 'user,ip,user', 'user', 'ip,ip,user' ],
				'abusefilter-edit-duplicated-throttlegroups'
			],
			[ [ '1', '3,33', 'ip,ip,user' ], 'abusefilter-edit-duplicated-throttlegroups' ],
			[ [ '1', '3,33', 'user,ip', 'ip,user' ], 'abusefilter-edit-duplicated-throttlegroups' ],
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
