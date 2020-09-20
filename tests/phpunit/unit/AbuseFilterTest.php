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

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterGeneric
 */
class AbuseFilterTest extends MediaWikiUnitTestCase {
	/**
	 * Check that version comparing works well
	 *
	 * @param stdClass $firstVersion
	 * @param array $firstActions
	 * @param stdClass $secondVersion
	 * @param array $secondActions
	 * @param array $expected The differences
	 * @covers AbuseFilter::compareVersions
	 * @dataProvider provideVersions
	 */
	public function testCompareVersions(
		stdClass $firstVersion,
		array $firstActions,
		stdClass $secondVersion,
		array $secondActions,
		array $expected
	) {
		$allActions = [
			'throttle', 'warn', 'disallow', 'blockautopromote', 'block', 'rangeblock', 'degroup', 'tag'
		];
		$differences = AbuseFilter::compareVersions(
			Filter::newFromRow( $firstVersion ),
			$firstActions,
			Filter::newFromRow( $secondVersion ),
			$secondActions,
			$allActions
		);

		$this->assertSame( $expected, $differences );
	}

	/**
	 * Data provider for testCompareVersions
	 * @return array
	 */
	public function provideVersions() {
		$baseRow = [
			'af_actions' => '',
			'af_user' => 1,
			'af_user_text' => 'Foo',
			'af_timestamp' => '20181016155634',
			'af_id' => 42
		];
		return [
			[
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'disallow' => [] ],
				(object)( [
					'af_public_comments' => 'OtherComments',
					'af_pattern' => '/*Other pattern*/',
					'af_comments' => 'Other comments',
					'af_deleted' => 1,
					'af_enabled' => 0,
					'af_hidden' => 1,
					'af_global' => 1,
					'af_group' => 'flow'
				] + $baseRow ),
				[ 'disallow' => [] ],
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
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'disallow' => [] ],
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'disallow' => [] ],
				[]
			],
			[
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'disallow' => [] ],
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'degroup' => [] ],
				[ 'actions' ]
			],
			[
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'disallow' => [] ],
				(object)( [
					'af_public_comments' => 'OtherComments',
					'af_pattern' => '/*Other pattern*/',
					'af_comments' => 'Other comments',
					'af_deleted' => 1,
					'af_enabled' => 0,
					'af_hidden' => 1,
					'af_global' => 1,
					'af_group' => 'flow'
				] + $baseRow ),
				[ 'blockautopromote' => [] ],
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
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'disallow' => [] ],
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'warn' => [ 'abusefilter-warning' ] ],
				[ 'actions' ]
			],
			[
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'warn' => [ 'abusefilter-warning' ] ],
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'disallow' => [] ],
				[ 'actions' ]
			],
			[
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'warn' => [ 'abusefilter-warning' ] ],
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[
					'warn' => [ 'abusefilter-my-best-warning' ],
					'degroup' => []
				],
				[ 'actions' ]
			],
			[
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'warn' => [ 'abusefilter-warning' ] ],
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Other Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 1,
					'af_global' => 0,
					'af_group' => 'flow'
				] + $baseRow ),
				[ 'warn' => [ 'abusefilter-my-best-warning' ] ],
				[
					'af_pattern',
					'af_hidden',
					'af_group',
					'actions'
				]
			],
			[
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'default'
				] + $baseRow ),
				[ 'warn' => [ 'abusefilter-beautiful-warning' ] ],
				(object)( [
					'af_public_comments' => 'Comments',
					'af_pattern' => '/*Pattern*/',
					'af_comments' => 'Comments',
					'af_deleted' => 0,
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_group' => 'flow'
				] + $baseRow ),
				[ 'warn' => [ 'abusefilter-my-best-warning' ] ],
				[
					'af_group',
					'actions'
				]
			],
		];
	}

	/**
	 * Check that row translating from abuse_filter_history to abuse_filter is working fine
	 *
	 * @param stdClass $row The row to translate
	 * @param array $expected The expected result
	 * @covers AbuseFilter::translateFromHistory
	 * @dataProvider provideHistoryRows
	 */
	public function testTranslateFromHistory( $row, $expected ) {
		$actual = AbuseFilter::translateFromHistory( $row );

		$actual[0] = $actual[0]->toDatabaseRow();
		unset( $actual[0]->af_throttled, $actual[0]->af_hit_count, $actual[0]->af_actions );
		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Data provider for testTranslateFromHistory
	 * @return array
	 */
	public function provideHistoryRows() {
		return [
			[
				(object)[
					'afh_filter' => 1,
					'afh_user' => 0,
					'afh_user_text' => 'FilteredUser',
					'afh_timestamp' => '20180706142932',
					'afh_pattern' => '/*Pattern*/',
					'afh_comments' => 'Comments',
					'afh_flags' => 'enabled,hidden',
					'afh_public_comments' => 'Description',
					'afh_actions' => serialize( [
						'degroup' => [],
						'disallow' => []
					] ),
					'afh_deleted' => 0,
					'afh_changed_fields' => 'actions',
					'afh_group' => 'default'
				],
				[
					(object)[
						'af_pattern' => '/*Pattern*/',
						'af_user' => 0,
						'af_user_text' => 'FilteredUser',
						'af_timestamp' => '20180706142932',
						'af_comments' => 'Comments',
						'af_public_comments' => 'Description',
						'af_deleted' => 0,
						'af_id' => 1,
						'af_group' => 'default',
						'af_hidden' => 1,
						'af_enabled' => 1,
						'af_global' => 0
					],
					[
						'degroup' => [],
						'disallow' => []
					]
				]
			],
			[
				(object)[
					'afh_filter' => 5,
					'afh_user' => 0,
					'afh_user_text' => 'FilteredUser',
					'afh_timestamp' => '20180706145516',
					'afh_pattern' => '1 === 1',
					'afh_comments' => '',
					'afh_flags' => '',
					'afh_public_comments' => 'Our best filter',
					'afh_actions' => serialize( [
						'warn' => [
							'abusefilter-warning',
							''
						],
						'disallow' => [],
					] ),
					'afh_deleted' => 0,
					'afh_changed_fields' => 'af_pattern,af_comments,af_enabled,actions',
					'afh_group' => 'flow'
				],
				[
					(object)[
						'af_pattern' => '1 === 1',
						'af_user' => 0,
						'af_user_text' => 'FilteredUser',
						'af_timestamp' => '20180706145516',
						'af_comments' => '',
						'af_public_comments' => 'Our best filter',
						'af_deleted' => 0,
						'af_id' => 5,
						'af_group' => 'flow',
						'af_hidden' => 0,
						'af_enabled' => 0,
						'af_global' => 0
					],
					[
						'warn' => [
							'abusefilter-warning',
							''
						],
						'disallow' => []
					]
				]
			],
			[
				(object)[
					'afh_filter' => 7,
					'afh_user' => 1,
					'afh_user_text' => 'AnotherUser',
					'afh_timestamp' => '20160511185604',
					'afh_pattern' => 'added_lines irlike "lol" & summary == "ggwp"',
					'afh_comments' => 'Show vandals no mercy, for you shall receive none.',
					'afh_flags' => 'enabled,hidden,global',
					'afh_public_comments' => 'Whatever',
					'afh_actions' => serialize( [
						'warn' => [
							'abusefilter-warning',
							''
						],
						'disallow' => [],
						'block' => [
							'blocktalk',
							'8 hours',
							'infinity'
						]
					] ),
					'afh_deleted' => 0,
					'afh_changed_fields' => 'af_pattern,af_comments,af_enabled,af_public_comments,actions',
					'afh_group' => 'default'
				],
				[
					(object)[
						'af_pattern' => 'added_lines irlike "lol" & summary == "ggwp"',
						'af_user' => 1,
						'af_user_text' => 'AnotherUser',
						'af_timestamp' => '20160511185604',
						'af_comments' => 'Show vandals no mercy, for you shall receive none.',
						'af_public_comments' => 'Whatever',
						'af_deleted' => 0,
						'af_id' => 7,
						'af_group' => 'default',
						'af_hidden' => 1,
						'af_enabled' => 1,
						'af_global' => 1
					],
					[
						'warn' => [
								'abusefilter-warning',
								''
						],
						'disallow' => [],
						'block' => [
							'blocktalk',
							'8 hours',
							'infinity'
						]
					]
				]
			],
			[
				(object)[
					'afh_filter' => 131,
					'afh_user' => 15,
					'afh_user_text' => 'YetAnotherUser',
					'afh_timestamp' => '20180511185604',
					'afh_pattern' => 'user_name == "Thatguy"',
					'afh_comments' => '',
					'afh_flags' => 'hidden,deleted',
					'afh_public_comments' => 'No comment.',
					'afh_actions' => serialize( [
						'throttle' => [
							'131',
							'3,60',
							'user'
						],
						'tag' => [
							'mytag',
							'yourtag'
						]
					] ),
					'afh_deleted' => 1,
					'afh_changed_fields' => 'af_pattern',
					'afh_group' => 'default'
				],
				[
					(object)[
						'af_pattern' => 'user_name == "Thatguy"',
						'af_user' => 15,
						'af_user_text' => 'YetAnotherUser',
						'af_timestamp' => '20180511185604',
						'af_comments' => '',
						'af_public_comments' => 'No comment.',
						'af_deleted' => 1,
						'af_id' => 131,
						'af_group' => 'default',
						'af_hidden' => 1,
						'af_enabled' => 0,
						'af_global' => 0
					],
					[
						'throttle' => [
							'131',
							'3,60',
							'user'
						],
						'tag' => [
							'mytag',
							'yourtag'
						]
					]
				]
			]
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
