<?php
/**
 * Complete tests where filters are saved, actions are executed and the right
 *   consequences are expected to be taken
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
 * @group AbuseFilterConsequences
 * @group Database
 *
 * @covers AbuseFilter
 * @covers AbuseFilterHooks
 * @covers AbuseFilterParser
 * @covers AFPData
 * @covers AbuseFilterTokenizer
 * @covers AFPToken
 * @covers AbuseFilterVariableHolder
 * @covers AFComputedVariable
 */
class AbuseFilterConsequencesTest extends MediaWikiTestCase {
	protected static $mUser;

	/**
	 * @var array This tables will be deleted in parent::tearDown
	 */
	protected $tablesUsed = [
		'abuse_filter',
		'abuse_filter_action',
		'abuse_filter_history',
		'abuse_filter_log',
		'page',
		'ipblocks',
	];

	// Properties of the filter rows that we're not interested in changing.
	// Write them once to save space
	protected static $defaultRowSection = [
		'af_user_text' => 'FilterTester',
		'af_user' => 0,
		'af_timestamp' => '20180707105743',
		'af_group' => 'default',
		'af_hit_count' => 0,
	];

	// Filters that may be created, their key is the ID.
	protected static $filters = [
		1 => [
			'af_id' => 1,
			'af_pattern' => 'added_lines irlike "foo"',
			'af_enabled' => 1,
			'af_comments' => 'Comments',
			'af_public_comments' => 'Mock filter for edit',
			'af_hidden' => 0,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_actions' => 'warn,tag',
			'af_global' => 0,
			'actions' => [
				'warn' => [
					'abusefilter-my-warning'
				],
				'tag' => [
					'filtertag'
				]
			]
		],
		2 => [
			'af_id' => 2,
			'af_pattern' => 'action = "move" & moved_to_title contains "test" & moved_to_title === moved_to_text',
			'af_enabled' => 1,
			'af_comments' => 'No comment',
			'af_public_comments' => 'Mock filter for move',
			'af_hidden' => 1,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_actions' => 'disallow,block',
			'af_global' => 0,
			'actions' => [
				'disallow' => [],
				'block' => [
					'blocktalk',
					'8 hours',
					'infinity'
				]
			]
		],
		3 => [
			'af_id' => 3,
			'af_pattern' => 'action = "delete" & "test" in lcase(page_prefixedtitle) & page_prefixedtitle === article_prefixedtext',
			'af_enabled' => 1,
			'af_comments' => '',
			'af_public_comments' => 'Mock filter for delete',
			'af_hidden' => 0,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_actions' => 'degroup',
			'af_global' => 0,
			'actions' => [
				'degroup' => []
			]
		],
		4 => [
			'af_id' => 4,
			'af_pattern' => 'action contains "createaccount" & accountname rlike "user" & page_title === article_text',
			'af_enabled' => 1,
			'af_comments' => '1',
			'af_public_comments' => 'Mock filter for createaccount',
			'af_hidden' => 1,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_actions' => '',
			'af_global' => 0,
			'actions' => []
		],
		5 => [
			'af_id' => 5,
			'af_pattern' => 'user_name == "FilteredUser"',
			'af_enabled' => 1,
			'af_comments' => '',
			'af_public_comments' => 'Mock filter',
			'af_hidden' => 0,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_actions' => 'tag',
			'af_global' => 0,
			'actions' => [
				'tag' => [
					'firstTag',
					'secondTag'
				]
			]
		],
		6 => [
			'af_id' => 6,
			'af_pattern' => 'edit_delta === 7',
			'af_enabled' => 1,
			'af_comments' => '',
			'af_public_comments' => 'Mock filter with edit_delta',
			'af_hidden' => 1,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_actions' => 'disallow',
			'af_global' => 0,
			'actions' => [
				'disallow' => []
			]
		],
		7 => [
			'af_id' => 7,
			'af_pattern' => 'timestamp === int(timestamp)',
			'af_enabled' => 1,
			'af_comments' => '',
			'af_public_comments' => 'Mock filter with timestamp',
			'af_hidden' => 0,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_actions' => 'degroup',
			'af_global' => 0,
			'actions' => [
				'degroup' => []
			]
		],
		8 => [
			'af_id' => 8,
			'af_pattern' => 'added_lines_pst irlike "\\[\\[Link\\|Link\\]\\]"',
			'af_enabled' => 1,
			'af_comments' => '',
			'af_public_comments' => 'Mock filter with pst',
			'af_hidden' => 0,
			'af_hit_count' => 0,
			'af_deleted' => 0,
			'af_actions' => 'disallow,block',
			'af_global' => 0,
			'actions' => [
				'disallow' => [],
				'block' => [
					'NoTalkBlockSet',
					'4 hours',
					'4 hours'
				]
			]
		],
		9 => [
			'af_id' => 9,
			'af_pattern' => 'new_size > old_size',
			'af_enabled' => 1,
			'af_comments' => '',
			'af_public_comments' => 'Mock filter with size',
			'af_hidden' => 1,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_actions' => 'disallow,block',
			'af_global' => 0,
			'actions' => [
				'disallow' => [],
				'block' => [
					'blocktalk',
					'3 hours',
					'3 hours'
				]
			]
		],
		10 => [
			'af_id' => 10,
			'af_pattern' => '1 == 1',
			'af_enabled' => 1,
			'af_comments' => '',
			'af_public_comments' => 'Mock throttled filter',
			'af_hidden' => 1,
			'af_throttled' => 1,
			'af_deleted' => 0,
			'af_actions' => 'tag,block',
			'af_global' => 0,
			'actions' => [
				'tag' => [
					'testTag'
				],
				'block' => [
					'blocktalk',
					'infinity',
					'infinity'
				]
			]
		],
		11 => [
			'af_id' => 11,
			'af_pattern' => '1 == 1',
			'af_enabled' => 1,
			'af_comments' => '',
			'af_public_comments' => 'Mock filter which throttles',
			'af_hidden' => 0,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_actions' => 'throttle,disallow',
			'af_global' => 0,
			'actions' => [
				'throttle' => [
					11,
					'1,3600',
					'user'
				],
				'disallow' => []
			]
		],
		12 => [
			'af_id' => 12,
			'af_pattern' => 'page_title == user_name & user_name === page_title',
			'af_enabled' => 1,
			'af_comments' => '',
			'af_public_comments' => 'Mock filter for userpage',
			'af_hidden' => 0,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_actions' => 'disallow,block,degroup',
			'af_global' => 0,
			'actions' => [
				'disallow' => [],
				'block' => [
					'blocktalk',
					'8 hours',
					'1 day'
				],
				'degroup' => []
			]
		],
		13 => [
			'af_id' => 13,
			'af_pattern' => '2 == 2',
			'af_enabled' => 1,
			'af_comments' => '',
			'af_public_comments' => 'Another throttled mock filter',
			'af_hidden' => 0,
			'af_throttled' => 1,
			'af_deleted' => 0,
			'af_actions' => 'block,degroup',
			'af_global' => 0,
			'actions' => [
				'block' => [
					'blocktalk',
					'8 hours',
					'1 day'
				],
				'degroup' => []
			]
		],
		14 => [
			'af_id' => 14,
			'af_pattern' => '5/int(article_text) == 3',
			'af_enabled' => 1,
			'af_comments' => '',
			'af_public_comments' => 'Filter with a possible division by zero',
			'af_hidden' => 0,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_actions' => 'disallow',
			'af_global' => 0,
			'actions' => [
				'disallow' => []
			]
		]
	];

	/**
	 * @see MediaWikiTestCase::setUp
	 */
	protected function setUp() {
		parent::setUp();
		$user = User::newFromName( 'FilteredUser' );
		$user->addToDatabase();
		$user->addGroup( 'sysop' );
		if ( $user->isBlocked() ) {
			$block = Block::newFromTarget( $user );
			$block->delete();
		}
		self::$mUser = $user;
		// Make sure that the config we're using is the one we're expecting
		$this->setMwGlobals( [
			'wgUser' => $user,
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
			'wgAbuseFilterRuntimeProfile' => true,
			'wgAbuseFilterProfile' => true
		] );
	}

	/**
	 * Performs an edit. Freely adapted from EditPageTest::assertEdit
	 *
	 * @param Title $title Title of the page to edit
	 * @param string $oldText Old content of the page
	 * @param string $newText The new content of the page
	 * @param string $summary The summary of the edit
	 * @return Status
	 */
	private static function doEdit( $title, $oldText, $newText, $summary ) {
		$page = WikiPage::factory( $title );
		$content = ContentHandler::makeContent( $oldText, $title );
		$page->doEditContent( $content, 'Creating the page for testing AbuseFilter.' );

		$params = [
			'wpTextbox1' => $newText,
			'wpSummary' => $summary,
			'wpEditToken' => self::$mUser->getEditToken(),
			'wpEdittime' => $page->getTimestamp(),
			'wpStarttime' => wfTimestampNow(),
			'wpUnicodeCheck' => EditPage::UNICODE_CHECK,
			'wpSectionTitle' => '',
			'wpMinorEdit' => false,
			'wpWatchthis' => false
		];
		$req = new FauxRequest( $params, true );

		$article = new Article( $title );
		$article->getContext()->setTitle( $title );
		$article->getContext()->setUser( self::$mUser );
		$ep = new EditPage( $article );
		$ep->setContextTitle( $title );
		$ep->importFormData( $req );
		return $ep->internalAttemptSave( $result );
	}

	/**
	 * Executes an action to filter
	 *
	 * @param array $params Parameters of the action
	 * @param array $options Further options
	 * @return Status|Status[]
	 */
	private static function doAction( $params, $options ) {
		$type = array_shift( $params );
		$target = array_shift( $params );
		$target = Title::newFromText( $target );
		// Make sure that previous blocks don't affect the test
		self::$mUser->clearInstanceCache();

		switch ( $type ) {
			case 'edit':
				if ( in_array( 'makeGoodEditFirst', $options ) ) {
					$firstStatus = self::doEdit(
						$target, $params['oldText'], $params['firstNewText'], $params['summary']
					);
					$secondStatus = self::doEdit(
						$target, $params['firstNewText'], $params['secondNewText'], $params['summary']
					);
					$status = [ $firstStatus, $secondStatus ];
				} else {
					$status = self::doEdit( $target, $params['oldText'], $params['newText'], $params['summary'] );
				}
				break;
			case 'move':
				$move = new MovePage( $target, Title::newFromText( $params['newTitle'] ) );
				$status = $move->checkPermissions( self::$mUser, 'AbuseFilter move test' );
				break;
			case 'delete':
				$page = WikiPage::factory( $target );
				$content = ContentHandler::makeContent( 'Page to be deleted in AbuseFilter test', $target );
				$page->doEditContent( $content, 'Creating the page for testing deletion AbuseFilter.' );
				$status = $page->doDeleteArticleReal( 'Testing deletion in AbuseFilter' );
				break;
			case 'createaccount':
				$user = User::newFromName( $params['username'] );
				$provider = new AbuseFilterPreAuthenticationProvider();
				$status = $provider->testForAccountCreation( $user, $user, [] );
				break;
		}

		// Clear cache since we'll need to retrieve some fresh data about the user
		// like blocks and groups later when checking expected values
		self::$mUser->clearInstanceCache();

		return $status;
	}

	/**
	 * Creates new filters with the given ids, referred to self::$filters
	 *
	 * @param int[] $ids IDs of the filters to create
	 */
	private static function createFilters( $ids ) {
		global $wgAbuseFilterActions;
		$dbw = wfGetDB( DB_MASTER );

		foreach ( $ids as $id ) {
			$filter = array_merge( self::$filters[$id], self::$defaultRowSection );
			$actions = $filter['actions'];
			unset( $filter['actions'] );

			$dbw->replace(
				'abuse_filter',
				[ 'af_id' ],
				$filter,
				__METHOD__
			);

			$actionRows = [];
			foreach ( array_filter( $wgAbuseFilterActions ) as $action => $_ ) {
				if ( isset( $actions[$action] ) ) {
					$parameters = $actions[$action];

					$thisRow = [
						'afa_filter' => $id,
						'afa_consequence' => $action,
						'afa_parameters' => implode( "\n", $parameters )
					];
					$actionsRows[] = $thisRow;
				}
			}

			$dbw->replace(
				'abuse_filter_action',
				[ 'afa_filter' ],
				$actionsRows,
				__METHOD__
			);
		}
	}

	/**
	 * Creates new filters, execute an action and check the consequences
	 *
	 * @param string $testDescription A short description of the test, used for error reporting
	 * @param int[] $createIds IDs of the filters to create
	 * @param array $actionParams Details of the action we need to execute to trigger filters
	 * @param array $consequences The consequences we're expecting
	 * @param array $options Further options for the test
	 * @covers AbuseFilter
	 * @dataProvider provideFilters
	 */
	public function testFilterConsequences(
		$testDescription,
		$createIds,
		$actionParams,
		$consequences,
		$options
	) {
		global $wgLang;
		self::createFilters( $createIds );

		if ( in_array( 'makeGoodEditFirst', $options ) ) {
			$this->setMwGlobals( [
				// Necessary to test throttle
				'wgMainCacheType' => CACHE_ANYTHING
			] );
		}
		if ( in_array( 'hitCondsLimit', $options ) ) {
			$this->setMwGlobals( [
				'wgAbuseFilterConditionLimit' => 0
			] );
		}
		if ( in_array( 'hitTimeLimit', $options ) ) {
			$this->setMwGlobals( [
				'wgAbuseFilterSlowFilterRuntimeLimit' => 0
			] );
		}
		if ( in_array( 'hitThrottleLimit', $options ) ) {
			$this->setMwGlobals( [
				'wgAbuseFilterEmergencyDisableCount' => [
					'default' => 0
				]
			] );
		}

		$result = self::doAction( $actionParams, $options );

		$expectedErrors = [];
		$testErrorMessage = false;
		foreach ( $consequences as $consequence => $ids ) {
			foreach ( $ids as $id ) {
				$params = self::$filters[$id]['actions'][$consequence];
				$success = true;
				switch ( $consequence ) {
					case 'warn':
						// Aborts the hook with the warning message as error.
						$expectedErrors['warn'][] = $params[0];
						break;
					case 'disallow':
						// Aborts the hook with 'abusefilter-disallowed' error.
						$expectedErrors['disallow'][] = 'abusefilter-disallowed';
						break;
					case 'block':
						// Aborts the hook with 'abusefilter-blocked-display' error. Should block
						// the user with expected duration and options.
						$userBlock = self::$mUser->getBlock( false );

						if ( !$userBlock ) {
							$testErrorMessage = "User isn't blocked.";
							break;
						}

							$shouldPreventTalkEdit = $params[0] === 'blocktalk';
						$edittalkCheck = $userBlock->prevents( 'editownusertalk' ) === $shouldPreventTalkEdit;
						if ( !$edittalkCheck ) {
							$testErrorMessage = 'The expected block option "edittalk" options does not ' .
								'match the actual one.';
							break;
						}

							$expectedExpiry = SpecialBlock::parseExpiryInput( $params[2] );
						// Get rid of non-numeric 'infinity' by setting it to 0
						$actualExpiry = wfIsInfinity( $userBlock->getExpiry() ) ? 0 : $userBlock->getExpiry();
						$expectedExpiry = wfIsInfinity( $expectedExpiry ) ? 0 : $expectedExpiry;
						// We need to take into account code execution time. 10 seconds should be enough
						$durationCheck = abs( strtotime( $actualExpiry ) - strtotime( $expectedExpiry ) ) < 10;
						if ( !$durationCheck ) {
							$testErrorMessage = "The expected block expiry ($expectedExpiry) does not " .
								"match the actual one ($actualExpiry).";
							break;
						}

							$expectedErrors['block'][] = 'abusefilter-blocked-display';
						break;
					case 'degroup':
						// Aborts the hook with 'abusefilter-degrouped' error and degroups the user.
						$expectedErrors['degroup'][] = 'abusefilter-degrouped';
						$groupCheck = !in_array( 'sysop', self::$mUser->getEffectiveGroups() );
						if ( !$groupCheck ) {
							$testErrorMessage = 'The user was not degrouped.';
						}
						break;
					case 'tag':
						// Only add tags, to be retrieved in tag_summary table.
						if ( $actionParams[1] === null ) {
							// It's an account creation, so no tags.
							break;
						}
						$title = Title::newFromText( $actionParams[1] );
						$page = WikiPage::factory( $title );
						$revId = $page->getLatest();
						$dbr = wfGetDB( DB_REPLICA );
						$appliedTags = $dbr->selectField(
							'tag_summary',
							'ts_tags',
							[ 'ts_rev_id' => $revId ],
							__METHOD__
						);
						$appliedTags = explode( ',', $appliedTags );

							$tagCheck = count( array_diff( $params, $appliedTags ) ) === 0;
						if ( !$tagCheck ) {
							$expectedTags = $wgLang->commaList( $params );
							$actualTags = $wgLang->commaList( $appliedTags );

								$testErrorMessage = "Expected the edit to have the following tags: $expectedTags. " .
								"Got the following instead: $actualTags.";
						}
						break;
					case 'throttle':
						// The action was executed twice and $result is an array of two Status objects.
						if ( !$result[0]->isGood() ) {
							// The first one should be fine
							$testErrorMessage = "The first edit should have been saved, being only throttled.";
							break;
						}

							$result = $result[1];
						break;
				}

				if ( $testErrorMessage ) {
					$this->fail( "$testErrorMessage Test description: $testDescription" );
				}
			}
		}

		if ( in_array( 'hitThrottleLimit', $options ) ) {
			$dbr = wfGetDB( DB_REPLICA );
			$throttled = true;
			foreach ( $createIds as $filter ) {
				$curThrottle = $dbr->selectField(
					'abuse_filter',
					'af_throttled',
					[ 'af_id' => $filter ],
					__METHOD__
				);
				$throttled &= $curThrottle;
			}

			if ( !$throttled ) {
				$expectedThrottled = $wgLang->commaList( $createIds );
				$this->fail( 'Expected the following filters to be automatically ' .
					"throttled: $expectedThrottled." );
			}
		}

		// Errors have a priority order
		$expected = $expectedErrors['warn'] ?? $expectedErrors['degroup'] ??
			$expectedErrors['block'] ?? $expectedErrors['disallow'] ?? null;
		if ( isset( $expectedErrors['degroup'] ) && $expected === $expectedErrors['degroup'] &&
			isset( $expectedErrors['block'] ) ) {
			// Degroup and block warning can be fired together
			$expected = array_merge( $expectedErrors['degroup'], $expectedErrors['block'] );
		} elseif ( !is_array( $expected ) ) {
			$expected = (array)$expected;
		}

		$errors = $result->getErrors();

		$actual = [];
		foreach ( $errors as $error ) {
			$msg = $error['message'];
			if ( strpos( $msg, 'abusefilter' ) !== false ) {
				$actual[] = $msg;
			}
		}

		$expectedDisplay = $wgLang->commaList( $expected );
		$actualDisplay = $wgLang->commaList( $actual );

		$this->assertEquals(
			$expected,
			$actual,
			"The edit should have returned the following error messages: $expectedDisplay. " .
				"Got $actualDisplay instead. Test description: $testDescription"
		);
	}

	/**
	 * Data provider for creating and editing filters. For every test case, we pass
	 *   - an array with the IDs of the filters to be created (listed in self::$filters),
	 *   - an array with details of the action to execute in order to trigger the filters,
	 *   - an array of expected consequences of the form
	 *       [ 'consequence name' => [ IDs of the filter to take its parameters from ] ]
	 *       Such IDs may be more than one if we have a warning that is shown twice.
	 *   - an array with further options for testing
	 *
	 * @return array
	 */
	public function provideFilters() {
		return [
			[
				'Basic test for "edit" action.',
				[ 1, 2 ],
				[
					'edit',
					'Test page',
					'oldText' => 'Some old text for the test.',
					'newText' => 'I like foo',
					'summary' => 'Test AbuseFilter for edit action.'
				],
				[ 'warn'  => [ 1 ] ],
				[]
			],
			[
				'Basic test for "move" action.',
				[ 2 ],
				[
					'move',
					'Test page',
					'newTitle' => 'Another test page'
				],
				[ 'disallow'  => [ 2 ], 'block' => [ 2 ] ],
				[]
			],
			[
				'Basic test for "delete" action.',
				[ 2, 3 ],
				[
					'delete',
					'Test page'
				],
				[ 'degroup' => [ 3 ] ],
				[]
			],
			[
				'Basic test for "createaccount" action.',
				[ 1, 2, 3, 4 ],
				[
					'createaccount',
					null,
					'username' => 'AnotherUser'
				],
				[],
				[]
			],
			[
				'Test to check that all tags are applied.',
				[ 5 ],
				[
					'edit',
					'User:FilteredUser',
					'oldText' => 'Hey.',
					'newText' => 'I am a very nice user, really!',
					'summary' => ''
				],
				[ 'tag' => [ 5 ] ],
				[]
			],
			[
				'Test to check that the edit is disallowed.',
				[ 6 ],
				[
					'edit',
					'Help:Help',
					'oldText' => 'Some help.',
					'newText' => 'Some help for you',
					'summary' => 'Help! I need somebody'
				],
				[ 'disallow' => [ 6 ] ],
				[]
			],
			[
				'Test to check that degroup and block are executed together.',
				[ 2, 3, 7, 8 ],
				[
					'edit',
					'Link',
					'oldText' => 'What is a link?',
					'newText' => 'A link is something like this: [[Link|]].',
					'summary' => 'Explaining'
				],
				[ 'degroup' => [ 7 ], 'block' => [ 8 ] ],
				[]
			],
			[
				'Test to check that the block duration is the longest one.',
				[ 8, 9 ],
				[
					'edit',
					'Whatever',
					'oldText' => 'Whatever is whatever',
					'newText' => 'Whatever is whatever, whatever it is. BTW, here is a [[Link|]]',
					'summary' => 'Whatever'
				],
				[ 'disallow' => [ 8 ], 'block' => [ 8 ] ],
				[]
			],
			[
				'Test to check that throttled filters only execute "safe" actions.',
				[ 10 ],
				[
					'edit',
					'Buffalo',
					'oldText' => 'Buffalo',
					'newText' => 'Buffalo buffalo Buffalo buffalo buffalo buffalo Buffalo buffalo.',
					'summary' => 'Buffalo!'
				],
				[ 'tag' => [ 10 ] ],
				[]
			],
			[
				'Test to see that throttling works well.',
				[ 11 ],
				[
					'edit',
					'Throttle',
					'oldText' => 'What is throttle?',
					'firstNewText' => 'Throttle is something that should happen...',
					'secondNewText' => '... Right now!',
					'summary' => 'Throttle'
				],
				[ 'throttle' => [ 11 ], 'disallow' => [ 11 ] ],
				[ 'makeGoodEditFirst' ]
			],
			[
				'Test to check that degroup and block are both executed and degroup warning is shown twice.',
				[ 1, 3, 7, 12 ],
				[
					'edit',
					'User:FilteredUser',
					'oldText' => '',
					'newText' => 'A couple of lines about me...',
					'summary' => 'My user page'
				],
				[ 'block' => [ 12 ], 'degroup' => [ 7, 12 ] ],
				[]
			],
			[
				'Test to check that every throttled filter only executes "safe" actions.',
				[ 10, 13 ],
				[
					'edit',
					'Tyger! Tyger! Burning bright',
					'oldText' => 'In the forests of the night',
					'newText' => 'What immortal hand or eye',
					'summary' => 'Could frame thy fearful symmetry?'
				],
				[ 'tag' => [ 10 ] ],
				[]
			],
			[
				'Test to check that runtime exceptions (division by zero) are correctly handled.',
				[ 14 ],
				[
					'edit',
					'0',
					'oldText' => 'Old text',
					'newText' => 'New text',
					'summary' => 'Some summary'
				],
				[],
				[]
			],
			[
				'Test to check that the conditions limit works.',
				[ 8, 10 ],
				[
					'edit',
					'Anything',
					'oldText' => 'Bar',
					'newText' => 'Foo',
					'summary' => ''
				],
				[],
				[ 'hitCondsLimit' ]
			],
			[
				'Test slow executions.',
				[ 7, 12 ],
				[
					'edit',
					'Something',
					'oldText' => 'Please allow me',
					'newText' => 'to introduce myself',
					'summary' => ''
				],
				[ 'degroup' => [ 7 ] ],
				[ 'hitTimeLimit' ]
			],
			[
				'Test throttling a dangerous filter.',
				[ 13 ],
				[
					'edit',
					'My page',
					'oldText' => '',
					'newText' => 'AbuseFilter will not block me',
					'summary' => ''
				],
				[],
				[ 'hitThrottleLimit' ]
			],
		];
	}
}
