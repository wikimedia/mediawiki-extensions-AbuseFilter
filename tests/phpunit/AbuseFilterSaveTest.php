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

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterSave
 * @group Database
 *
 * @covers AbuseFilter
 * @covers AbuseFilterViewEdit
 * @covers AbuseFilterParser
 */
class AbuseFilterSaveTest extends MediaWikiTestCase {
	protected static $mUser, $mParameters;

	/**
	 * @var array This tables will be deleted in parent::tearDown
	 */
	protected $tablesUsed = [
		'abuse_filter',
		'abuse_filter_action',
		'abuse_filter_history',
		'abuse_filter_log'
	];

	/**
	 * @see MediaWikiTestCase::setUp
	 */
	protected function setUp() {
		parent::setUp();
		$user = User::newFromName( 'FilterTester' );
		$user->addToDatabase();
		$user->addGroup( 'filterEditor' );
		RequestContext::getMain()->setUser( $user );
		self::$mUser = $user;
		// Make sure that the config we're using is the one we're expecting
		$this->setMwGlobals( [
			'wgUser' => $user,
			'wgAbuseFilterRestrictions' => [
				'degroup' => true
			],
			'wgAbuseFilterIsCentral' => true,
			'wgAbuseFilterActions' => [
				'throttle' => true,
				'warn' => true,
				'disallow' => true,
				'blockautopromote' => true,
				'block' => true,
				'rangeblock' => true,
				'degroup' => true,
				'tag' => true
			],
			'wgAbuseFilterValidGroups' => [
				'default',
				'flow'
			]
		] );
		$this->setGroupPermissions( [
			'filterEditor' => [
				'abusefilter-modify' => true,
				'abusefilter-modify-restricted' => false,
				'abusefilter-modify-global' => false,
			],
			'filterEditorGlobal' => [
				'abusefilter-modify' => true,
				'abusefilter-modify-global' => true,
			]
		] );
	}

	/**
	 * Gets an instance of AbuseFilterViewEdit ready for creating or editing filter
	 *
	 * @param string $filter 'new' for a new filter, its ID otherwise
	 * @return AbuseFilterViewEdit
	 */
	private static function getViewEdit( $filter ) {
		$special = new SpecialAbuseFilter();
		$context = RequestContext::getMain( self::getRequest() );
		$context->setRequest( self::getRequest() );

		$special->setContext( $context );
		$special->mFilter = $filter;
		$viewEdit = new AbuseFilterViewEdit( $special, [ $filter ] );
		// Being a static property, it's not deleted between tests
		$viewEdit::$mLoadedRow = null;

		return $viewEdit;
	}

	/**
	 * Creates a FauxRequest object
	 *
	 * @return FauxRequest
	 */
	private static function getRequest() {
		$params = [
			'wpFilterRules' => self::$mParameters['rules'],
			'wpFilterDescription' => self::$mParameters['description'],
			'wpFilterNotes' => self::$mParameters['notes'],
			'wpFilterGroup' => self::$mParameters['group'],
			'wpFilterEnabled' => self::$mParameters['enabled'],
			'wpFilterHidden' => self::$mParameters['hidden'],
			'wpFilterDeleted' => self::$mParameters['deleted'],
			'wpFilterGlobal' => self::$mParameters['global'],
			'wpFilterActionThrottle' => self::$mParameters['throttleEnabled'],
			'wpFilterThrottleCount' => self::$mParameters['throttleCount'],
			'wpFilterThrottlePeriod' => self::$mParameters['throttlePeriod'],
			'wpFilterThrottleGroups' => self::$mParameters['throttleGroups'],
			'wpFilterActionWarn' => self::$mParameters['warnEnabled'],
			'wpFilterWarnMessage' => self::$mParameters['warnMessage'],
			'wpFilterWarnMessageOther' => self::$mParameters['warnMessageOther'],
			'wpFilterActionDisallow' => self::$mParameters['disallowEnabled'],
			'wpFilterActionBlockautopromote' => self::$mParameters['blockautopromoteEnabled'],
			'wpFilterActionDegroup' => self::$mParameters['degroupEnabled'],
			'wpFilterActionBlock' => self::$mParameters['blockEnabled'],
			'wpFilterBlockTalk' => self::$mParameters['blockTalk'],
			'wpBlockAnonDuration' => self::$mParameters['blockAnons'],
			'wpBlockUserDuration' => self::$mParameters['blockUsers'],
			'wpFilterActionRangeblock' => self::$mParameters['rangeblockEnabled'],
			'wpFilterActionTag' => self::$mParameters['tagEnabled'],
			'wpFilterTags' => self::$mParameters['tagTags'],
		];

		// Checkboxes aren't included at all if they aren't selected. We can remove them
		// this way (instead of iterating a hardcoded list) since they're the only false values
		$params = array_filter( $params, function ( $el ) {
			return $el !== false;
		} );

		$request = new FauxRequest( $params, true );
		return $request;
	}

	/**
	 * Creates $amount new filters, in case we need to test updating an existing one
	 *
	 * @param int $amount How many filters to create
	 */
	private static function createNewFilters( $amount ) {
		$defaultRow = [
			'af_pattern' => '/**/',
			'af_user' => 0,
			'af_user_text' => 'FilterTester',
			'af_timestamp' => wfTimestampNow(),
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

		$dbw = wfGetDB( DB_MASTER );
		for ( $i = 1; $i <= $amount; $i++ ) {
			$dbw->replace(
				'abuse_filter',
				[ 'af_id' ],
				$defaultRow,
				__METHOD__
			);
		}
	}

	/**
	 * Validate and save a filter given its parameters
	 *
	 * @param array $args Parameters of the filter and metadata for the test
	 * @covers AbuseFilter::saveFilter
	 * @dataProvider provideFilters
	 */
	public function testSaveFilter( $args ) {
		// Preliminar stuff for the test
		if ( $args['testData']['customUserGroup'] ) {
			self::$mUser->addGroup( $args['testData']['customUserGroup'] );
		}

		if ( $args['testData']['needsOtherFilters'] ) {
			self::createNewFilters( $args['testData']['needsOtherFilters'] );
		}

		// Extract parameters from testset and build what we need to save a filter
		self::$mParameters = $args['filterParameters'];
		$filter = $args['filterParameters']['id'];
		$viewEdit = self::getViewEdit( $filter );
		$request = self::getRequest();
		list( $newRow, $actions ) = $viewEdit->loadRequest( $filter );
		self::$mParameters['rowActions'] = implode( ',', array_keys( array_filter( $actions ) ) );

		// Send data for validation and saving
		$status = AbuseFilter::saveFilter( $viewEdit, $filter, $request, $newRow, $actions );

		// Must be removed for the next test
		if ( $args['testData']['customUserGroup'] ) {
			self::$mUser->removeGroup( $args['testData']['customUserGroup'] );
		}

		$shouldFail = $args['testData']['shouldFail'];
		$shouldBeSaved = $args['testData']['shouldBeSaved'];
		$furtherInfo = null;
		$expected = true;
		if ( $shouldFail ) {
			if ( $status->isGood() ) {
				$furtherInfo = 'The filter validation returned a valid status.';
				$result = false;
			} else {
				$result = $status->getErrors();
				$result = $result[0]['message'];
				$expected = $args['testData']['expectedMessage'];
			}
		} else {
			if ( $shouldBeSaved ) {
				$value = $status->getValue();
				$result = $status->isGood() && is_array( $value ) && count( $value ) === 2 &&
					is_numeric( $value[0] ) && is_numeric( $value[1] );
			} else {
				$result = $status->isGood() && $status->getValue() === false;
			}
		}

		$errorMessage = $args['testData']['doingWhat'] . '. Expected: ' .
			$args['testData']['expectedResult'] . '.';
		if ( $furtherInfo ) {
			$errorMessage .= "\nFurther info: " . $furtherInfo;
		}
		$this->assertEquals(
			$expected,
			$result,
			$errorMessage
		);
	}

	/**
	 * Data provider for creating and editing filters.
	 * @return array
	 */
	public function provideFilters() {
		return [
			[
				[
					'filterParameters' => [
						'id' => 'new',
						'rules' => '',
						'description' => '',
						'notes' => '',
						'group' => 'default',
						'enabled' => true,
						'hidden' => false,
						'global' => false,
						'deleted' => false,
						'throttled' => 0,
						'throttleEnabled' => false,
						'throttleCount' => 0,
						'throttlePeriod' => 0,
						'throttleGroups' => '',
						'warnEnabled' => false,
						'warnMessage' => 'abusefilter-warning',
						'warnMessageOther' => '',
						'disallowEnabled' => false,
						'blockautopromoteEnabled' => true,
						'degroupEnabled' => false,
						'blockEnabled' => false,
						'blockTalk' => false,
						'blockAnons' => 'infinity',
						'blockUsers' => 'infinity',
						'rangeblockEnabled' => false,
						'tagEnabled' => false,
						'tagTags' => ''
					],
					'testData' => [
						'doingWhat' => 'Trying to save a filter without description and rules',
						'expectedResult' => 'a "missing required fields" error message',
						'expectedMessage' => 'abusefilter-edit-missingfields',
						'shouldFail' => true,
						'shouldBeSaved' => false,
						'customUserGroup' => '',
						'needsOtherFilters' => false
					]
				]
			],
			[
				[
					'filterParameters' => [
						'id' => 'new',
						'rules' => '/* My rules */',
						'description' => 'Some new filter',
						'notes' => '',
						'group' => 'default',
						'enabled' => false,
						'hidden' => false,
						'global' => false,
						'deleted' => true,
						'throttled' => 0,
						'throttleEnabled' => false,
						'throttleCount' => 0,
						'throttlePeriod' => 0,
						'throttleGroups' => '',
						'warnEnabled' => false,
						'warnMessage' => 'abusefilter-warning',
						'warnMessageOther' => '',
						'disallowEnabled' => false,
						'blockautopromoteEnabled' => false,
						'degroupEnabled' => false,
						'blockEnabled' => false,
						'blockTalk' => false,
						'blockAnons' => 'infinity',
						'blockUsers' => 'infinity',
						'rangeblockEnabled' => false,
						'tagEnabled' => false,
						'tagTags' => ''
					],
					'testData' => [
						'doingWhat' => 'Trying to save a filter with only rules and description',
						'expectedResult' => 'the saving to be successful',
						'expectedMessage' => '',
						'shouldFail' => false,
						'shouldBeSaved' => true,
						'customUserGroup' => '',
						'needsOtherFilters' => false
					]
				]
			],
			[
				[
					'filterParameters' => [
						'id' => 'new',
						'rules' => 'rlike',
						'description' => 'This syntax aint good',
						'notes' => '',
						'group' => 'default',
						'enabled' => true,
						'hidden' => false,
						'global' => false,
						'deleted' => false,
						'throttled' => 0,
						'throttleEnabled' => false,
						'throttleCount' => 0,
						'throttlePeriod' => 0,
						'throttleGroups' => '',
						'warnEnabled' => false,
						'warnMessage' => 'abusefilter-warning',
						'warnMessageOther' => '',
						'disallowEnabled' => false,
						'blockautopromoteEnabled' => false,
						'degroupEnabled' => false,
						'blockEnabled' => true,
						'blockTalk' => true,
						'blockAnons' => '8 hours',
						'blockUsers' => 'infinity',
						'rangeblockEnabled' => false,
						'tagEnabled' => false,
						'tagTags' => ''
					],
					'testData' => [
						'doingWhat' => 'Trying to save a filter with wrong syntax',
						'expectedResult' => 'a "wrong syntax" error message',
						'expectedMessage' => 'abusefilter-edit-badsyntax',
						'shouldFail' => true,
						'shouldBeSaved' => false,
						'customUserGroup' => '',
						'needsOtherFilters' => false
					]
				]
			],
			[
				[
					'filterParameters' => [
						'id' => 'new',
						'rules' => '1==1',
						'description' => 'Enabled and deleted',
						'notes' => '',
						'group' => 'default',
						'enabled' => true,
						'hidden' => false,
						'global' => false,
						'deleted' => true,
						'throttled' => 0,
						'throttleEnabled' => false,
						'throttleCount' => 0,
						'throttlePeriod' => 0,
						'throttleGroups' => '',
						'warnEnabled' => false,
						'warnMessage' => 'abusefilter-warning',
						'warnMessageOther' => '',
						'disallowEnabled' => false,
						'blockautopromoteEnabled' => false,
						'degroupEnabled' => false,
						'blockEnabled' => true,
						'blockTalk' => true,
						'blockAnons' => '8 hours',
						'blockUsers' => 'infinity',
						'rangeblockEnabled' => false,
						'tagEnabled' => false,
						'tagTags' => ''
					],
					'testData' => [
						'doingWhat' => 'Trying to save a filter marking it both enabled and deleted',
						'expectedResult' => 'an error message',
						'expectedMessage' => 'abusefilter-edit-deleting-enabled',
						'shouldFail' => true,
						'shouldBeSaved' => false,
						'customUserGroup' => '',
						'needsOtherFilters' => false
					]
				]
			],
			[
				[
					'filterParameters' => [
						'id' => 'new',
						'rules' => '1==1',
						'description' => 'Reserved tag',
						'notes' => 'Some notes',
						'group' => 'default',
						'enabled' => true,
						'hidden' => true,
						'global' => false,
						'deleted' => false,
						'throttled' => 0,
						'throttleEnabled' => false,
						'throttleCount' => 0,
						'throttlePeriod' => 0,
						'throttleGroups' => '',
						'warnEnabled' => false,
						'warnMessage' => 'abusefilter-warning',
						'warnMessageOther' => '',
						'disallowEnabled' => false,
						'blockautopromoteEnabled' => false,
						'degroupEnabled' => false,
						'blockEnabled' => false,
						'blockTalk' => false,
						'blockAnons' => 'infinity',
						'blockUsers' => 'infinity',
						'rangeblockEnabled' => false,
						'tagEnabled' => true,
						'tagTags' => 'mw-undo'
					],
					'testData' => [
						'doingWhat' => 'Trying to save a filter with a reserved tag',
						'expectedResult' => 'an error message saying that the tag cannot be used',
						'expectedMessage' => 'abusefilter-edit-bad-tags',
						'shouldFail' => true,
						'shouldBeSaved' => false,
						'customUserGroup' => '',
						'needsOtherFilters' => false
					]
				]
			],
			[
				[
					'filterParameters' => [
						'id' => 'new',
						'rules' => '1==1',
						'description' => 'Invalid tag',
						'notes' => 'Some notes',
						'group' => 'default',
						'enabled' => true,
						'hidden' => false,
						'global' => false,
						'deleted' => false,
						'throttled' => 0,
						'throttleEnabled' => false,
						'throttleCount' => 0,
						'throttlePeriod' => 0,
						'throttleGroups' => '',
						'warnEnabled' => false,
						'warnMessage' => 'abusefilter-warning',
						'warnMessageOther' => '',
						'disallowEnabled' => false,
						'blockautopromoteEnabled' => false,
						'degroupEnabled' => false,
						'blockEnabled' => false,
						'blockTalk' => false,
						'blockAnons' => 'infinity',
						'blockUsers' => 'infinity',
						'rangeblockEnabled' => false,
						'tagEnabled' => true,
						'tagTags' => 'some|tag'
					],
					'testData' => [
						'doingWhat' => 'Trying to save a filter with an invalid tag',
						'expectedResult' => 'an error message saying that the tag cannot be used',
						'expectedMessage' => 'tags-create-invalid-chars',
						'shouldFail' => true,
						'shouldBeSaved' => false,
						'customUserGroup' => '',
						'needsOtherFilters' => false
					]
				]
			],
			[
				[
					'filterParameters' => [
						'id' => 'new',
						'rules' => '1==1',
						'description' => 'Global without perms',
						'notes' => '',
						'group' => 'default',
						'enabled' => true,
						'hidden' => false,
						'global' => true,
						'deleted' => false,
						'throttled' => 0,
						'throttleEnabled' => false,
						'throttleCount' => 0,
						'throttlePeriod' => 0,
						'throttleGroups' => '',
						'warnEnabled' => false,
						'warnMessage' => 'abusefilter-warning',
						'warnMessageOther' => '',
						'disallowEnabled' => true,
						'blockautopromoteEnabled' => false,
						'degroupEnabled' => false,
						'blockEnabled' => false,
						'blockTalk' => false,
						'blockAnons' => 'infinity',
						'blockUsers' => 'infinity',
						'rangeblockEnabled' => false,
						'tagEnabled' => false,
						'tagTags' => ''
					],
					'testData' => [
						'doingWhat' => 'Trying to save a global filter without enough rights',
						'expectedResult' => 'an error message saying that I do not have the required rights',
						'expectedMessage' => 'abusefilter-edit-notallowed-global',
						'shouldFail' => true,
						'shouldBeSaved' => false,
						'customUserGroup' => '',
						'needsOtherFilters' => false
					]
				]
			],
			[
				[
					'filterParameters' => [
						'id' => 'new',
						'rules' => '1==1',
						'description' => 'Global with invalid warn message',
						'notes' => '',
						'group' => 'default',
						'enabled' => true,
						'hidden' => false,
						'global' => true,
						'deleted' => false,
						'throttled' => 0,
						'throttleEnabled' => false,
						'throttleCount' => 0,
						'throttlePeriod' => 0,
						'throttleGroups' => '',
						'warnEnabled' => true,
						'warnMessage' => 'abusefilter-beautiful-warning',
						'warnMessageOther' => '',
						'disallowEnabled' => false,
						'blockautopromoteEnabled' => false,
						'degroupEnabled' => false,
						'blockEnabled' => false,
						'blockTalk' => false,
						'blockAnons' => 'infinity',
						'blockUsers' => 'infinity',
						'rangeblockEnabled' => false,
						'tagEnabled' => false,
						'tagTags' => ''
					],
					'testData' => [
						'doingWhat' => 'Trying to save a global filter with a custom warn message',
						'expectedResult' => 'an error message saying that custom warn messages ' .
							'cannot be used for global rules',
						'expectedMessage' => 'abusefilter-edit-notallowed-global-custom-msg',
						'shouldFail' => true,
						'shouldBeSaved' => false,
						'customUserGroup' => 'filterEditorGlobal',
						'needsOtherFilters' => false
					]
				]
			],
			[
				[
					'filterParameters' => [
						'id' => 'new',
						'rules' => '1==1',
						'description' => 'Restricted action',
						'notes' => '',
						'group' => 'default',
						'enabled' => true,
						'hidden' => false,
						'global' => false,
						'deleted' => false,
						'throttled' => 0,
						'throttleEnabled' => false,
						'throttleCount' => 0,
						'throttlePeriod' => 0,
						'throttleGroups' => '',
						'warnEnabled' => false,
						'warnMessage' => 'abusefilter-warning',
						'warnMessageOther' => '',
						'disallowEnabled' => false,
						'blockautopromoteEnabled' => false,
						'degroupEnabled' => true,
						'blockEnabled' => false,
						'blockTalk' => false,
						'blockAnons' => 'infinity',
						'blockUsers' => 'infinity',
						'rangeblockEnabled' => false,
						'tagEnabled' => false,
						'tagTags' => ''
					],
					'testData' => [
						'doingWhat' => 'Trying to save a filter with a restricted action',
						'expectedResult' => 'an error message saying that the action is restricted',
						'expectedMessage' => 'abusefilter-edit-restricted',
						'shouldFail' => true,
						'shouldBeSaved' => false,
						'customUserGroup' => '',
						'needsOtherFilters' => false
					]
				]
			],
			[
				[
					'filterParameters' => [
						'id' => '1',
						'rules' => '/**/',
						'description' => 'Mock filter',
						'notes' => '',
						'group' => 'default',
						'enabled' => true,
						'hidden' => false,
						'global' => false,
						'deleted' => false,
						'throttled' => 0,
						'throttleEnabled' => false,
						'throttleCount' => 0,
						'throttlePeriod' => 0,
						'throttleGroups' => '',
						'warnEnabled' => false,
						'warnMessage' => 'abusefilter-warning',
						'warnMessageOther' => '',
						'disallowEnabled' => false,
						'blockautopromoteEnabled' => false,
						'degroupEnabled' => false,
						'blockEnabled' => false,
						'blockTalk' => false,
						'blockAnons' => 'infinity',
						'blockUsers' => 'infinity',
						'rangeblockEnabled' => false,
						'tagEnabled' => false,
						'tagTags' => ''
					],
					'testData' => [
						'doingWhat' => 'Trying to save a filter without changing anything',
						'expectedResult' => 'the validation to pass without the filter being saved',
						'expectedMessage' => '',
						'shouldFail' => false,
						'shouldBeSaved' => false,
						'customUserGroup' => '',
						'needsOtherFilters' => 1
					]
				]
			]
		];
	}
}
