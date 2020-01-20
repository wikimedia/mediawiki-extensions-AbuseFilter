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

use MediaWiki\Linker\LinkRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use Wikimedia\Rdbms\IDatabase;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterSave
 *
 * @covers AbuseFilter
 * @covers AbuseFilterViewEdit
 */
class AbuseFilterSaveTest extends MediaWikiTestCase {
	private static $defaultFilterRow = [
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
	 * Gets an instance of AbuseFilterViewEdit ready for creating or editing filter
	 *
	 * @param User $user
	 * @param array $params
	 * @param bool $existing Whether the filter already exists
	 * @return AbuseFilterViewEdit|MockObject
	 */
	private function getViewEdit( User $user, array $params, $existing ) {
		$special = new SpecialAbuseFilter();
		$context = new RequestContext();
		$context->setRequest( $this->getRequest( $params ) );
		$context->setUser( $user );
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

		$context->setConfig( new HashConfig( $cfgOpts ) );

		$special->setContext( $context );
		$filter = $params['id'];
		$special->mFilter = $filter;
		/** @var LinkRenderer|MockObject $lr */
		$lr = $this->getMockBuilder( LinkRenderer::class )
			->disableOriginalConstructor()
			->getMock();
		$special->setLinkRenderer( $lr );
		/** @var AbuseFilterViewEdit|MockObject $viewEdit */
		$viewEdit = $this->getMockBuilder( AbuseFilterViewEdit::class )
			->setConstructorArgs( [ $special, [ $filter ] ] )
			->setMethods( [ 'loadFilterData' ] )
			->getMock();

		if ( $existing ) {
			$origValues = [ (object)self::$defaultFilterRow, [] ];
		} else {
			$origValues = [
				(object)[
					'af_pattern' => '',
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_throttled' => 0
				],
				[]
			];
		}
		$viewEdit->expects( $this->once() )
			->method( 'loadFilterData' )
			->willReturn( $origValues );

		// Being a static property, it's not deleted between tests
		$viewEdit::$mLoadedRow = null;

		return $viewEdit;
	}

	/**
	 * Creates a FauxRequest object
	 *
	 * @param array $params
	 * @return FauxRequest
	 */
	private function getRequest( array $params ) {
		$reqParams = [
			'wpFilterRules' => $params['rules'],
			'wpFilterDescription' => $params['description'],
			'wpFilterNotes' => $params['notes'] ?? '',
			'wpFilterGroup' => $params['group'] ?? 'default',
			'wpFilterEnabled' => $params['enabled'] ?? true,
			'wpFilterHidden' => $params['hidden'] ?? false,
			'wpFilterDeleted' => $params['deleted'] ?? false,
			'wpFilterGlobal' => $params['global'] ?? false,
			'wpFilterActionThrottle' => $params['throttleEnabled'] ?? false,
			'wpFilterThrottleCount' => $params['throttleCount'] ?? 0,
			'wpFilterThrottlePeriod' => $params['throttlePeriod'] ?? 0,
			'wpFilterThrottleGroups' => $params['throttleGroups'] ?? '',
			'wpFilterActionWarn' => $params['warnEnabled'] ?? false,
			'wpFilterWarnMessage' => $params['warnMessage'] ?? 'abusefilter-warning',
			'wpFilterWarnMessageOther' => $params['warnMessageOther'] ?? '',
			'wpFilterActionDisallow' => $params['disallowEnabled'] ?? false,
			'wpFilterDisallowMessage' => $params['disallowMessage'] ?? 'abusefilter-disallowed',
			'wpFilterDisallowMessageOther' => $params['disallowMessageOther'] ?? '',
			'wpFilterActionBlockautopromote' => $params['blockautopromoteEnabled'] ?? false,
			'wpFilterActionDegroup' => $params['degroupEnabled'] ?? false,
			'wpFilterActionBlock' => $params['blockEnabled'] ?? false,
			'wpFilterBlockTalk' => $params['blockTalk'] ?? false,
			'wpBlockAnonDuration' => $params['blockAnons'] ?? 'infinity',
			'wpBlockUserDuration' => $params['blockUsers'] ?? 'infinity',
			'wpFilterActionRangeblock' => $params['rangeblockEnabled'] ?? false,
			'wpFilterActionTag' => $params['tagEnabled'] ?? false,
			'wpFilterTags' => $params['tagTags'] ?? '',
		];

		// Checkboxes aren't included at all if they aren't selected. We can remove them
		// this way (instead of iterating a hardcoded list) since they're the only false values
		$reqParams = array_filter( $reqParams, function ( $el ) {
			return $el !== false;
		} );

		return new FauxRequest( $reqParams, true );
	}

	/**
	 * @param array $testPerms
	 * @return User|MockObject
	 */
	private function getUserMock( $testPerms ) {
		$perms = array_merge( $testPerms, [ 'abusefilter-modify' ] );
		$user = $this->getMockBuilder( User::class )
			->setMethods( [ 'getBlock', 'getName', 'getId', 'getActorId' ] )
			->getMock();

		$user->expects( $this->any() )
			->method( 'getName' )
			->willReturn( 'FilterUser' );
		$user->expects( $this->any() )
			->method( 'getId' )
			->willReturn( 1 );
		$user->expects( $this->any() )
			->method( 'getActorId' )
			->willReturn( 1 );
		$this->overrideUserPermissions( $user, $perms );
		return $user;
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

		$params = $args['filterParameters'];
		$filter = $params['id'] = $params['id'] ?? 'new';
		$existing = isset( $args['testData']['existing'] );
		$viewEdit = $this->getViewEdit( $user, $params, $existing );

		list( $newRow, $actions ) = $viewEdit->loadRequest( $filter );

		/** @var IDatabase|MockObject $dbw */
		$dbw = $this->getMockForAbstractClass( IDatabase::class );
		$dbw->expects( $this->any() )
			->method( 'insertId' )
			->willReturn( 1 );
		$status = AbuseFilter::saveFilter( $viewEdit, $filter, $newRow, $actions, $dbw );

		if ( $args['testData']['shouldFail'] ) {
			$this->assertFalse( $status->isGood(), 'The filter validation returned a valid status.' );
			$actual = $status->getErrors()[0]['message'];
			$expected = $args['testData']['expectedMessage'];
			$this->assertEquals( $expected, $actual );
		} else {
			if ( $args['testData']['shouldBeSaved'] ) {
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
	}

	/**
	 * Data provider for creating and editing filters.
	 * @return array
	 */
	public function provideFilters() {
		return [
			'Fail due to empty description and rules' => [
				[
					'filterParameters' => [
						'rules' => '',
						'description' => '',
						'blockautopromoteEnabled' => true,
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
					'filterParameters' => [
						'rules' => '/* My rules */',
						'description' => 'Some new filter',
						'enabled' => false,
						'deleted' => true,
					],
					'testData' => [
						'shouldFail' => false,
						'shouldBeSaved' => true
					]
				]
			],
			'Fail due to syntax error' => [
				[
					'filterParameters' => [
						'rules' => 'rlike',
						'description' => 'This syntax aint good',
						'blockEnabled' => true,
						'blockTalk' => true,
						'blockAnons' => '8 hours',
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
					'filterParameters' => [
						'rules' => '1==1',
						'description' => 'Enabled and deleted',
						'deleted' => true,
						'blockEnabled' => true,
						'blockTalk' => true,
						'blockAnons' => '8 hours',
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
					'filterParameters' => [
						'rules' => '1==1',
						'description' => 'Reserved tag',
						'notes' => 'Some notes',
						'hidden' => true,
						'tagEnabled' => true,
						'tagTags' => 'mw-undo'
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
					'filterParameters' => [
						'rules' => '1==1',
						'description' => 'Invalid tag',
						'notes' => 'Some notes',
						'tagEnabled' => true,
						'tagTags' => 'some|tag'
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
					'filterParameters' => [
						'rules' => '1!=0',
						'description' => 'Empty tag',
						'notes' => '',
						'tagEnabled' => true,
						'tagTags' => ''
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
					'filterParameters' => [
						'rules' => '1==1',
						'description' => 'Global without perms',
						'global' => true,
						'disallowEnabled' => true,
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
					'filterParameters' => [
						'rules' => '1==1',
						'description' => 'Global with invalid warn message',
						'global' => true,
						'warnEnabled' => true,
						'warnMessage' => 'abusefilter-beautiful-warning',
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
					'filterParameters' => [
						'rules' => '1==1',
						'description' => 'Global with invalid disallow message',
						'global' => true,
						'disallowEnabled' => true,
						'disallowMessage' => 'abusefilter-disallowed-something',
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
					'filterParameters' => [
						'rules' => '1==1',
						'description' => 'Restricted action',
						'degroupEnabled' => true,
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
					'filterParameters' => [
						'id' => '1',
						'rules' => '/**/',
						'description' => 'Mock filter',
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
					'filterParameters' => [
						'rules' => '1==1',
						'description' => 'Invalid throttle groups',
						'notes' => 'Throttle... Again',
						'throttleEnabled' => true,
						'throttleCount' => 11,
						'throttlePeriod' => 111,
						'throttleGroups' => 'user\nfoo'
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
					'filterParameters' => [
						'rules' => '1==1',
						'description' => 'Empty warning message',
						'warnEnabled' => true,
						'warnMessage' => '',
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
					'filterParameters' => [
						'rules' => '1==1',
						'description' => 'Empty disallow message',
						'disallowEnabled' => true,
						'disallowMessage' => '',
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
