<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;

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
	/** A fake timestamp to use in several time-related tests. */
	const FAKE_TIME = 1514700000;

	/**
	 * @var array These tables will be deleted in parent::tearDown.
	 *   We need it to happen to make tests on fresh pages.
	 */
	protected $tablesUsed = [
		'page',
		'page_restrictions',
		'user',
		'text',
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
		// Make sure that the config we're using is the one we're expecting
		$this->setMwGlobals( [
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
		$this->overrideMwServices();
	}

	/**
	 * @see MediaWikiTestCase::tearDown
	 */
	protected function tearDown() {
		MWTimestamp::setFakeTime( false );
		parent::tearDown();
	}

	/**
	 * Given the name of a variable, naturally sets it to a determined amount
	 *
	 * @param User $user
	 * @param string $var The variable name
	 * @return array the first position is the result (mixed), the second is a boolean
	 *   indicating whether we've been able to compute the given variable
	 */
	private function computeExpectedUserVariable( User $user, $var ) {
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
					$user
				);
				for ( $i = 1; $i <= 7; $i++ ) {
					$page->doEditContent(
						new WikitextContent( "AbuseFilter test, page revision #$i" ),
						'Testing page for AbuseFilter',
						EDIT_UPDATE,
						false,
						$user
					);
				}
				// Reload to reflect deferred update
				$user->clearInstanceCache();
				$result = 7;
				break;
			case 'user_name':
				$result = $user->getName();
				break;
			case 'user_emailconfirm':
				$time = wfTimestampNow();
				$user->setEmailAuthenticationTimestamp( $time );
				$result = $time;
				break;
			case 'user_groups':
				$result = $user->getEffectiveGroups();
				$user->addGroup( 'AFTestUserGroups' );
				array_unshift( $result, 'AFTestUserGroups' );
				break;
			case 'user_rights':
				$rights = [ 'abusefilter-foo', 'abusefilter-bar' ];
				$this->setGroupPermissions( [
					'AFTestUserRights' => array_fill_keys( $rights, true )
				] );
				$this->overrideMwServices();
				$previous = $user->getRights();
				$user->addGroup( 'AFTestUserRights' );
				$user->clearInstanceCache();
				$result = array_merge( $rights, $previous );
				break;
			case 'user_blocked':
				$block = new Block();
				$block->setTarget( $user );
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
		$user = $this->getMutableTestUser()->getUser();
		list( $computed, $successfully ) = $this->computeExpectedUserVariable( $user, $varName );
		if ( !$successfully ) {
			$this->fail( "Given unknown user-related variable $varName." );
		}

		$variableHolder = AbuseFilter::generateUserVars( $user );
		$actual = $variableHolder->getVar( $varName )->toNative();
		$this->assertSame( $computed, $actual );
	}

	/**
	 * Data provider for testGenerateUserVars
	 * @return Generator|array
	 */
	public function provideUserVars() {
		$vars = [
			'user_editcount',
			'user_name',
			'user_emailconfirm',
			'user_groups',
			'user_rights',
			'user_blocked'
		];
		foreach ( $vars as $var ) {
			yield $var => [ $var ];
		}
	}

	/**
	 * Check that user_age is correct. Needs a separate function to take into account the
	 *   difference between timestamps due to test execution time
	 *
	 * @covers AbuseFilter::generateUserVars
	 */
	public function testUserAgeVar() {
		MWTimestamp::setFakeTime( self::FAKE_TIME );
		$user = User::newFromName( 'TestUserAge' );
		$user->addToDatabase();
		$expected = 163;
		// Set a fake timestamp so that execution time won't be a problem
		MWTimestamp::setFakeTime( self::FAKE_TIME + $expected );
		$variableHolder = AbuseFilter::generateUserVars( $user );
		$actual = $variableHolder->getVar( 'user_age' )->toNative();

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Given the name of a variable, naturally sets it to a determined amount
	 *
	 * @param Title $title The title to use for computing variables
	 * @param string $suffix The suffix of the variable
	 * @param string|null $options Further options for the test
	 * @return array the first position is the result (mixed), the second is a boolean
	 *   indicating whether we've been able to compute the given variable. If false, then
	 *   the result may be null if the requested variable doesn't exist, or false if there
	 *   has been some other problem.
	 */
	private function computeExpectedTitleVariable( Title $title, $suffix, $options = null ) {
		$page = WikiPage::factory( $title );
		$user = $this->getMutableTestUser()->getUser();

		if ( $options === 'restricted' ) {
			$action = str_replace( '_restrictions_', '', $suffix );
			if ( $action !== 'create' ) {
				// To apply other restrictions, the title has to exist
				$page->doEditContent(
					new WikitextContent( 'AbuseFilter test for title variables' ),
					'Testing page for AbuseFilter',
					EDIT_NEW,
					false,
					$user
				);
			}
			$cascade = false;
			$page->doUpdateRestrictions(
				[ $action => true ],
				[ $action => 'infinity' ],
				$cascade,
				'Testing restrictions for AbuseFilter',
				$user
			);
		}
		$success = true;
		switch ( $suffix ) {
			case '_id':
				$result = $title->getArticleID();
				break;
			case '_namespace':
				$result = $title->getNamespace();
				break;
			case '_title':
				$result = $title->getText();
				break;
			case '_prefixedtitle':
				$result = $title->getPrefixedText();
				break;
			case '_restrictions_create':
			case '_restrictions_edit':
			case '_restrictions_move':
			case '_restrictions_upload':
				$type = str_replace( '_restrictions_', '', $suffix );
				$restrictions = $title->getRestrictions( $type );
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
					$user
				);
				$mockContributors = [ 'X>Alice', 'X>Bob', 'X>Charlie' ];
				foreach ( $mockContributors as $contributor ) {
					$page->doEditContent(
						new WikitextContent( "AbuseFilter test, page revision by $contributor" ),
						'Testing page for AbuseFilter',
						EDIT_UPDATE,
						false,
						User::newFromName( $contributor, false )
					);
				}
				$contributors = array_reverse( $mockContributors );
				array_push( $contributors, $user->getName() );
				$result = $contributors;
				break;
			case '_first_contributor':
				// Create the page and make a couple of edits from different users
				$page->doEditContent(
					new WikitextContent( 'AbuseFilter test for title variables' ),
					'Testing page for AbuseFilter',
					EDIT_NEW,
					false,
					$user
				);
				$mockContributors = [ 'X>Alice', 'X>Bob', 'X>Charlie' ];
				foreach ( $mockContributors as $contributor ) {
					$page->doEditContent(
						new WikitextContent( "AbuseFilter test, page revision by $contributor" ),
						'Testing page for AbuseFilter',
						EDIT_UPDATE,
						false,
						User::newFromName( $contributor, false )
					);
				}
				$result = $user->getName();
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
		$titleNamespace = 0;
		$titleText = 'AbuseFilter test';
		if ( $options === 'restricted' ) {
			// Test on a different page
			$titleText = 'AbuseFilter restrictions test';
			if ( str_replace( '_restrictions_', '', $suffix ) === 'upload' ) {
				// Only files can have upload restrictions
				$titleNamespace = 6;
			}
		}
		$title = Title::makeTitle( $titleNamespace, $titleText );
		list( $computed, $success ) = $this->computeExpectedTitleVariable( $title, $suffix, $options );
		if ( !$success ) {
			if ( $computed === null ) {
				$this->fail( "Given unknown title-related variable $varName." );
			} else {
				$this->fail( "AbuseFilter variable $varName is computed wrongly." );
			}
		}

		$variableHolder = AbuseFilter::generateTitleVars( $title, $prefix );
		$actual = $variableHolder->getVar( $varName )->toNative();
		$this->assertSame( $computed, $actual );
	}

	/**
	 * Data provider for testGenerateUserVars
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
			'_recent_contributors'
		];
		foreach ( $prefixes as $prefix ) {
			foreach ( $suffixes as $suffix ) {
				yield $prefix . $suffix => [ $prefix, $suffix ];
				if ( strpos( $suffix, 'restrictions' ) !== false ) {
					// Add a case where the page has the restriction
					yield $prefix . $suffix . ', restricted' => [ $prefix, $suffix, 'restricted' ];
				}
			}
		}
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

		MWTimestamp::setFakeTime( self::FAKE_TIME );
		$title = Title::newFromText( 'AbuseFilter test' );
		$page = WikiPage::factory( $title );
		$page->doEditContent(
			new WikitextContent( 'AbuseFilter _age variables test' ),
			'Testing page for AbuseFilter',
			EDIT_NEW,
			false,
			$this->getTestUser()->getUser()
		);

		$expected = 123;
		MWTimestamp::setFakeTime( self::FAKE_TIME + $expected );
		$variableHolder = AbuseFilter::generateTitleVars( $title, $prefix );
		$actual = $variableHolder->getVar( $varName )->toNative();
		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Data provider for testAgeVars
	 * @return Generator|array
	 */
	public function provideAgeVars() {
		$prefixes = [ 'page', 'moved_from', 'moved_to' ];
		foreach ( $prefixes as $prefix ) {
			yield "{$prefix}_age" => [ $prefix ];
		}
	}

	/**
	 * @covers \AbuseFilter::bufferTagsToSetByAction
	 */
	public function testTagsToSetWillNotContainDuplicates() {
		$this->assertSame( [], AbuseFilter::$tagsToSet, 'precondition' );

		$title = Title::newFromText( __METHOD__ );
		$vars = new AbuseFilterVariableHolder();
		$vars->setVar( 'ACTION', '' );
		$user = $this->getTestUser()->getUser();

		$iterations = 2;
		while ( $iterations-- ) {
			AbuseFilter::takeConsequenceAction(
				'tag',
				[ 'uniqueTag' ],
				$title,
				$vars,
				'',
				0,
				$user
			);

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
	 * @param AbuseFilterVariableHolder &$vars The object to use to store/retrieve variables
	 * @param WikiPage $page The page to use
	 * @return array
	 */
	private function computeExpectedEditVariable(
		$old,
		$new,
		AbuseFilterVariableHolder &$vars,
		WikiPage $page
	) {
		$popts = ParserOptions::newCanonical();
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
		$vars->setVar( 'old_wikitext', $old );
		$vars->setVar( 'new_wikitext', $new );
		$vars->setVar( 'summary', 'Testing page for AbuseFilter' );

		$computedVariables = [];
		foreach ( $variables as $var ) {
			$success = true;
			// Reset text variables since some operations are changing them.
			$oldText = $old;
			$newText = $new;
			switch ( $var ) {
				case 'edit_diff_pst':
					$newText = $vars->getVar( 'new_pst' )->toString();
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
					$diff = $vars->getVar( $diffVariable )->toString();
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
					$newHtml = $vars->getVar( 'new_html' )->toString();
					$result = StringUtils::delimiterReplace( '<', '>', '', $newHtml );
					break;
				case 'new_pst':
				case 'new_html':
					$content = ContentHandler::makeContent( $newText, $page->getTitle() );
					$editInfo = $page->prepareContentForEdit( $content );

					if ( $var === 'new_pst' ) {
						$result = $editInfo->pstContent->serialize( $editInfo->format );
					} else {
						$result = $editInfo->output->getText();
					}
					break;
				case 'all_links':
					$content = ContentHandler::makeContent( $newText, $page->getTitle() );
					$editInfo = $page->prepareContentForEdit( $content );
					$result = array_keys( $editInfo->output->getExternalLinks() );
					break;
				case 'old_links':
					$popts->setTidy( true );
					$parser = MediaWikiServices::getInstance()->getParser();
					$edit = $parser->parse( $oldText, $page->getTitle(), $popts );
					$result = array_keys( $edit->getExternalLinks() );
					break;
				case 'added_links':
				case 'removed_links':
					$oldLinks = $vars->getVar( 'old_links' )->toString();
					$newLinks = $vars->getVar( 'all_links' )->toString();
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
			$vars->setVar( $var, $result );
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
		$title = Title::makeTitle( 0, 'AbuseFilter test' );
		$page = WikiPage::factory( $title );
		$user = $this->getTestUser()->getUser();

		$page->doEditContent(
			new WikitextContent( $oldText ),
			'Creating the test page',
			EDIT_NEW,
			false,
			$user
		);
		$page->doEditContent(
			new WikitextContent( $newText ),
			'Testing page for AbuseFilter',
			EDIT_UPDATE,
			false,
			$user
		);

		$vars = new AbuseFilterVariableHolder();
		$computeResult = $this->computeExpectedEditVariable( $oldText, $newText, $vars, $page );

		$computedVariables = [];
		foreach ( $computeResult as $varName => $computed ) {
			if ( !$computed[1] ) {
				$this->fail( "Given unknown edit variable $varName." );
			}
			$computedVariables[$varName] = $computed[0];
		}

		$vars->addHolders( AbuseFilter::getEditVars( $title, $page ) );

		$actualVariables = [];
		foreach ( array_keys( $vars->mVars ) as $varName ) {
			$actualVariables[$varName] = $vars->getVar( $varName )->toNative();
		}

		$differences = [];
		foreach ( $computedVariables as $var => $computed ) {
			if ( !isset( $actualVariables[$var] ) ) {
				$this->fail( "AbuseFilter::getEditVars didn't set the $var variable." );
			} elseif ( $computed !== $actualVariables[$var] ) {
				$differences[] = $var;
			}
		}

		$this->assertEmpty(
			$differences,
			'The following AbuseFilter variables are computed wrongly: ' . implode( ', ', $differences )
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

	/**
	 * @param RevisionRecord $rev The revision being converted
	 * @param string $userGroup The group to assign to the test user
	 * @param string $expected The expected textual representation of the Revision
	 * @covers AbuseFilter::revisionToString
	 * @dataProvider provideRevisionToString
	 */
	public function testRevisionToString( $rev, $userGroup, $expected ) {
		if ( $userGroup === 'user' ) {
			$user = $this->getTestUser()->getUser();
		} else {
			$user = $this->getTestUser( $userGroup )->getUser();
		}

		$actual = AbuseFilter::revisionToString( $rev, $user );
		$this->assertSame( $expected, $actual );
	}

	/**
	 * Data provider for testRevisionToString
	 *
	 * @return Generator|array
	 */
	public function provideRevisionToString() {
		yield 'no revision' => [ null, RevisionRecord::FOR_PUBLIC, '' ];

		$title = Title::newFromText( __METHOD__ );
		$revRec = new MutableRevisionRecord( $title );
		$revRec->setContent( SlotRecord::MAIN, new TextContent( 'Main slot text.' ) );
		yield 'Revision instance' => [
			new Revision( $revRec ),
			'user',
			'Main slot text.'
		];

		yield 'RevisionRecord instance' => [
			$revRec,
			'user',
			'Main slot text.'
		];

		$revRec = new MutableRevisionRecord( $title );
		$revRec->setContent( SlotRecord::MAIN, new TextContent( 'Main slot text.' ) );
		$revRec->setContent( 'aux', new TextContent( 'Aux slot content.' ) );
		yield 'Multi-slot' => [
			$revRec,
			'user',
			"Main slot text.\n\nAux slot content."
		];

		$revRec = new MutableRevisionRecord( $title );
		$revRec->setContent( SlotRecord::MAIN, new TextContent( 'Main slot text.' ) );
		$revRec->setVisibility( RevisionRecord::DELETED_TEXT );
		yield 'Suppressed revision, unprivileged' => [
			$revRec,
			'user',
			''
		];

		yield 'Suppressed revision, privileged' => [
			$revRec,
			'sysop',
			'Main slot text.'
		];
	}

	/**
	 * Test storing and loading the var dump. See also AbuseFilterConsequencesTest::testVarDump
	 *
	 * @param array $variables Map of [ name => value ] to build an AbuseFilterVariableHolder with
	 * @covers AbuseFilter::storeVarDump
	 * @covers AbuseFilter::loadVarDump
	 * @covers AbuseFilterVariableHolder::dumpAllVars
	 * @dataProvider provideVariables
	 */
	public function testVarDump( $variables ) {
		global $wgCompressRevisions, $wgDefaultExternalStore;

		$holder = new AbuseFilterVariableHolder();
		foreach ( $variables as $name => $value ) {
			$holder->setVar( $name, $value );
		}
		if ( array_intersect_key( AbuseFilter::getDeprecatedVariables(), $variables ) ) {
			$holder->mVarsVersion = 1;
		}

		$insertID = AbuseFilter::storeVarDump( $holder );
		$dbw = wfGetDB( DB_MASTER );

		$flags = $dbw->selectField(
			'text',
			'old_flags',
			'',
			__METHOD__,
			[ 'ORDER BY' => 'old_id DESC' ]
		);
		$this->assertNotFalse( $flags, 'The var dump has not been saved.' );
		$flags = explode( ',', $flags );

		$expectedFlags = [ 'nativeDataArray' ];
		if ( $wgCompressRevisions ) {
			$expectedFlags[] = 'gzip';
		}
		if ( $wgDefaultExternalStore ) {
			$expectedFlags[] = 'external';
		}

		$this->assertEquals( $expectedFlags, $flags, 'The var dump does not have the correct flags' );

		$dump = AbuseFilter::loadVarDump( "stored-text:$insertID" );
		$this->assertEquals( $holder, $dump, 'The var dump is not saved correctly' );
	}

	/**
	 * Data provider for testVarDump
	 *
	 * @return array
	 */
	public function provideVariables() {
		return [
			'Only basic variables' => [
				[
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text'
				]
			],
			[
				[
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'user_editcount' => 15,
					'added_lines' => [ 'Foo', '', 'Bar' ]
				]
			],
			'Deprecated variables' => [
				[
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'article_articleid' => 11745,
					'article_first_contributor' => 'Good guy'
				]
			],
			[
				[
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'page_title' => 'Some title',
					'summary' => 'Fooooo'
				]
			],
			[
				[
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'all_links' => [ 'https://en.wikipedia.org' ],
					'moved_to_id' => 156,
					'moved_to_prefixedtitle' => 'MediaWiki:Foobar.js',
					'new_content_model' => CONTENT_MODEL_JAVASCRIPT
				]
			],
			[
				[
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'timestamp' => 1546000295,
					'action' => 'delete',
					'page_namespace' => 114
				]
			],
			[
				[
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'new_html' => 'Foo <small>bar</small> <s>lol</s>.',
					'new_pst' => '[[Link|link]] test {{blah}}.'
				]
			],
			'Disabled vars' => [
				[
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'old_html' => 'Foo <small>bar</small> <s>lol</s>.',
					'old_text' => 'Foobar'
				]
			]
		];
	}
}
