<?php

use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator;
use PHPUnit\Framework\MockObject\MockObject;

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
 *
 * @covers AbuseFilter
 * @covers AFPData
 * @covers AbuseFilterVariableHolder
 * @covers AFComputedVariable
 */
class AbuseFilterTest extends MediaWikiUnitTestCase {
	/** A fake timestamp to use in several time-related tests. */
	private const FAKE_TIME = 1514700000;

	/**
	 * @inheritDoc
	 */
	protected function tearDown() : void {
		MWTimestamp::setFakeTime( false );
		parent::tearDown();
	}

	/**
	 * @param string $method
	 * @param mixed $result
	 * @return MockObject|User User type is here for IDE-friendliness
	 */
	private function getUserWithMockedMethod( $method, $result ) {
		$user = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();

		$user->expects( $this->atLeastOnce() )
			->method( $method )
			->willReturn( $result );

		return $user;
	}

	/**
	 * Given the name of a variable, create a User mock with that value
	 *
	 * @param string $var The variable name
	 * @return array the first position is the User mock, the second is the expected value
	 *   for the given variable
	 */
	private function getUserAndExpectedVariable( $var ) {
		switch ( $var ) {
			case 'user_editcount':
				$result = 7;
				$user = $this->getUserWithMockedMethod( 'getEditCount', $result );
				break;
			case 'user_name':
				$result = 'UniqueUserName';
				$user = $this->getUserWithMockedMethod( 'getName', $result );
				break;
			case 'user_emailconfirm':
				$result = wfTimestampNow();
				$user = $this->getUserWithMockedMethod( 'getEmailAuthenticationTimestamp', $result );
				break;
			case 'user_groups':
				$result = [ '*', 'group1', 'group2' ];
				$user = $this->getUserWithMockedMethod( 'getEffectiveGroups', $result );
				break;
			case 'user_rights':
				$result = [ 'abusefilter-foo', 'abusefilter-bar' ];
				$user = $this->getUserWithMockedMethod( 'getRights', $result );
				break;
			case 'user_blocked':
				$result = true;
				$user = $this->getUserWithMockedMethod( 'getBlock', $result );
				break;
			case 'user_age':
				MWTimestamp::setFakeTime( self::FAKE_TIME );
				$result = 163;
				$user = $this->getUserWithMockedMethod( 'getRegistration', self::FAKE_TIME - $result );
				break;
			default:
				throw new Exception( "Given unknown user-related variable $var." );
		}

		return [ $user, $result ];
	}

	/**
	 * Check that the generated user-related variables are correct
	 *
	 * @param string $varName The name of the variable we're currently testing
	 * @covers \MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator::addUserVars
	 * @dataProvider provideUserVars
	 */
	public function testAddUserVars( $varName ) {
		list( $user, $computed ) = $this->getUserAndExpectedVariable( $varName );

		$variableHolder = new AbuseFilterVariableHolder();
		$generator = new VariableGenerator( $variableHolder );
		$variableHolder = $generator->addUserVars( $user )->getVariableHolder();
		$actual = $variableHolder->getVar( $varName )->toNative();
		$this->assertSame( $computed, $actual );
	}

	/**
	 * Data provider for testAddUserVars
	 * @return Generator|array
	 */
	public function provideUserVars() {
		$vars = [
			'user_editcount',
			'user_name',
			'user_emailconfirm',
			'user_groups',
			'user_rights',
			'user_blocked',
			'user_age'
		];
		foreach ( $vars as $var ) {
			yield $var => [ $var ];
		}
	}

	/**
	 * @param string $method
	 * @param mixed $result
	 * @return MockObject|Title Title type is here for IDE-friendliness
	 */
	private function getTitleWithMockedMethod( $method, $result ) {
		$title = $this->getMockBuilder( Title::class )
			->disableOriginalConstructor()
			->getMock();

		$title->expects( $this->atLeastOnce() )
			->method( $method )
			->willReturn( $result );

		return $title;
	}

	/**
	 * Given the name of a variable, create a Title mock with that value
	 *
	 * @param string $prefix The prefix of the variable
	 * @param string $suffix The suffix of the variable
	 * @param bool $restricted Whether the title should be restricted
	 * @return array the first position is the mocked Title, the second the expected value of the var
	 */
	private function getTitleAndExpectedVariable( $prefix, $suffix, $restricted = false ) {
		switch ( $suffix ) {
			case '_id':
				$result = 1234;
				$title = $this->getTitleWithMockedMethod( 'getArticleID', $result );
				break;
			case '_namespace':
				$result = 5;
				$title = $this->getTitleWithMockedMethod( 'getNamespace', $result );
				break;
			case '_title':
				$result = 'Page title';
				$title = $this->getTitleWithMockedMethod( 'getText', $result );
				break;
			case '_prefixedtitle':
				$result = 'Page prefixedtitle';
				$title = $this->getTitleWithMockedMethod( 'getPrefixedText', $result );
				break;
			case '_restrictions_create':
			case '_restrictions_edit':
			case '_restrictions_move':
			case '_restrictions_upload':
				$result = $restricted ? [ 'sysop' ] : [];
				$title = $this->getTitleWithMockedMethod( 'getRestrictions', $result );
				break;
			// case '_recent_contributors' handled in AbuseFilterDBTest
			case '_first_contributor':
				$result = 'Fake username';
				$revision = $this->getMockBuilder( Revision::class )
					->disableOriginalConstructor()
					->setMethods( [ 'getUserText' ] )
					->getMock();
				$revision->expects( $this->atLeastOnce() )
					->method( 'getUserText' )
					->willReturn( $result );
				$title = $this->getTitleWithMockedMethod( 'getFirstRevision', $revision );
				break;
			case '_age':
				$result = 123;
				MWTimestamp::setFakeTime( self::FAKE_TIME );
				$title = $this->getTitleWithMockedMethod( 'getEarliestRevTime', self::FAKE_TIME - $result );
				break;
			default:
				throw new Exception( "Given unknown title-related variable $prefix$suffix;." );
		}

		return [ $title, $result ];
	}

	/**
	 * Check that the generated title-related variables are correct
	 *
	 * @param string $prefix The prefix of the variables we're currently testing
	 * @param string $suffix The suffix of the variables we're currently testing
	 * @param bool $restricted Used for _restrictions variable. If true,
	 *   the tested title will have the requested restriction.
	 * @covers \MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator::addTitleVars
	 * @dataProvider provideTitleVars
	 */
	public function testAddTitleVars( $prefix, $suffix, $restricted = false ) {
		$varName = $prefix . $suffix;
		list( $title, $computed ) = $this->getTitleAndExpectedVariable( $prefix, $suffix, $restricted );

		$variableHolder = $this->getMockBuilder( AbuseFilterVariableHolder::class )
			->setMethods( [ 'getLazyLoader' ] )
			->getMock();

		/** @var MockObject|AbuseFilterVariableHolder $variableHolder */
		$variableHolder->expects( $this->any() )
			->method( 'getLazyLoader' )
			->willReturnCallback( function ( $method, $params ) use ( $title ) {
				$lazyLoader = $this->getMockBuilder( AFComputedVariable::class )
					->setMethods( [ 'buildTitle' ] )
					->setConstructorArgs( [ $method, $params ] )
					->getMock();

				$lazyLoader->expects( $this->any() )
					->method( 'buildTitle' )
					->willReturn( $title );
				return $lazyLoader;
			} );

		$generator = new VariableGenerator( $variableHolder );
		$variableHolder = $generator->addTitleVars( $title, $prefix )->getVariableHolder();
		$actual = $variableHolder->getVar( $varName )->toNative();
		$this->assertSame( $computed, $actual );
	}

	/**
	 * Data provider for testAddTitleVars
	 * @return Generator|array
	 */
	public function provideTitleVars() {
		$prefixes = [ 'page', 'moved_from', 'moved_to' ];
		$suffixes = [
			'_id',
			'_namespace',
			'_title',
			'_prefixedtitle',
			'_restrictions_create',
			'_restrictions_edit',
			'_restrictions_move',
			'_restrictions_upload',
			'_first_contributor',
			'_age'
		];
		foreach ( $prefixes as $prefix ) {
			foreach ( $suffixes as $suffix ) {
				yield $prefix . $suffix => [ $prefix, $suffix ];
				if ( strpos( $suffix, 'restrictions' ) !== false ) {
					// Add a case where the page has the restriction
					yield $prefix . $suffix . ', restricted' => [ $prefix, $suffix, true ];
				}
			}
		}
	}

	/**
	 * @covers AbuseFilter::bufferTagsToSetByAction
	 */
	public function testTagsToSetWillNotContainDuplicates() {
		AbuseFilter::$tagsToSet = [];

		$iterations = 3;
		$actionID = wfRandomString( 30 );
		while ( $iterations-- ) {
			AbuseFilter::bufferTagsToSetByAction( [ $actionID => [ 'uniqueTag' ] ] );
			$this->assertSame( [ 'uniqueTag' ], reset( AbuseFilter::$tagsToSet ) );
		}
	}

	/**
	 * Check that version comparing works well
	 *
	 * @param array $firstVersion [ stdClass, array ]
	 * @param array $secondVersion [ stdClass, array ]
	 * @param array $expected The differences
	 * @covers AbuseFilter::compareVersions
	 * @dataProvider provideVersions
	 */
	public function testCompareVersions( $firstVersion, $secondVersion, $expected ) {
		$allActions = [
			'throttle', 'warn', 'disallow', 'blockautopromote', 'block', 'rangeblock', 'degroup', 'tag'
		];
		$differences = AbuseFilter::compareVersions( $firstVersion, $secondVersion, $allActions );

		$this->assertSame(
			$expected,
			$differences,
			'AbuseFilter::compareVersions did not output the expected result.'
		);
	}

	/**
	 * Data provider for testCompareVersions
	 * @return array
	 */
	public function provideVersions() {
		return [
			[
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'disallow' => []
					]
				],
				[
					(object)[
						'af_public_comments' => 'OtherComments',
						'af_pattern' => '/*Other pattern*/',
						'af_comments' => 'Other comments',
						'af_deleted' => 1,
						'af_enabled' => 0,
						'af_hidden' => 1,
						'af_global' => 1,
						'af_group' => 'flow'
					],
					[
						'disallow' => []
					]
				],
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
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'disallow' => []
					]
				],
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'disallow' => []
					]
				],
				[]
			],
			[
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'disallow' => []
					]
				],
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'degroup' => []
					]
				],
				[ 'actions' ]
			],
			[
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'disallow' => []
					]
				],
				[
					(object)[
						'af_public_comments' => 'OtherComments',
						'af_pattern' => '/*Other pattern*/',
						'af_comments' => 'Other comments',
						'af_deleted' => 1,
						'af_enabled' => 0,
						'af_hidden' => 1,
						'af_global' => 1,
						'af_group' => 'flow'
					],
					[
						'blockautopromote' => []
					]
				],
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
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'disallow' => []
					]
				],
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'warn' => [
							'abusefilter-warning'
						]
					]
				],
				[ 'actions' ]
			],
			[
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'warn' => [
							'abusefilter-warning'
						]
					]
				],
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'disallow' => []
					]
				],
				[ 'actions' ]
			],
			[
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'warn' => [
							'abusefilter-warning'
						]
					]
				],
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'warn' => [
							'abusefilter-my-best-warning'
						],
						'degroup' => []
					]
				],
				[ 'actions' ]
			],
			[
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'warn' => [
							'abusefilter-warning'
						]
					]
				],
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Other Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 1,
						'af_global' => 0,
						'af_group' => 'flow'
					],
					[
						'warn' => [
							'abusefilter-my-best-warning'
						]
					]
				],
				[
					'af_pattern',
					'af_hidden',
					'af_group',
					'actions'
				]
			],
			[
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'default'
					],
					[
						'warn' => [
							'abusefilter-beautiful-warning'
						]
					]
				],
				[
					(object)[
						'af_public_comments' => 'Comments',
						'af_pattern' => '/*Pattern*/',
						'af_comments' => 'Comments',
						'af_deleted' => 0,
						'af_enabled' => 1,
						'af_hidden' => 0,
						'af_global' => 0,
						'af_group' => 'flow'
					],
					[
						'warn' => [
							'abusefilter-my-best-warning'
						]
					]
				],
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
						'af_enabled' => 1
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
						'af_enabled' => 0
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
					'afh_flags' => 'enabled,hidden',
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
						'af_enabled' => 1
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
						'af_enabled' => 0
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
}
