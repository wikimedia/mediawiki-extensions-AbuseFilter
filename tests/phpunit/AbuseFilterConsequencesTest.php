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

use MediaWiki\MediaWikiServices;
use MediaWiki\Storage\NameTableAccessException;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterConsequences
 * @group Database
 * @group Large
 *
 * @covers AbuseFilter
 * @covers AbuseFilterHooks
 * @covers AbuseFilterPreAuthenticationProvider
 * @todo Add upload actions everywhere
 */
class AbuseFilterConsequencesTest extends MediaWikiTestCase {
	/** @var User The user performing actions */
	private static $mUser;

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
		'logging',
		'change_tag',
		'user'
	];

	// phpcs:disable Generic.Files.LineLength
	// Filters that may be created, their key is the ID.
	protected static $filters = [
		1 => [
			'af_pattern' => 'added_lines irlike "foo"',
			'af_public_comments' => 'Mock filter for edit',
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
			'af_pattern' => 'action = "move" & moved_to_title contains "test" & moved_to_title === moved_to_text',
			'af_public_comments' => 'Mock filter for move',
			'af_hidden' => 1,
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
			'af_pattern' => 'action = "delete" & "test" in lcase(page_prefixedtitle) & page_prefixedtitle === article_prefixedtext',
			'af_public_comments' => 'Mock filter for delete',
			'af_global' => 1,
			'actions' => [
				'degroup' => []
			]
		],
		4 => [
			'af_pattern' => 'action contains "createaccount" & accountname rlike "user" & page_title === article_text',
			'af_public_comments' => 'Mock filter for createaccount',
			'af_hidden' => 1,
			'actions' => []
		],
		5 => [
			'af_pattern' => 'user_name == "FilteredUser"',
			'af_public_comments' => 'Mock filter',
			'actions' => [
				'tag' => [
					'firstTag',
					'secondTag'
				]
			]
		],
		6 => [
			'af_pattern' => 'edit_delta === 7',
			'af_public_comments' => 'Mock filter with edit_delta',
			'af_hidden' => 1,
			'af_global' => 1,
			'actions' => [
				'disallow' => [
					'abusefilter-disallowed-really'
				]
			]
		],
		7 => [
			'af_pattern' => 'timestamp === int(timestamp)',
			'af_public_comments' => 'Mock filter with timestamp',
			'actions' => [
				'degroup' => []
			]
		],
		8 => [
			'af_pattern' => 'added_lines_pst irlike "\\[\\[Link\\|Link\\]\\]"',
			'af_public_comments' => 'Mock filter with pst',
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
			'af_pattern' => 'new_size > old_size',
			'af_public_comments' => 'Mock filter with size',
			'af_hidden' => 1,
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
			'af_pattern' => '1 == 1',
			'af_public_comments' => 'Mock throttled filter',
			'af_hidden' => 1,
			'af_throttled' => 1,
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
			'af_pattern' => '1 == 1',
			'af_public_comments' => 'Catch-all filter which throttles',
			'actions' => [
				'throttle' => [
					11,
					'1,3600',
					'site'
				],
				'disallow' => []
			]
		],
		12 => [
			'af_pattern' => 'page_title == user_name & user_name === page_title',
			'af_public_comments' => 'Mock filter for userpage',
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
			'af_pattern' => '2 == 2',
			'af_public_comments' => 'Another throttled mock filter',
			'af_throttled' => 1,
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
			'af_pattern' => '5/int(article_text) == 3',
			'af_public_comments' => 'Filter with a possible division by zero',
			'actions' => [
				'disallow' => []
			]
		],
		15 => [
			'af_pattern' => 'action contains "createaccount"',
			'af_public_comments' => 'Catch-all for account creations',
			'af_hidden' => 1,
			'actions' => [
				'disallow' => []
			]
		]
	];
	// phpcs:enable Generic.Files.LineLength

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
			// Exclude noisy creation log
			'wgPageCreationLog' => false,
			'wgAbuseFilterActions' => [
				'throttle' => true,
				'warn' => true,
				'disallow' => true,
				'blockautopromote' => true,
				'block' => true,
				'rangeblock' => true,
				'degroup' => true,
				'tag' => true
			]
		] );
	}

	/**
	 * Creates new filters with the given ids, referred to self::$filters
	 *
	 * @param int[] $ids IDs of the filters to create
	 */
	private static function createFilters( $ids ) {
		global $wgAbuseFilterActions;
		$dbw = wfGetDB( DB_MASTER );
		$defaultRowSection = [
			'af_user_text' => 'FilterTester',
			'af_user' => 0,
			'af_timestamp' => $dbw->timestamp(),
			'af_group' => 'default',
			'af_comments' => '',
			'af_hit_count' => 0,
			'af_enabled' => 1,
			'af_hidden' => 0,
			'af_throttled' => 0,
			'af_deleted' => 0,
			'af_global' => 0
		];

		foreach ( $ids as $id ) {
			$filter = self::$filters[$id] + $defaultRowSection;
			$actions = $filter['actions'];
			unset( $filter['actions'] );
			$filter[ 'af_actions' ] = implode( ',', array_keys( $actions ) );
			$filter[ 'af_id' ] = $id;

			$dbw->insert(
				'abuse_filter',
				$filter,
				__METHOD__
			);

			$actionsRows = [];
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

			$dbw->insert(
				'abuse_filter_action',
				$actionsRows,
				__METHOD__
			);
		}
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
	private function doEdit( $title, $oldText, $newText, $summary ) {
		$page = WikiPage::factory( $title );
		if ( !$page->exists() ) {
			$content = ContentHandler::makeContent( $oldText, $title );
			$page->doEditContent( $content, 'Creating the page for testing AbuseFilter.' );
		}

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
		$result = [];
		return $ep->internalAttemptSave( $result );
	}

	/**
	 * Executes an action to filter
	 *
	 * @param array $params Parameters of the action
	 * @return Status
	 */
	private function doAction( $params ) {
		$target = Title::newFromText( $params['target'] );
		// Make sure that previous blocks don't affect the test
		self::$mUser->clearInstanceCache();
		if ( $params['action'] === 'move' || $params['action'] === 'delete' ) {
			// For these actions, the page has to exist.
			$page = WikiPage::factory( $target );
			if ( !$page->exists() ) {
				$content = ContentHandler::makeContent(
					'AbuseFilter test page for action' . $params['action'],
					$target
				);
				$check = $page->doEditContent( $content, 'Creating the page to test AbuseFilter.' );
				if ( !$check->isGood() ) {
					throw new MWException( 'Cannot create the test page.' );
				}
			}
		}

		switch ( $params['action'] ) {
			case 'edit':
				$status = $this->doEdit( $target, $params['oldText'], $params['newText'], $params['summary'] );
				break;
			case 'move':
				$mp = new MovePage( $target, Title::newFromText( $params['newTitle'] ) );
				$status = $mp->move( self::$mUser, 'AbuseFilter move test', false );
				break;
			case 'delete':
				$status = $page->doDeleteArticleReal( 'Testing deletion in AbuseFilter' );
				break;
			case 'createaccount':
				$user = User::newFromName( $params['username'] );
				$provider = new AbuseFilterPreAuthenticationProvider();
				$status = $provider->testForAccountCreation( $user, $user, [] );

				// A creatable username must exist to be passed to $logEntry->setPerformer(),
				// so create the account.
				$user->addToDatabase();

				$logEntry = new \ManualLogEntry( 'newusers', 'create' );
				$logEntry->setPerformer( $user );
				$logEntry->setTarget( $user->getUserPage() );
				$logid = $logEntry->insert();
				$logEntry->publish( $logid );
				break;
			default:
				throw new UnexpectedValueException( 'Unrecognized action.' );
		}

		// Clear cache since we'll need to retrieve some fresh data about the user
		// like blocks and groups later when checking expected values
		self::$mUser->clearInstanceCache();

		return $status;
	}

	/**
	 * @param array[] $actionsParams Arrays of parameters for every action
	 * @return Status[]
	 */
	private function doActions( $actionsParams ) {
		$ret = [];
		foreach ( $actionsParams as $params ) {
			$ret[] = $this->doAction( $params );
		}
		return $ret;
	}

	/**
	 * Helper function to retrieve change tags applied to an edit or log entry
	 *
	 * @param array $actionParams As given by the data provider
	 * @return string[] The applied tags
	 * @fixme This method is pretty hacky. A clean alternative from core would be nice.
	 */
	private function getActionTags( $actionParams ) {
		if ( $actionParams['action'] === 'edit' ) {
			$page = WikiPage::factory( Title::newFromText( $actionParams['target'] ) );
			$where = [ 'ct_rev_id' => $page->getLatest() ];
		} else {
			$logType = $actionParams['action'] === 'createaccount' ? 'newusers' : $actionParams['action'];
			$logAction = $logType === 'newusers' ? 'create' : $logType;
			$title = Title::newFromText( $actionParams['target'] );
			$id = $this->db->selectField(
				'logging',
				'log_id',
				[
					'log_title' => $title->getDBkey(),
					'log_type' => $logType,
					'log_action' => $logAction
				],
				__METHOD__,
				[],
				[ 'ORDER BY' => 'log_id DESC' ]
			);
			if ( !$id ) {
				$this->fail( 'Could not find the action in the logging table.' );
			}
			$where = [ 'ct_log_id' => $id ];
		}

		$changeTagDefStore = MediaWikiServices::getInstance()->getChangeTagDefStore();
		$tagIds = $this->db->selectFieldValues(
			'change_tag',
			'ct_tag_id',
			$where,
			__METHOD__
		);
		$appliedTags = [];
		foreach ( $tagIds as $tagId ) {
			try {
				$appliedTags[] = $changeTagDefStore->getName( (int)$tagId );
			} catch ( NameTableAccessException $exception ) {
				continue;
			}
		}

		return $appliedTags;
	}

	/**
	 * Checks that consequences are effectively taken and builds an array of expected and actual
	 * consequences which can be compared.
	 *
	 * @param Status $result As returned by self::doAction
	 * @param array $actionParams As it's given by data providers
	 * @param array $consequences As it's given by data providers
	 * @return array [ expected consequences, actual consequences ]
	 */
	private function checkConsequences( $result, $actionParams, $consequences ) {
		$expectedErrors = [];
		$testErrorMessage = false;
		foreach ( $consequences as $consequence => $ids ) {
			foreach ( $ids as $id ) {
				$params = self::$filters[$id]['actions'][$consequence];
				switch ( $consequence ) {
					case 'warn':
						// Aborts the hook with the warning message as error.
						$expectedErrors['warn'][] = $params[0] ?? 'abusefilter-warning';
						break;
					case 'disallow':
						// Aborts the hook with the disallow message error.
						$expectedErrors['disallow'][] = $params[0] ?? 'abusefilter-disallowed';
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
						$edittalkCheck = $userBlock->appliesToUsertalk( self::$mUser->getTalkPage() ) ===
							$shouldPreventTalkEdit;
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
						// Only add tags, to be retrieved in change_tag table.
						$appliedTags = $this->getActionTags( $actionParams );
						$tagCheck = count( array_diff( $params, $appliedTags ) ) === 0;
						if ( !$tagCheck ) {
							$expectedTags = implode( ', ', $params );
							$actualTags = implode( ', ', $appliedTags );

							$testErrorMessage = "Expected the action to have the following tags: $expectedTags. " .
								"Got the following instead: $actualTags.";
						}
						break;
					case 'throttle':
						throw new UnexpectedValueException( 'Use self::testThrottleConsequence to test throttling' );
					default:
						throw new UnexpectedValueException( 'Consequence not recognized.' );
				}

				if ( $testErrorMessage ) {
					$this->fail( $testErrorMessage );
				}
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

		return [ $expected, $actual ];
	}

	/**
	 * Creates new filters, execute an action and check the consequences
	 *
	 * @param int[] $createIds IDs of the filters to create
	 * @param array $actionParams Details of the action we need to execute to trigger filters
	 * @param array $consequences The consequences we're expecting
	 * @dataProvider provideFilters
	 */
	public function testFilterConsequences( $createIds, $actionParams, $consequences ) {
		self::createFilters( $createIds );
		$result = $this->doAction( $actionParams );
		list( $expected, $actual ) = $this->checkConsequences( $result, $actionParams, $consequences );

		$expectedDisplay = implode( ', ', $expected );
		$actualDisplay = implode( ', ', $actual );

		$this->assertEquals(
			$expected,
			$actual,
			"The action should have returned the following error messages: $expectedDisplay. " .
			"Got $actualDisplay instead."
		);
	}

	/**
	 * Data provider for testFilterConsequences. For every test case, we pass
	 *   - an array with the IDs of the filters to be created (listed in self::$filters),
	 *   - an array with details of the action to execute in order to trigger the filters,
	 *   - an array of expected consequences of the form
	 *       [ 'consequence name' => [ IDs of the filter to take its parameters from ] ]
	 *       Such IDs may be more than one if we have a warning that is shown twice.
	 *
	 * @return array
	 */
	public function provideFilters() {
		return [
			'Basic test for "edit" action' => [
				[ 1, 2 ],
				[
					'action' => 'edit',
					'target' => 'Test page',
					'oldText' => 'Some old text for the test.',
					'newText' => 'I like foo',
					'summary' => 'Test AbuseFilter for edit action.'
				],
				[ 'warn'  => [ 1 ] ]
			],
			'Basic test for "move" action' => [
				[ 2 ],
				[
					'action' => 'move',
					'target' => 'Test page',
					'newTitle' => 'Another test page'
				],
				[ 'disallow'  => [ 2 ], 'block' => [ 2 ] ]
			],
			'Basic test for "delete" action' => [
				[ 2, 3 ],
				[
					'action' => 'delete',
					'target' => 'Test page'
				],
				[ 'degroup' => [ 3 ] ]
			],
			'Basic test for "createaccount", no consequences.' => [
				[ 1, 2, 3, 4 ],
				[
					'action' => 'createaccount',
					'target' => 'User:AnotherUser',
					'username' => 'AnotherUser'
				],
				[]
			],
			'Basic test for "createaccount", disallowed.' => [
				[ 15 ],
				[
					'action' => 'createaccount',
					'target' => 'User:AnotherUser',
					'username' => 'AnotherUser'
				],
				[ 'disallow' => [ 15 ] ]
			],
			'Check that all tags are applied' => [
				[ 5 ],
				[
					'action' => 'edit',
					'target' => 'User:FilteredUser',
					'oldText' => 'Hey.',
					'newText' => 'I am a very nice user, really!',
					'summary' => ''
				],
				[ 'tag' => [ 5 ] ]
			],
			[
				[ 6 ],
				[
					'action' => 'edit',
					'target' => 'Help:Help',
					'oldText' => 'Some help.',
					'newText' => 'Some help for you',
					'summary' => 'Help! I need somebody'
				],
				[ 'disallow' => [ 6 ] ]
			],
			'Check that degroup and block are executed together' => [
				[ 2, 3, 7, 8 ],
				[
					'action' => 'edit',
					'target' => 'Link',
					'oldText' => 'What is a link?',
					'newText' => 'A link is something like this: [[Link|]].',
					'summary' => 'Explaining'
				],
				[ 'degroup' => [ 7 ], 'block' => [ 8 ] ]
			],
			'Check that the block duration is the longer one' => [
				[ 8, 9 ],
				[
					'action' => 'edit',
					'target' => 'Whatever',
					'oldText' => 'Whatever is whatever',
					'newText' => 'Whatever is whatever, whatever it is. BTW, here is a [[Link|]]',
					'summary' => 'Whatever'
				],
				[ 'disallow' => [ 8 ], 'block' => [ 8 ] ]
			],
			'Check that throttled filters only execute "safe" actions' => [
				[ 10 ],
				[
					'action' => 'edit',
					'target' => 'Buffalo',
					'oldText' => 'Buffalo',
					'newText' => 'Buffalo buffalo Buffalo buffalo buffalo buffalo Buffalo buffalo.',
					'summary' => 'Buffalo!'
				],
				[ 'tag' => [ 10 ] ]
			],
			'Check that degroup and block are both executed and degroup warning is shown twice' => [
				[ 1, 3, 7, 12 ],
				[
					'action' => 'edit',
					'target' => 'User:FilteredUser',
					'oldText' => '',
					'newText' => 'A couple of lines about me...',
					'summary' => 'My user page'
				],
				[ 'block' => [ 12 ], 'degroup' => [ 7, 12 ] ]
			],
			'Check that every throttled filter only executes "safe" actions' => [
				[ 10, 13 ],
				[
					'action' => 'edit',
					'target' => 'Tyger! Tyger! Burning bright',
					'oldText' => 'In the forests of the night',
					'newText' => 'What immortal hand or eye',
					'summary' => 'Could frame thy fearful symmetry?'
				],
				[ 'tag' => [ 10 ] ]
			],
			'Check that runtime exceptions (division by zero) are correctly handled' => [
				[ 14 ],
				[
					'action' => 'edit',
					'target' => '0',
					'oldText' => 'Old text',
					'newText' => 'New text',
					'summary' => 'Some summary'
				],
				[]
			],
			[
				[ 8, 10 ],
				[
					'action' => 'edit',
					'target' => 'Anything',
					'oldText' => 'Bar',
					'newText' => 'Foo',
					'summary' => ''
				],
				[]
			],
			[
				[ 7, 12 ],
				[
					'action' => 'edit',
					'target' => 'Something',
					'oldText' => 'Please allow me',
					'newText' => 'to introduce myself',
					'summary' => ''
				],
				[ 'degroup' => [ 7 ] ]
			],
			[
				[ 13 ],
				[
					'action' => 'edit',
					'target' => 'My page',
					'oldText' => '',
					'newText' => 'AbuseFilter will not block me',
					'summary' => ''
				],
				[]
			],
		];
	}

	/**
	 * Check that hitting the conditions limit stops the execution, and thus no actions are taken.
	 *
	 * @param int[] $createIds IDs of the filters to create
	 * @param array $actionParams Details of the action we need to execute to trigger filters
	 * @covers AbuseFilter::triggerLimiter
	 * @covers AbuseFilter::checkAllFilters
	 * @dataProvider provideFiltersNoConsequences
	 */
	public function testCondsLimit( $createIds, $actionParams ) {
		self::createFilters( $createIds );
		$this->setMwGlobals( [ 'wgAbuseFilterConditionLimit' => 0 ] );
		$res = $this->doAction( $actionParams );

		$this->assertTrue( $res->isGood(), 'The action should succeed when testing the conds limit' );
		$appliedTags = $this->getActionTags( $actionParams );
		$this->assertContains(
			'abusefilter-condition-limit',
			$appliedTags,
			"The action wasn't tagged with 'abusefilter-condition-limit' upon hitting the limit"
		);
	}

	/**
	 * Check that hitting the time limit is logged
	 *
	 * @param int[] $createIds IDs of the filters to create
	 * @param array $actionParams Details of the action we need to execute to trigger filters
	 * @covers AbuseFilter::checkFilter
	 * @covers AbuseFilter::recordSlowFilter
	 * @dataProvider provideFiltersNoConsequences
	 */
	public function testTimeLimit( $createIds, $actionParams ) {
		$loggerMock = new TestLogger();
		$loggerMock->setCollect( true );
		$this->setLogger( 'AbuseFilter', $loggerMock );
		$this->setMwGlobals( [ 'wgAbuseFilterSlowFilterRuntimeLimit' => -1 ] );

		self::createFilters( $createIds );
		// We don't care about consequences here
		$this->doAction( $actionParams );

		// Ensure slow filters are logged
		$loggerBuffer = $loggerMock->getBuffer();
		$found = false;
		foreach ( $loggerBuffer as $entry ) {
			$check = preg_match( '/Edit filter [^ ]+ on [^ ]+ is taking longer than expected/', $entry[1] );
			if ( $check ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'The time limit hit was not logged.' );
	}

	/**
	 * Similar to self::provideFilters, but for tests where we don't care about consequences.
	 *
	 * @return array
	 */
	public function provideFiltersNoConsequences() {
		return [
			[
				[ 1, 2 ],
				[
					'action' => 'edit',
					'target' => 'Test page',
					'oldText' => 'Some old text for the test.',
					'newText' => 'I like foo',
					'summary' => 'Test AbuseFilter for edit action.'
				]
			],
			[
				[ 2 ],
				[
					'action' => 'move',
					'target' => 'Test page',
					'newTitle' => 'Another test page'
				]
			],
			[
				[ 5 ],
				[
					'action' => 'edit',
					'target' => 'User:FilteredUser',
					'oldText' => 'Hey.',
					'newText' => 'I am a very nice user, really!',
					'summary' => ''
				]
			],
			[
				[ 2, 3, 7, 8 ],
				[
					'action' => 'edit',
					'target' => 'Link',
					'oldText' => 'What is a link?',
					'newText' => 'A link is something like this: [[Link|]].',
					'summary' => 'Explaining'
				]
			],
			[
				[ 8, 10 ],
				[
					'action' => 'edit',
					'target' => 'Anything',
					'oldText' => 'Bar',
					'newText' => 'Foo',
					'summary' => ''
				]
			],
			[
				[ 2, 3 ],
				[
					'action' => 'delete',
					'target' => 'Test page'
				]
			],
			[
				[ 10, 13 ],
				[
					'action' => 'edit',
					'target' => 'Tyger! Tyger! Burning bright',
					'oldText' => 'In the forests of the night',
					'newText' => 'What immortal hand or eye',
					'summary' => 'Could frame thy fearful symmetry?'
				]
			],
			[
				[ 15 ],
				[
					'action' => 'createaccount',
					'target' => 'User:AnotherUser',
					'username' => 'AnotherUser'
				]
			]
		];
	}

	/**
	 * Check that hitting the throttle effectively updates abuse_filter.af_throttled.
	 *
	 * @param int[] $createIds IDs of the filters to create
	 * @param array $actionParams Details of the action we need to execute to trigger filters
	 * @covers AbuseFilter::checkEmergencyDisable
	 * @dataProvider provideThrottleLimitFilters
	 */
	public function testThrottleLimit( $createIds, $actionParams ) {
		self::createFilters( $createIds );
		$this->setMwGlobals( [ 'wgAbuseFilterEmergencyDisableCount' => [ 'default' => -1 ] ] );
		// We don't care about consequences here
		$this->doAction( $actionParams );

		$throttled = [];
		foreach ( $createIds as $filter ) {
			$curThrottle = $this->db->selectField(
				'abuse_filter',
				'af_throttled',
				[ 'af_id' => $filter ],
				__METHOD__
			);
			if ( $curThrottle ) {
				$throttled[] = $filter;
			}
		}

		$expectedThrottled = implode( ', ', $createIds );
		$actualThrottled = implode( ', ', $throttled );
		$this->assertEquals( $createIds, $throttled, "Expected the following filters to be " .
			"automatically throttled: $expectedThrottled. The following are throttled instead: " .
			"$actualThrottled." );
	}

	/**
	 * Data provider for testThrottleLimit. Note that using filters with af_throttled = 1 in
	 * self::$filters makes the test case useless.
	 *
	 * @return array
	 */
	public function provideThrottleLimitFilters() {
		return [
			[
				[ 1 ],
				[
					'action' => 'edit',
					'target' => 'Test page',
					'oldText' => 'Some old text for the test.',
					'newText' => 'I like foo',
					'summary' => 'Test AbuseFilter for edit action.'
				]
			],
			[
				[ 2 ],
				[
					'action' => 'move',
					'target' => 'Test page',
					'newTitle' => 'Another test page'
				]
			],
			[
				[ 5 ],
				[
					'action' => 'edit',
					'target' => 'User:FilteredUser',
					'oldText' => 'Hey.',
					'newText' => 'I am a very nice user, really!',
					'summary' => ''
				]
			],
			[
				[ 7, 8 ],
				[
					'action' => 'edit',
					'target' => 'Link',
					'oldText' => 'What is a link?',
					'newText' => 'A link is something like this: [[Link|]].',
					'summary' => 'Explaining'
				]
			],
			[
				[ 3 ],
				[
					'action' => 'delete',
					'target' => 'Test page'
				]
			],
			[
				[ 15 ],
				[
					'action' => 'createaccount',
					'target' => 'User:AnotherUser',
					'username' => 'AnotherUser'
				]
			]
		];
	}

	/**
	 * Check an array of results from self::doAction to ensure that all but the last actions have been
	 *   executed (i.e. no errors).
	 * @param Status[] $results As returned by self::doActions
	 * @return Status The Status of the last action, to be later checked with self::checkConsequences
	 */
	private function checkThrottleConsequence( $results ) {
		$finalRes = array_pop( $results );
		foreach ( $results as $result ) {
			if ( !$result->isGood() ) {
				$this->fail( 'Only the last actions should have triggered a filter; the other ones ' .
				'should have been allowed.' );
			}
		}

		return $finalRes;
	}

	/**
	 * Like self::testFilterConsequences but for throttle, which deserves a special treatment
	 *
	 * @param int[] $createIds IDs of the filters to create
	 * @param array[] $actionsParams Details of the action we need to execute to trigger filters
	 * @param array $consequences The consequences we're expecting
	 * @dataProvider provideThrottleFilters
	 */
	public function testThrottle( $createIds, $actionsParams, $consequences ) {
		$this->setMwGlobals( [ 'wgMainCacheType' => CACHE_ANYTHING ] );
		self::createFilters( $createIds );
		$results = self::doActions( $actionsParams );
		$res = $this->checkThrottleConsequence( $results );
		$lastParams = array_pop( $actionsParams );
		list( $expected, $actual ) = $this->checkConsequences( $res, $lastParams, $consequences );

		$expectedDisplay = implode( ', ', $expected );
		$actualDisplay = implode( ', ', $actual );

		$this->assertEquals(
			$expected,
			$actual,
			"The action should have returned the following error messages: $expectedDisplay. " .
			"Got $actualDisplay instead."
		);
	}

	/**
	 * Data provider for testThrottle. For every test case, we pass
	 *   - an array with the IDs of the filters to be created (listed in self::$filters),
	 *   - an array of array, where every sub-array holds the details of the action to execute in
	 *       order to trigger the filters, each one like in self::provideFilters
	 *   - an array of expected consequences for the last action (i.e. after throttling) of the form
	 *       [ 'consequence name' => [ IDs of the filter to take its parameters from ] ]
	 *       Such IDs may be more than one if we have a warning that is shown twice.
	 *
	 *
	 * @return array
	 */
	public function provideThrottleFilters() {
		return [
			'Basic test for throttling edits' => [
				[ 11 ],
				[
					[
						'action' => 'edit',
						'target' => 'Throttle',
						'oldText' => 'What is throttle?',
						'newText' => 'Throttle is something that should happen...',
						'summary' => 'Throttle'
					],
					[
						'action' => 'edit',
						'target' => 'Throttle',
						'oldText' => 'Throttle is something that should happen...',
						'newText' => '... Right now!',
						'summary' => 'Throttle'
					]
				],
				[ 'disallow' => [ 11 ] ]
			],
			'Basic test for throttling "move"' => [
				[ 11 ],
				[
					[
						'action' => 'move',
						'target' => 'Throttle test',
						'newTitle' => 'Another throttle test'
					],
					[
						'action' => 'move',
						'target' => 'Another throttle test',
						'newTitle' => 'Yet another throttle test'
					],
				],
				[ 'disallow' => [ 11 ] ]
			],
			'Basic test for throttling "delete"' => [
				[ 11 ],
				[
					[
						'action' => 'delete',
						'target' => 'Test page'
					],
					[
						'action' => 'delete',
						'target' => 'Test page'
					]
				],
				[ 'disallow' => [ 11 ] ]
			],
			'Basic test for throttling "createaccount"' => [
				[ 11 ],
				[
					[
						'action' => 'createaccount',
						'target' => 'User:AnotherUser',
						'username' => 'AnotherUser'
					],
					[
						'action' => 'createaccount',
						'target' => 'User:YetAnotherUser',
						'username' => 'YetAnotherUser'
					]
				],
				[ 'disallow' => [ 11 ] ]
			],
		];
	}
}
