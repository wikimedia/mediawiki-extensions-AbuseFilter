<?php
/**
 * Tests for validating and saving a filter
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
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Extension\AbuseFilter\Filter\Specs;
use PHPUnit\Framework\MockObject\MockObject;
use Wikimedia\Rdbms\IDatabase;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterSave
 */
class AbuseFilterSaveTest extends MediaWikiTestCase {
	private const DEFAULT_VALUES = [
		'rules' => '/**/',
		'user' => 0,
		'user_text' => 'FilterTester',
		'timestamp' => '20190826000000',
		'enabled' => 1,
		'comments' => '',
		'name' => 'Mock filter',
		'hidden' => 0,
		'hit_count' => 0,
		'throttled' => 0,
		'deleted' => 0,
		'actions' => [],
		'global' => 0,
		'group' => 'default'
	];

	/**
	 * @return HashConfig
	 */
	private function getConfig() : HashConfig {
		$cfgOpts = [
			'LanguageCode' => 'en',
			'AbuseFilterActions' => [
				'throttle' => true,
				'warn' => true,
				'disallow' => true,
				'blockautopromote' => true,
				'block' => true,
				'rangeblock' => true,
				'degroup' => true,
				'tag' => true
			],
			'AbuseFilterValidGroups' => [
				'default',
				'flow'
			],
			'AbuseFilterActionRestrictions' => [
				'degroup' => true
			],
			'AbuseFilterIsCentral' => true,
		];
		return new HashConfig( $cfgOpts );
	}

	/**
	 * @param array $testPerms
	 * @return User|MockObject
	 */
	private function getUserMock( array $testPerms ) {
		$perms = array_merge( $testPerms, [ 'abusefilter-modify' ] );
		/** @var User|MockObject $user */
		$user = $this->createMock( User::class );
		$user->method( 'getName' )->willReturn( 'FilterUser' );
		$user->method( 'getId' )->willReturn( 1 );
		$user->method( 'getActorId' )->willReturn( 1 );
		$this->overrideUserPermissions( $user, $perms );
		return $user;
	}

	/**
	 * @param array $filterSpecs
	 * @return Filter
	 */
	private function getFilterFromSpecs( array $filterSpecs ) : Filter {
		return new Filter(
			new Specs(
				$filterSpecs['rules'],
				$filterSpecs['comments'],
				$filterSpecs['name'],
				array_keys( $filterSpecs['actions'] ),
				$filterSpecs['group']
			),
			new Flags(
				$filterSpecs['enabled'],
				$filterSpecs['deleted'],
				$filterSpecs['hidden'],
				$filterSpecs['global']
			),
			$filterSpecs['actions'],
			new LastEditInfo(
				$filterSpecs['user'],
				$filterSpecs['user_text'],
				$filterSpecs['timestamp']
			),
			$filterSpecs['id'],
			$filterSpecs['hit_count'],
			$filterSpecs['throttled']
		);
	}

	/**
	 * @param array $args
	 * @return Filter[]
	 */
	private function getFilterFromTestSpecs( array $args ) : array {
		$filterSpecs = $args['filter'] + self::DEFAULT_VALUES;
		$newFilter = $this->getFilterFromSpecs( $filterSpecs );

		$existing = isset( $args['testData']['existing'] );
		if ( $existing ) {
			$origFilter = $this->getFilterFromSpecs( self::DEFAULT_VALUES + [ 'id' => 1 ] );
		} else {
			$origFilter = MutableFilter::newDefault();
		}

		return [ $newFilter, $origFilter ];
	}

	/**
	 * Validate and save a filter given its parameters
	 *
	 * @param array $args Parameters of the filter and metadata for the test
	 * @covers AbuseFilter::saveFilter
	 * @dataProvider provideFilters
	 */
	public function testSaveFilter( $args ) {
		$user = $this->getUserMock( $args['testData']['userPerms'] ?? [] );

		$filter = $args['filter']['id'] = $args['filter']['id'] ?? null;
		[ $newFilter, $origFilter ] = $this->getFilterFromTestSpecs( $args );

		/** @var IDatabase|MockObject $dbw */
		$dbw = $this->createMock( IDatabase::class );
		$dbw->method( 'insertId' )->willReturn( 1 );
		// This is needed because of the ManualLogEntry usage
		$dbw->method( 'selectRow' )->willReturn( (object)[ 'actor_id' => '1' ] );
		$status = AbuseFilter::saveFilter(
			$user, $filter, $newFilter, $origFilter,
			$dbw, $this->getConfig()
		);

		if ( $args['testData']['shouldFail'] ) {
			$this->assertFalse( $status->isGood(), 'The filter validation returned a valid status.' );
			$actual = $status->getErrors()[0]['message'];
			$expected = $args['testData']['expectedMessage'];
			$this->assertEquals( $expected, $actual );
		} elseif ( $args['testData']['shouldBeSaved'] ) {
			$this->assertTrue(
				$status->isGood(),
				"Save failed with status: $status"
			);
			$value = $status->getValue();
			$this->assertIsArray( $value );
			$this->assertCount( 2, $value );
			$this->assertContainsOnly( 'int', $value );
		} else {
			$this->assertTrue(
				$status->isGood(),
				"Got a non-good status: $status"
			);
			$this->assertFalse( $status->getValue(), 'Status value should be false' );
		}
	}

	/**
	 * Data provider for creating and editing filters.
	 * @return array
	 */
	public function provideFilters() : array {
		return [
			'Fail due to empty description and rules' => [
				[
					'filter' => [
						'rules' => '',
						'name' => '',
						'actions' => [
							'blockautopromote' => []
						]
					],
					'testData' => [
						'expectedMessage' => 'abusefilter-edit-missingfields',
						'shouldFail' => true,
						'shouldBeSaved' => false
					]
				]
			],
			'Success for only rules and description' => [
				[
					'filter' => [
						'rules' => '/* My rules */',
						'name' => 'Some new filter',
						'enabled' => false,
						'deleted' => true
					],
					'testData' => [
						'shouldFail' => false,
						'shouldBeSaved' => true
					]
				]
			],
			'Fail due to syntax error' => [
				[
					'filter' => [
						'rules' => 'rlike',
						'name' => 'This syntax aint good',
						'actions' => [
							'block' => [ true, '8 hours', '8 hours' ]
						]
					],
					'testData' => [
						'expectedMessage' => 'abusefilter-edit-badsyntax',
						'shouldFail' => true,
						'shouldBeSaved' => false
					]
				]
			],
			'Fail due to both "enabled" and "deleted" selected' => [
				[
					'filter' => [
						'rules' => '1==1',
						'names' => 'Enabled and deleted',
						'deleted' => true,
						'actions' => [
							'block' => [ true, '8 hours', '8 hours' ]
						]
					],
					'testData' => [
						'expectedMessage' => 'abusefilter-edit-deleting-enabled',
						'shouldFail' => true,
						'shouldBeSaved' => false
					]
				]
			],
			'Fail due to a reserved tag' => [
				[
					'filter' => [
						'rules' => '1==1',
						'name' => 'Reserved tag',
						'comments' => 'Some notes',
						'hidden' => true,
						'actions' => [
							'tag' => [ 'mw-undo' ]
						]
					],
					'testData' => [
						'expectedMessage' => 'abusefilter-edit-bad-tags',
						'shouldFail' => true,
						'shouldBeSaved' => false
					]
				]
			],
			'Fail due to an invalid tag' => [
				[
					'filter' => [
						'rules' => '1==1',
						'name' => 'Invalid tag',
						'comments' => 'Some notes',
						'actions' => [
							'tag' => [ 'invalid|tag' ]
						]
					],
					'testData' => [
						'expectedMessage' => 'tags-create-invalid-chars',
						'shouldFail' => true,
						'shouldBeSaved' => false
					]
				]
			],
			'Fail due to an empty tag' => [
				[
					'filter' => [
						'rules' => '1!=0',
						'name' => 'Empty tag',
						'comments' => '',
						'actions' => [
							'tag' => [ '' ]
						]
					],
					'testData' => [
						'expectedMessage' => 'tags-create-no-name',
						'shouldFail' => true,
						'shouldBeSaved' => false
					]
				]
			],
			'Fail due to lack of modify-global right' => [
				[
					'filter' => [
						'rules' => '1==1',
						'name' => 'Global without perms',
						'global' => true,
						'actions' => [
							'disallow' => [ 'abusefilter-disallowed' ]
						],
					],
					'testData' => [
						'expectedMessage' => 'abusefilter-edit-notallowed-global',
						'shouldFail' => true,
						'shouldBeSaved' => false
					]
				]
			],
			'Fail due to custom warn message on global filter' => [
				[
					'filter' => [
						'rules' => '1==1',
						'name' => 'Global with invalid warn message',
						'global' => true,
						'actions' => [
							'warn' => [ 'abusefilter-beautiful-warning' ]
						],
					],
					'testData' => [
						'expectedMessage' => 'abusefilter-edit-notallowed-global-custom-msg',
						'shouldFail' => true,
						'shouldBeSaved' => false,
						'userPerms' => [ 'abusefilter-modify-global' ]
					]
				]
			],
			'Fail due to custom disallow message on global filter' => [
				[
					'filter' => [
						'rules' => '1==1',
						'name' => 'Global with invalid disallow message',
						'global' => true,
						'actions' => [
							'disallow' => [ 'abusefilter-disallowed-something' ]
						],
					],
					'testData' => [
						'expectedMessage' => 'abusefilter-edit-notallowed-global-custom-msg',
						'shouldFail' => true,
						'shouldBeSaved' => false,
						'userPerms' => [ 'abusefilter-modify-global' ]
					]
				]
			],
			'Fail due to a restricted action' => [
				[
					'filter' => [
						'rules' => '1==1',
						'name' => 'Restricted action',
						'actions' => [
							'degroup' => []
						],
					],
					'testData' => [
						'expectedMessage' => 'abusefilter-edit-restricted',
						'shouldFail' => true,
						'shouldBeSaved' => false
					]
				]
			],
			'Pass validation but do not save when there are no changes' => [
				[
					'filter' => [
						'id' => '1',
						'rules' => '/**/',
						'name' => 'Mock filter'
					],
					'testData' => [
						'shouldFail' => false,
						'shouldBeSaved' => false,
						'existing' => true
					]
				]
			],
			'Fail due to invalid throttle groups' => [
				[
					'filter' => [
						'rules' => '1==1',
						'name' => 'Invalid throttle groups',
						'comments' => 'Throttle... Again',
						'actions' => [
							'throttle' => [ null, '11,111', "user\nfoo" ]
						],
					],
					'testData' => [
						'expectedMessage' => 'abusefilter-edit-invalid-throttlegroups',
						'shouldFail' => true,
						'shouldBeSaved' => false
					]
				]
			],
			'Fail due to empty warning message' => [
				[
					'filter' => [
						'rules' => '1==1',
						'name' => 'Empty warning message',
						'actions' => [
							'warn' => [ '' ]
						],
					],
					'testData' => [
						'expectedMessage' => 'abusefilter-edit-invalid-warn-message',
						'shouldFail' => true,
						'shouldBeSaved' => false
					]
				]
			],
			'Fail due to empty disallow message' => [
				[
					'filter' => [
						'rules' => '1==1',
						'name' => 'Empty disallow message',
						'actions' => [
							'disallow' => [ '' ]
						],
					],
					'testData' => [
						'expectedMessage' => 'abusefilter-edit-invalid-disallow-message',
						'shouldFail' => true,
						'shouldBeSaved' => false
					]
				]
			]
		];
	}
}
