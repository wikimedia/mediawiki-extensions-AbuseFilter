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

use PHPUnit\Framework\MockObject\MockObject;
use Wikimedia\Rdbms\IDatabase;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterSave
 */
class AbuseFilterSaveTest extends MediaWikiTestCase {
	private const DEFAULT_ABUSE_FILTER_ROW = [
		'af_pattern' => '/**/',
		'af_user' => 0,
		'af_user_text' => 'FilterTester',
		'af_timestamp' => '20190826000000',
		'af_enabled' => 1,
		'af_comments' => '',
		'af_public_comments' => 'Mock filter',
		'af_hidden' => 0,
		'af_hit_count' => 0,
		'af_throttled' => 0,
		'af_deleted' => 0,
		'af_actions' => '',
		'af_global' => 0,
		'af_group' => 'default'
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
			'AbuseFilterRestrictions' => [
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
	 * @param array $args
	 * @return array
	 */
	private function getRowAndActionsFromTestSpecs( array $args ) : array {
		$newRow = (object)( $args['row'] + self::DEFAULT_ABUSE_FILTER_ROW );
		$actions = $args['actions'] ?? [];

		$existing = isset( $args['testData']['existing'] );
		if ( $existing ) {
			$origRow = (object)( self::DEFAULT_ABUSE_FILTER_ROW + [ 'af_id' => 1 ] );
		} else {
			$origRow = (object)[
				'af_pattern' => '',
				'af_enabled' => 1,
				'af_hidden' => 0,
				'af_global' => 0,
				'af_throttled' => 0,
				'af_hit_count' => 0,
			];
		}

		return [ $newRow, $actions, $origRow, [] ];
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

		$filter = $args['row']['af_id'] = $args['row']['af_id'] ?? null;
		[ $newRow, $actions, $origRow, $origActions ] = $this->getRowAndActionsFromTestSpecs( $args );

		/** @var IDatabase|MockObject $dbw */
		$dbw = $this->createMock( IDatabase::class );
		$dbw->method( 'insertId' )->willReturn( 1 );
		// This is needed because of the ManualLogEntry usage
		$dbw->method( 'selectRow' )->willReturn( (object)[ 'actor_id' => '1' ] );
		$status = AbuseFilter::saveFilter(
			$user, $filter, $newRow, $actions, $origRow,
			$origActions, $dbw, $this->getConfig()
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
					'row' => [
						'af_pattern' => '',
						'af_public_comments' => '',
					],
					'actions' => [
						'blockautopromote' => []
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
					'row' => [
						'af_pattern' => '/* My rules */',
						'af_public_comments' => 'Some new filter',
						'af_enabled' => false,
						'af_deleted' => true
					],
					'testData' => [
						'shouldFail' => false,
						'shouldBeSaved' => true
					]
				]
			],
			'Fail due to syntax error' => [
				[
					'row' => [
						'af_pattern' => 'rlike',
						'af_public_comments' => 'This syntax aint good',
					],
					'actions' => [
						'block' => [ true, '8 hours', '8 hours' ]
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
					'row' => [
						'af_pattern' => '1==1',
						'af_public_comments' => 'Enabled and deleted',
						'af_deleted' => true
					],
					'actions' => [
						'block' => [ true, '8 hours', '8 hours' ]
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
					'row' => [
						'af_pattern' => '1==1',
						'af_public_comments' => 'Reserved tag',
						'af_comments' => 'Some notes',
						'af_hidden' => true
					],
					'actions' => [
						'tag' => [ 'mw-undo' ]
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
					'row' => [
						'af_pattern' => '1==1',
						'af_public_comments' => 'Invalid tag',
						'af_comments' => 'Some notes',
					],
					'actions' => [
						'tag' => [ 'invalid|tag' ]
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
					'row' => [
						'af_pattern' => '1!=0',
						'af_public_comments' => 'Empty tag',
						'af_comments' => '',
					],
					'actions' => [
						'tag' => [ '' ]
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
					'row' => [
						'af_pattern' => '1==1',
						'af_public_comments' => 'Global without perms',
						'af_global' => true,
					],
					'actions' => [
						'disallow' => [ 'abusefilter-disallowed' ]
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
					'row' => [
						'af_pattern' => '1==1',
						'af_public_comments' => 'Global with invalid warn message',
						'af_global' => true,
					],
					'actions' => [
						'warn' => [ 'abusefilter-beautiful-warning' ]
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
					'row' => [
						'af_pattern' => '1==1',
						'af_public_comments' => 'Global with invalid disallow message',
						'af_global' => true,
					],
					'actions' => [
						'disallow' => [ 'abusefilter-disallowed-something' ]
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
					'row' => [
						'af_pattern' => '1==1',
						'af_public_comments' => 'Restricted action',
					],
					'actions' => [
						'degroup' => []
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
					'row' => [
						'af_id' => '1',
						'af_pattern' => '/**/',
						'af_public_comments' => 'Mock filter'
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
					'row' => [
						'af_pattern' => '1==1',
						'af_public_comments' => 'Invalid throttle groups',
						'af_comments' => 'Throttle... Again',
					],
					'actions' => [
						'throttle' => [ null, '11,111', "user\nfoo" ]
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
					'row' => [
						'af_pattern' => '1==1',
						'af_public_comments' => 'Empty warning message',
					],
					'actions' => [
						'warn' => [ '' ]
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
					'row' => [
						'af_pattern' => '1==1',
						'af_public_comments' => 'Empty disallow message',
					],
					'actions' => [
						'disallow' => [ '' ]
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
