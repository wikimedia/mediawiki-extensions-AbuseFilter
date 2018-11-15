<?php
/**
 * Generic tests for utility functions in AbuseFilter
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
 * @group Database
 *
 * @covers AbuseFilter
 * @covers AFPData
 * @covers AbuseFilterVariableHolder
 * @covers AFComputedVariable
 */
class AbuseFilterTest extends MediaWikiTestCase {
	/** @var User */
	protected static $mUser;
	/** @var Title */
	protected static $mTitle;
	/** @var WikiPage */
	protected static $mPage;
	/** @var AbuseFilterVariableHolder */
	protected static $mVariables;

	/**
	 * @var array These tables will be deleted in parent::tearDown.
	 *   We need it to happen to make tests on fresh pages.
	 */
	protected $tablesUsed = [
		'page',
		'page_restrictions',
		'abuse_filter',
		'abuse_filter_history',
		'abuse_filter_log',
		'abuse_filter_actions'
	];

	/**
	 * @see MediaWikiTestCase::setUp
	 */
	protected function setUp() {
		parent::setUp();
		MWTimestamp::setFakeTime( 1514700000 );
		$user = User::newFromName( 'AnotherFilteredUser' );
		$user->addToDatabase();
		$user->addGroup( 'basicFilteredUser' );
		self::$mUser = $user;
		MWTimestamp::setFakeTime( false );

		self::$mVariables = new AbuseFilterVariableHolder();

		// Make sure that the config we're using is the one we're expecting
		$this->setMwGlobals( [
			'wgUser' => $user,
			'wgRestrictionTypes' => [
				'create',
				'edit',
				'move',
				'upload'
			],
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
			],
			'wgEnableParserLimitReporting' => false
		] );
		$this->setGroupPermissions( [
			'basicFilteredUser' => [
				'abusefilter-view' => true
			],
			'intermediateFilteredUser' => [
				'abusefilter-log' => true
			],
			'privilegedFilteredUser' => [
				'abusefilter-private' => true,
				'abusefilter-revert' => true
			]
		] );
	}

	/**
	 * @see MediaWikiTestCase::tearDown
	 */
	protected function tearDown() {
		MWTimestamp::setFakeTime( false );
		$userGroups = self::$mUser->getGroups();
		// We want to start fresh
		foreach ( $userGroups as $group ) {
			self::$mUser->removeGroup( $group );
		}
		parent::tearDown();
	}

	/**
	 * Given the name of a variable, naturally sets it to a determined amount
	 *
	 * @param string $var The variable name
	 * @return array the first position is the result (mixed), the second is a boolean
	 *   indicating whether we've been able to compute the given variable
	 */
	private static function computeExpectedUserVariable( $var ) {
		$success = true;
		switch ( $var ) {
			case 'user_editcount':
				// Create a page and make the user edit it 7 times
				$page = WikiPage::factory( Title::newFromText( 'UTPage' ) );
				$page->doEditContent(
					new WikitextContent( 'AbuseFilter test, page creation' ),
					'Testing page for AbuseFilter',
					EDIT_NEW,
					false,
					self::$mUser
				);
				for ( $i = 1; $i <= 7; $i++ ) {
					$page->doEditContent(
						new WikitextContent( "AbuseFilter test, page revision #$i" ),
						'Testing page for AbuseFilter',
						EDIT_UPDATE,
						false,
						self::$mUser
					);
				}
				// Reload to reflect deferred update
				self::$mUser->clearInstanceCache();
				$result = 7;
				break;
			case 'user_name':
				$result = self::$mUser->getName();
				break;
			case 'user_emailconfirm':
				$time = wfTimestampNow();
				self::$mUser->setEmailAuthenticationTimestamp( $time );
				$result = $time;
				break;
			case 'user_groups':
				self::$mUser->addGroup( 'intermediateFilteredUser' );
				$result = self::$mUser->getEffectiveGroups();
				break;
			case 'user_rights':
				self::$mUser->addGroup( 'privilegedFilteredUser' );
				$result = self::$mUser->getRights();
				break;
			case 'user_blocked':
				$block = new Block();
				$block->setTarget( self::$mUser );
				$block->setBlocker( 'UTSysop' );
				$block->mReason = 'Testing AbuseFilter variable user_blocked';
				$block->mExpiry = 'infinity';

				$block->insert();
				$result = true;
				break;
			default:
				$success = false;
				$result = null;
		}
		return [ $result, $success ];
	}

	/**
	 * Check that the generated user-related variables are correct
	 *
	 * @param string $varName The name of the variable we're currently testing
	 * @covers AbuseFilter::generateUserVars
	 * @dataProvider provideUserVars
	 */
	public function testGenerateUserVars( $varName ) {
		list( $computed, $successfully ) = self::computeExpectedUserVariable( $varName );
		if ( !$successfully ) {
			$this->fail( "Given unknown user-related variable $varName." );
		}

		$variableHolder = AbuseFilter::generateUserVars( self::$mUser );
		$actual = $variableHolder->getVar( $varName )->toNative();
		$this->assertSame(
			$computed,
			$actual,
			"AbuseFilter variable $varName is computed wrongly."
		);
	}

	/**
	 * Data provider for testGenerateUserVars
	 * @return array
	 */
	public function provideUserVars() {
		return [
			[ 'user_editcount' ],
			[ 'user_name' ],
			[ 'user_emailconfirm' ],
			[ 'user_groups' ],
			[ 'user_rights' ],
			[ 'user_blocked' ]
		];
	}

	/**
	 * Check that user_age is correct. Needs a separate function to take into account the
	 *   difference between timestamps due to test execution time
	 *
	 * @covers AbuseFilter::generateUserVars
	 */
	public function testUserAgeVar() {
		// Set a fake timestamp so that execution time won't be a problem
		MWTimestamp::setFakeTime( 1514700163 );
		$variableHolder = AbuseFilter::generateUserVars( self::$mUser );
		$actual = $variableHolder->getVar( 'user_age' )->toNative();

		$this->assertEquals(
			163,
			$actual,
			"AbuseFilter variable user_age is computed wrongly. Expected: 163, actual: $actual."
		);
	}

	/**
	 * Given the name of a variable, naturally sets it to a determined amount
	 *
	 * @param string $suffix The suffix of the variable
	 * @param string|null $options Further options for the test
	 * @return array the first position is the result (mixed), the second is a boolean
	 *   indicating whether we've been able to compute the given variable. If false, then
	 *   the result may be null if the requested variable doesn't exist, or false if there
	 *   has been some other problem.
	 */
	private static function computeExpectedTitleVariable( $suffix, $options = null ) {
		self::$mTitle = Title::newFromText( 'AbuseFilter test' );
		$page = WikiPage::factory( self::$mTitle );

		if ( $options === 'restricted' ) {
			$action = str_replace( '_restrictions_', '', $suffix );
			$namespace = 0;
			if ( $action === 'upload' ) {
				// Only files can have it
				$namespace = 6;
			}
			self::$mTitle = Title::makeTitle( $namespace, 'AbuseFilter restrictions test' );
			$page = WikiPage::factory( self::$mTitle );
			if ( $action !== 'create' ) {
				// To apply other restrictions, the title has to exist
				$page->doEditContent(
					new WikitextContent( 'AbuseFilter test for title variables' ),
					'Testing page for AbuseFilter',
					EDIT_NEW,
					false,
					self::$mUser
				);
			}
			$_ = false;
			$s = $page->doUpdateRestrictions(
				[ $action => true ],
				[ $action => 'infinity' ],
				$_,
				'Testing restrictions for AbuseFilter',
				self::$mUser
			);
		}
		$success = true;
		switch ( $suffix ) {
			case '_id':
				$result = self::$mTitle->getArticleID();
				break;
			case '_namespace':
				$result = self::$mTitle->getNamespace();
				break;
			case '_title':
				$result = self::$mTitle->getText();
				break;
			case '_prefixedtitle':
				$result = self::$mTitle->getPrefixedText();
				break;
			case '_restrictions_create':
				$restrictions = self::$mTitle->getRestrictions( 'create' );
				$restrictions = count( $restrictions ) ? $restrictions : [];
				$preliminarCheck = !( $options === 'restricted' xor count( $restrictions ) );
				if ( $preliminarCheck ) {
					$result = $restrictions;
				} else {
					$success = false;
					$result = false;
				}
				break;
			case '_restrictions_edit':
				$restrictions = self::$mTitle->getRestrictions( 'edit' );
				$restrictions = count( $restrictions ) ? $restrictions : [];
				$preliminarCheck = !( $options === 'restricted' xor count( $restrictions ) );
				if ( $preliminarCheck ) {
					$result = $restrictions;
				} else {
					$success = false;
					$result = false;
				}
				break;
			case '_restrictions_move':
				$restrictions = self::$mTitle->getRestrictions( 'move' );
				$restrictions = count( $restrictions ) ? $restrictions : [];
				$preliminarCheck = !( $options === 'restricted' xor count( $restrictions ) );
				if ( $preliminarCheck ) {
					$result = $restrictions;
				} else {
					$success = false;
					$result = false;
				}
				break;
			case '_restrictions_upload':
				$restrictions = self::$mTitle->getRestrictions( 'upload' );
				$restrictions = count( $restrictions ) ? $restrictions : [];
				$preliminarCheck = !( $options === 'restricted' xor count( $restrictions ) );
				if ( $preliminarCheck ) {
					$result = $restrictions;
				} else {
					$success = false;
					$result = false;
				}
				break;
			case '_recent_contributors':
				// Create the page and make a couple of edits from different users
				$page->doEditContent(
					new WikitextContent( 'AbuseFilter test for title variables' ),
					'Testing page for AbuseFilter',
					EDIT_NEW,
					false,
					self::$mUser
				);
				$mockContributors = [ 'X>Alice', 'X>Bob', 'X>Charlie' ];
				foreach ( $mockContributors as $user ) {
					$page->doEditContent(
						new WikitextContent( "AbuseFilter test, page revision by $user" ),
						'Testing page for AbuseFilter',
						EDIT_UPDATE,
						false,
						User::newFromName( $user, false )
					);
				}
				$contributors = array_reverse( $mockContributors );
				array_push( $contributors, self::$mUser->getName() );
				$result = $contributors;
				break;
			case '_first_contributor':
				// Create the page and make a couple of edits from different users
				$page->doEditContent(
					new WikitextContent( 'AbuseFilter test for title variables' ),
					'Testing page for AbuseFilter',
					EDIT_NEW,
					false,
					self::$mUser
				);
				$mockContributors = [ 'X>Alice', 'X>Bob', 'X>Charlie' ];
				foreach ( $mockContributors as $user ) {
					$page->doEditContent(
						new WikitextContent( "AbuseFilter test, page revision by $user" ),
						'Testing page for AbuseFilter',
						EDIT_UPDATE,
						false,
						User::newFromName( $user, false )
					);
				}
				$result = self::$mUser->getName();
				break;
			default:
				$success = false;
				$result = null;
		}
		return [ $result, $success ];
	}

	/**
	 * Check that the generated title-related variables are correct
	 *
	 * @param string $prefix The prefix of the variables we're currently testing
	 * @param string $suffix The suffix of the variables we're currently testing
	 * @param string|null $options Whether we want to execute the test with specific options
	 *   Right now, this can only be 'restricted' for restrictions variables; in this case,
	 *   the tested title will have the requested restriction.
	 * @covers AbuseFilter::generateTitleVars
	 * @dataProvider provideTitleVars
	 */
	public function testGenerateTitleVars( $prefix, $suffix, $options = null ) {
		$varName = $prefix . $suffix;
		list( $computed, $successfully ) = self::computeExpectedTitleVariable( $suffix, $options );
		if ( !$successfully ) {
			if ( $computed === null ) {
				$this->fail( "Given unknown title-related variable $varName." );
			} else {
				$this->fail( "AbuseFilter variable $varName is computed wrongly." );
			}
		}

		$variableHolder = AbuseFilter::generateTitleVars( self::$mTitle, $prefix );
		$actual = $variableHolder->getVar( $varName )->toNative();
		$this->assertSame(
			$computed,
			$actual,
			"AbuseFilter variable $varName is computed wrongly."
		);
	}

	/**
	 * Data provider for testGenerateUserVars
	 * @return array
	 */
	public function provideTitleVars() {
		return [
			[ 'page', '_id' ],
			[ 'page', '_namespace' ],
			[ 'page', '_title' ],
			[ 'page', '_prefixedtitle' ],
			[ 'page', '_restrictions_create' ],
			[ 'page', '_restrictions_create', 'restricted' ],
			[ 'page', '_restrictions_edit' ],
			[ 'page', '_restrictions_edit', 'restricted' ],
			[ 'page', '_restrictions_move' ],
			[ 'page', '_restrictions_move', 'restricted' ],
			[ 'page', '_restrictions_upload' ],
			[ 'page', '_restrictions_upload', 'restricted' ],
			[ 'page', '_first_contributor' ],
			[ 'page', '_recent_contributors' ],
			[ 'moved_from', '_id' ],
			[ 'moved_from', '_namespace' ],
			[ 'moved_from', '_title' ],
			[ 'moved_from', '_prefixedtitle' ],
			[ 'moved_from', '_restrictions_create' ],
			[ 'moved_from', '_restrictions_create', 'restricted' ],
			[ 'moved_from', '_restrictions_edit' ],
			[ 'moved_from', '_restrictions_edit', 'restricted' ],
			[ 'moved_from', '_restrictions_move' ],
			[ 'moved_from', '_restrictions_move', 'restricted' ],
			[ 'moved_from', '_restrictions_upload' ],
			[ 'moved_from', '_restrictions_upload', 'restricted' ],
			[ 'moved_from', '_first_contributor' ],
			[ 'moved_from', '_recent_contributors' ],
			[ 'moved_to', '_id' ],
			[ 'moved_to', '_namespace' ],
			[ 'moved_to', '_title' ],
			[ 'moved_to', '_prefixedtitle' ],
			[ 'moved_to', '_restrictions_create' ],
			[ 'moved_to', '_restrictions_create', 'restricted' ],
			[ 'moved_to', '_restrictions_edit' ],
			[ 'moved_to', '_restrictions_edit', 'restricted' ],
			[ 'moved_to', '_restrictions_move' ],
			[ 'moved_to', '_restrictions_move', 'restricted' ],
			[ 'moved_to', '_restrictions_upload' ],
			[ 'moved_to', '_restrictions_upload', 'restricted' ],
			[ 'moved_to', '_first_contributor' ],
			[ 'moved_to', '_recent_contributors' ],
		];
	}

	/**
	 * Check that _age variables are correct. They need a separate function to take into
	 *   account the difference between timestamps due to test execution time
	 *
	 * @param string $prefix Prefix of the variable to test
	 * @covers AbuseFilter::generateTitleVars
	 * @dataProvider provideAgeVars
	 */
	public function testAgeVars( $prefix ) {
		$varName = $prefix . '_age';

		MWTimestamp::setFakeTime( 1514700000 );
		self::$mTitle = Title::newFromText( 'AbuseFilter test' );
		$page = WikiPage::factory( self::$mTitle );
		$page->doEditContent(
			new WikitextContent( 'AbuseFilter _age variables test' ),
			'Testing page for AbuseFilter',
			EDIT_NEW,
			false,
			self::$mUser
		);

		MWTimestamp::setFakeTime( 1514700123 );
		$variableHolder = AbuseFilter::generateTitleVars( self::$mTitle, $prefix );
		$actual = $variableHolder->getVar( $varName )->toNative();
		$this->assertEquals(
			123,
			$actual,
			"AbuseFilter variable $varName is computed wrongly. Expected: 123, actual: $actual."
		);
	}

	/**
	 * Data provider for testAgeVars
	 * @return array
	 */
	public function provideAgeVars() {
		return [
			[ 'page' ],
			[ 'moved_from' ],
			[ 'moved_to' ],
		];
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
		$differences = AbuseFilter::compareVersions( $firstVersion, $secondVersion );

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
						'disallow' => [
							'action' => 'disallow',
							'parameters' => []
						]
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
						'disallow' => [
							'action' => 'disallow',
							'parameters' => []
						]
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
						'disallow' => [
							'action' => 'disallow',
							'parameters' => []
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
						'disallow' => [
							'action' => 'disallow',
							'parameters' => []
						]
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
						'disallow' => [
							'action' => 'disallow',
							'parameters' => []
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
						'degroup' => [
							'action' => 'degroup',
							'parameters' => []
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
						'disallow' => [
							'action' => 'disallow',
							'parameters' => []
						]
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
						'blockautopromote' => [
							'action' => 'blockautopromote',
							'parameters' => []
						]
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
						'disallow' => [
							'action' => 'disallow',
							'parameters' => []
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
							'action' => 'warn',
							'parameters' => [
								'abusefilter-warning'
							]
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
							'action' => 'warn',
							'parameters' => [
								'abusefilter-warning'
							]
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
						'disallow' => [
							'action' => 'disallow',
							'parameters' => []
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
							'action' => 'warn',
							'parameters' => [
								'abusefilter-warning'
							]
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
							'action' => 'warn',
							'parameters' => [
								'abusefilter-my-best-warning'
							]
						],
						'degroup' => [
							'action' => 'degroup',
							'parameters' => []
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
							'action' => 'warn',
							'parameters' => [
								'abusefilter-warning'
							]
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
							'action' => 'warn',
							'parameters' => [
								'abusefilter-my-best-warning'
							]
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
							'action' => 'warn',
							'parameters' => [
								'abusefilter-beautiful-warning'
							]
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
							'action' => 'warn',
							'parameters' => [
								'abusefilter-my-best-warning'
							]
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

		$this->assertEquals(
			$expected,
			$actual,
			'AbuseFilter::translateFromHistory produced a wrong output.'
		);
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
						'degroup' => [
							'action' => 'degroup',
							'parameters' => []
						],
						'disallow' => [
							'action' => 'disallow',
							'parameters' => []
						]
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
							'action' => 'warn',
							'parameters' => [
								'abusefilter-warning',
								''
							]
						],
						'disallow' => [
							'action' => 'disallow',
							'parameters' => []
						]
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
							'action' => 'warn',
							'parameters' => [
								'abusefilter-warning',
								''
							]
						],
						'disallow' => [
							'action' => 'disallow',
							'parameters' => []
						],
						'block' => [
							'action' => 'block',
							'parameters' => [
								'blocktalk',
								'8 hours',
								'infinity'
							]
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
							'action' => 'throttle',
							'parameters' => [
								'131',
								'3,60',
								'user'
							]
						],
						'tag' => [
							'action' => 'tag',
							'parameters' => [
								'mytag',
								'yourtag'
							]
						]
					]
				]
			]
		];
	}

	/**
	 * Given the name of a variable, naturally sets it to a determined amount
	 *
	 * @param string $old The old wikitext of the page
	 * @param string $new The new wikitext of the page
	 * @return array
	 */
	private static function computeExpectedEditVariable( $old, $new ) {
		global $wgParser;
		$popts = ParserOptions::newFromUser( self::$mUser );
		// Order matters here. Some variables rely on other ones.
		$variables = [
			'new_html',
			'new_pst',
			'new_text',
			'edit_diff',
			'edit_diff_pst',
			'new_size',
			'old_size',
			'edit_delta',
			'added_lines',
			'removed_lines',
			'added_lines_pst',
			'all_links',
			'old_links',
			'added_links',
			'removed_links'
		];

		// Set required variables
		self::$mVariables->setVar( 'old_wikitext', $old );
		self::$mVariables->setVar( 'new_wikitext', $new );
		self::$mVariables->setVar( 'summary', 'Testing page for AbuseFilter' );

		$computedVariables = [];
		foreach ( $variables as $var ) {
			$success = true;
			// Reset text variables since some operations are changing them.
			$oldText = $old;
			$newText = $new;
			switch ( $var ) {
				case 'edit_diff_pst':
					$newText = self::$mVariables->getVar( 'new_pst' )->toString();
					// Intentional fall-through
				case 'edit_diff':
					$diffs = new Diff( explode( "\n", $oldText ), explode( "\n", $newText ) );
					$format = new UnifiedDiffFormatter();
					$result = $format->format( $diffs );
					break;
				case 'new_size':
					$result = strlen( $newText );
					break;
				case 'old_size':
					$result = strlen( $oldText );
					break;
				case 'edit_delta':
					$result = strlen( $newText ) - strlen( $oldText );
					break;
				case 'added_lines_pst':
				case 'added_lines':
				case 'removed_lines':
					$diffVariable = $var === 'added_lines_pst' ? 'edit_diff_pst' : 'edit_diff';
					$diff = self::$mVariables->getVar( $diffVariable )->toString();
					$line_prefix = $var === 'removed_lines' ? '-' : '+';
					$diff_lines = explode( "\n", $diff );
					$interest_lines = [];
					foreach ( $diff_lines as $line ) {
						if ( substr( $line, 0, 1 ) === $line_prefix ) {
							$interest_lines[] = substr( $line, strlen( $line_prefix ) );
						}
					}
					$result = $interest_lines;
					break;
				case 'new_text':
					$newHtml = self::$mVariables->getVar( 'new_html' )->toString();
					$result = StringUtils::delimiterReplace( '<', '>', '', $newHtml );
					break;
				case 'new_pst':
				case 'new_html':
					$article = self::$mPage;
					$content = ContentHandler::makeContent( $newText, $article->getTitle() );
					$editInfo = $article->prepareContentForEdit( $content );

					if ( $var === 'new_pst' ) {
						$result = $editInfo->pstContent->serialize( $editInfo->format );
					} else {
						$result = $editInfo->output->getText();
					}
					break;
				case 'all_links':
					$article = self::$mPage;
					$content = ContentHandler::makeContent( $newText, $article->getTitle() );
					$editInfo = $article->prepareContentForEdit( $content );
					$result = array_keys( $editInfo->output->getExternalLinks() );
					break;
				case 'old_links':
					$article = self::$mPage;
					$popts->setTidy( true );
					$edit = $wgParser->parse( $oldText, $article->getTitle(), $popts );
					$result = array_keys( $edit->getExternalLinks() );
					break;
				case 'added_links':
				case 'removed_links':
					$oldLinks = self::$mVariables->getVar( 'old_links' )->toString();
					$newLinks = self::$mVariables->getVar( 'all_links' )->toString();
					$oldLinks = explode( "\n", $oldLinks );
					$newLinks = explode( "\n", $newLinks );

					if ( $var === 'added_links' ) {
						$result = array_diff( $newLinks, $oldLinks );
					} else {
						$result = array_diff( $oldLinks, $newLinks );
					}
					break;
				default:
					$success = false;
					$result = null;
			}
			$computedVariables[$var] = [ $result, $success ];
			self::$mVariables->setVar( $var, $result );
		}
		return $computedVariables;
	}

	/**
	 * Check that the generated variables for edits are correct
	 *
	 * @param string $oldText The old wikitext of the page
	 * @param string $newText The new wikitext of the page
	 * @covers AbuseFilter::getEditVars
	 * @dataProvider provideEditVars
	 */
	public function testGetEditVars( $oldText, $newText ) {
		global $wgLang;
		self::$mTitle = Title::makeTitle( 0, 'AbuseFilter test' );
		self::$mPage = WikiPage::factory( self::$mTitle );

		self::$mPage->doEditContent(
			new WikitextContent( $oldText ),
			'Creating the test page',
			EDIT_NEW,
			false,
			self::$mUser
		);
		self::$mPage->doEditContent(
			new WikitextContent( $newText ),
			'Testing page for AbuseFilter',
			EDIT_UPDATE,
			false,
			self::$mUser
		);

		$computeResult = self::computeExpectedEditVariable( $oldText, $newText );

		$computedVariables = [];
		foreach ( $computeResult as $varName => $computed ) {
			if ( !$computed[1] ) {
				$this->fail( "Given unknown edit variable $varName." );
			}
			$computedVariables[$varName] = $computed[0];
		}

		self::$mVariables->addHolders( AbuseFilter::getEditVars( self::$mTitle, self::$mPage ) );

		$actualVariables = [];
		foreach ( self::$mVariables->mVars as $varName => $_ ) {
			$actualVariables[$varName] = self::$mVariables->getVar( $varName )->toNative();
		}

		$differences = [];
		foreach ( $computedVariables as $var => $computed ) {
			if ( !isset( $actualVariables[$var] ) ) {
				$this->fail( "AbuseFilter::getEditVars didn't set the $var variable." );
			} elseif ( $computed !== $actualVariables[$var] ) {
				$differences[] = $var;
			}
		}

		$this->assertCount(
			0,
			$differences,
			'The following AbuseFilter variables are computed wrongly: ' . $wgLang->commaList( $differences )
		);
	}

	/**
	 * Data provider for testGetEditVars
	 * @return array
	 */
	public function provideEditVars() {
		return [
			[
				'[https://www.mediawiki.it/wiki/Extension:AbuseFilter AbuseFilter] test page',
				'Adding something to compute edit variables. Here are some diacritics to make sure ' .
					"the test behaves well with unicode: Là giù cascherò io altresì.\n名探偵コナン.\n" .
					"[[Help:Pre Save Transform|]] should make the difference as well.\n" .
					'Instead, [https://www.mediawiki.it this] is an external link.'
			],
			[
				'Adding something to compute edit variables. Here are some diacritics to make sure ' .
					"the test behaves well with unicode: Là giù cascherò io altresì.\n名探偵コナン.\n" .
					"[[Help:Pre Save Transform|]] should make the difference as well.\n" .
					'Instead, [https://www.mediawiki.it this] is an external link.',
				'[https://www.mediawiki.it/wiki/Extension:AbuseFilter AbuseFilter] test page'
			],
			[
				"A '''foo''' is not a ''bar''.",
				"Actually, according to [http://en.wikipedia.org ''Wikipedia''], a '''''foo''''' " .
					'is <small>more or less</small> the same as a <b>bar</b>, except that a foo is ' .
					'usually provided together with a [[cellar door|]] to make it work<ref>Yes, really</ref>.'
			],
			[
				'This edit will be pretty smll',
				'This edit will be pretty small'
			]
		];
	}
}
