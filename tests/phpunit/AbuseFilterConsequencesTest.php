<?php

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Session\SessionManager;
use MediaWiki\MediaWikiServices;
use MediaWiki\Storage\NameTableAccessException;

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
 * @group Large
 *
 * @covers AbuseFilter
 * @covers AbuseFilterHooks
 * @covers AbuseFilterPreAuthenticationProvider
 * @covers AbuseFilterParser::__construct
 * @todo Add upload actions everywhere
 */
class AbuseFilterConsequencesTest extends MediaWikiTestCase {
	/**
	 * @var User The user performing actions
	 */
	private static $mUser;
	/**
	 * @var \MediaWiki\Session\Session The session object to use for edits
	 */
	private static $mEditSession;
	/** To be used as fake timestamp in several tests */
	const MAGIC_TIMESTAMP = 2051222400;
	/** Prefix for tables to emulate an external DB */
	const DB_EXTERNAL_PREFIX = 'external_';
	/** Tables to create in the external DB */
	public static $externalTables = [
		'abuse_filter',
		'abuse_filter_action',
		'abuse_filter_log',
		'text',
	];

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
		'user',
		'text'
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
		],
		16 => [
			'af_pattern' => 'random := "adruhaoerihouhae"; added_lines contains random | ' .
				'edit_diff_pst contains random | new_pst contains random | new_html contains random |' .
				'1=1 | /*Superfluous condition to set a lazyLoader but not compute*/all_links contains random',
			'af_public_comments' => 'Filter computing several non-lazy variables',
			'actions' => [
				'disallow' => []
			]
		],
		17 => [
			'af_pattern' => 'timestamp = "' . self::MAGIC_TIMESTAMP . '" | 3 = 2 | 1 = 4 | 5 = 7 | 6 = 3',
			'af_comments' => 'This will normally consume 5 conditions, unless the timestamp is set to' .
				'the magic value of self::MAGIC_TIMESTAMP.',
			'af_public_comments' => 'Test with variable conditions',
			'actions' => [
				'tag' => [
					'testTagProfiling'
				]
			]
		],
		18 => [
			'af_pattern' => '1 == 1',
			'af_public_comments' => 'Global filter',
			'af_global' => 1,
			'actions' => [
				'warn' => [
					'abusefilter-warning'
				],
				'disallow' => []
			]
		],
		19 => [
			'af_pattern' => 'user_name === "FilteredUser"',
			'af_public_comments' => 'Another global filter',
			'af_global' => 1,
			'actions' => [
				'tag' => [
					'globalTag'
				]
			]
		],
		20 => [
			'af_pattern' => 'page_title === "Cellar door"',
			'af_public_comments' => 'Yet another global filter',
			'af_global' => 1,
			'actions' => [
				'disallow' => [],
			]
		]
	];
	// phpcs:enable Generic.Files.LineLength

	/**
	 * Add tables for global filters to the list of used tables
	 *
	 * @inheritDoc
	 */
	public function __construct( $name = null, array $data = [], $dataName = '' ) {
		$prefixedTables = array_map(
			function ( $table ) {
				return self::DB_EXTERNAL_PREFIX . $table;
			},
			self::$externalTables
		);
		$this->tablesUsed = array_merge( $this->tablesUsed, $prefixedTables );
		parent::__construct( $name, $data, $dataName );
	}

	/**
	 * @see MediaWikiTestCase::setUp
	 */
	protected function setUp() {
		parent::setUp();
		self::$mEditSession = SessionManager::singleton()->getEmptySession();
		$user = User::newFromName( 'FilteredUser' );
		$user->addToDatabase();
		$user->addGroup( 'sysop' );
		$block = DatabaseBlock::newFromTarget( $user );
		if ( $block ) {
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
			],
			'wgAbuseFilterCentralDB' => $this->db->getDBname() . '-' . $this->dbPrefix() .
				self::DB_EXTERNAL_PREFIX,
			'wgAbuseFilterIsCentral' => false
		] );
	}

	/**
	 * @inheritDoc
	 */
	protected function tearDown() {
		// Paranoia: ensure no fake timestamp leftover
		MWTimestamp::setFakeTime( false );
		// Close the connection to the "external" database
		$externalDBName = $this->db->getDBname() . '-' . $this->dbPrefix() . self::DB_EXTERNAL_PREFIX;
		$db = wfGetDB( DB_MASTER, [], $externalDBName );
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lbFactory->getMainLB( $externalDBName )->closeConnection( $db );
		parent::tearDown();
	}

	/**
	 * Creates new filters with the given ids, referred to self::$filters
	 *
	 * @param int[] $ids IDs of the filters to create
	 * @param bool $external Whether to create filters in the external table
	 */
	private static function createFilters( $ids, $external = false ) {
		global $wgAbuseFilterActions;
		$dbw = wfGetDB( DB_MASTER );
		$tablePrefix = $external ? self::DB_EXTERNAL_PREFIX : '';
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
				"{$tablePrefix}abuse_filter",
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
				"{$tablePrefix}abuse_filter_action",
				$actionsRows,
				__METHOD__
			);
		}
	}

	/**
	 * Stash the edit via API
	 *
	 * @param Title $title Title of the page to edit
	 * @param string $text The new content of the page
	 * @param string $summary The summary of the edit
	 * @return string The status of the operation, as returned by the API.
	 */
	private function stashEdit( $title, $text, $summary ) {
		$this->setMwGlobals( [ 'wgMainCacheType' => 'hash' ] );
		$params = [
			'action' => 'stashedit',
			'title' => $title->getPrefixedText(),
			'baserevid' => 0,
			'text' => $text,
			'summary' => $summary,
			'contentmodel' => 'wikitext',
			'contentformat' => 'text/x-wiki'
		];

		// Set up an API request
		$apiContext = new ApiTestContext();
		$params['token'] = ApiQueryTokens::getToken(
			self::$mUser, self::$mEditSession, ApiQueryTokens::getTokenTypeSalts()[ 'csrf' ]
		)->toString();
		$request = new FauxRequest( $params, true, self::$mEditSession );
		$context = $apiContext->newTestContext( $request, self::$mUser );
		$main = new ApiMain( $context, true );

		$main->execute();
		$result = $main->getResult()->getResultData()[ 'stashedit' ];

		return $result[ 'status' ];
	}

	/**
	 * Performs an edit. Freely adapted from EditPageTest::assertEdit
	 *
	 * @param Title $title Title of the page to edit
	 * @param string $oldText Old content of the page
	 * @param string $newText The new content of the page
	 * @param string $summary The summary of the edit
	 * @param bool|null $fromStash Whether to stash the edit. Null means no stashing, false means
	 *   stash the edit but don't reuse it for saving, true means stash and reuse.
	 * @return Status
	 */
	private function doEdit( $title, $oldText, $newText, $summary, $fromStash = null ) {
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
		$req = new FauxRequest( $params, true, self::$mEditSession );

		if ( $fromStash !== null ) {
			// If we want to save from stash, submit the same text
			$stashText = $newText;
			if ( $fromStash === false ) {
				// Otherwise, stash some random text which won't match the actual edit
				$stashText = md5( uniqid( rand(), true ) );
			}
			$stashResult = $this->stashEdit( $title, $stashText, $summary );
			if ( $stashResult !== 'stashed' ) {
				throw new MWException( "The edit cannot be stashed, got the following result: $stashResult" );
			}
		}

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
			$page = $this->getExistingTestPage( $target );
		}

		switch ( $params['action'] ) {
			case 'edit':
				$status = $this->doEdit( $target, $params['oldText'], $params['newText'], $params['summary'] );
				break;
			case 'stashedit':
				$stashStatus = $params['stashType'] === 'hit';
				$status = $this->doEdit(
					$target,
					$params['oldText'],
					$params['newText'],
					$params['summary'],
					$stashStatus
				);
				break;
			case 'move':
				$newTitle = isset( $params['newTitle'] )
					? Title::newFromText( $params['newTitle'] )
					: $this->getNonExistingTestPage()->getTitle();
				$mp = new MovePage( $target, $newTitle );
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
				throw new UnexpectedValueException( 'Unrecognized action ' . $params['action'] );
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
		if ( $actionParams['action'] === 'edit' || $actionParams['action'] === 'stashedit' ) {
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
	 * @covers AbuseFilterParser::getCondCount
	 * @covers AbuseFilterParser::raiseCondCount
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
		$this->setMwGlobals( [ 'wgMainCacheType' => 'hash' ] );
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

	/**
	 * Test storing and loading the var dump. See also AbuseFilterTest::testVarDump
	 *
	 * @param int[] $createIds IDs of the filters to create
	 * @param array $actionParams Details of the action we need to execute to trigger filters
	 * @param string[] $usedVars The variables effectively computed by filters in $createIds.
	 *   We'll search these in the stored dump.
	 * @covers AbuseFilter::storeVarDump
	 * @covers AbuseFilter::loadVarDump
	 * @covers AbuseFilterVariableHolder::dumpAllVars
	 * @dataProvider provideFiltersAndVariables
	 */
	public function testVarDump( $createIds, $actionParams, $usedVars ) {
		self::createFilters( $createIds );
		// We don't care about consequences here
		$this->doAction( $actionParams );

		$dbw = wfGetDB( DB_MASTER );
		// We just take a dump from a single filters, as they're all identical for the same action
		$dumpID = $dbw->selectField(
			'abuse_filter_log',
			'afl_var_dump',
			'',
			__METHOD__,
			[ 'ORDER BY' => 'afl_timestamp DESC' ]
		);

		$vars = AbuseFilter::loadVarDump( $dumpID )->mVars;

		$interestingVars = array_intersect_key( $vars, array_fill_keys( $usedVars, true ) );

		sort( $usedVars );
		ksort( $interestingVars );
		$this->assertEquals(
			$usedVars,
			array_keys( $interestingVars ),
			"The saved variables aren't the expected ones."
		);
		$this->assertContainsOnlyInstancesOf(
			AFPData::class,
			$interestingVars,
			'Some variables have not been computed.'
		);
	}

	/**
	 * Data provider for testVarDump
	 *
	 * @return array
	 */
	public function provideFiltersAndVariables() {
		return [
			[
				[ 1, 2 ],
				[
					'action' => 'edit',
					'target' => 'Test page',
					'oldText' => 'Some old text for the test.',
					'newText' => 'I like foo',
					'summary' => 'Test AbuseFilter for edit action.'
				],
				[ 'added_lines', 'action' ]
			],
			[
				[ 1, 2 ],
				[
					'action' => 'stashedit',
					'target' => 'Test page',
					'oldText' => 'Some old text for the test.',
					'newText' => 'I like foo',
					'summary' => 'Test AbuseFilter for edit action.',
					'stashType' => 'hit'
				],
				[ 'added_lines', 'action' ]
			],
			[
				[ 1, 2 ],
				[
					'action' => 'stashedit',
					'target' => 'Test page',
					'oldText' => 'Some old text for the test.',
					'newText' => 'I like foo',
					'summary' => 'Test AbuseFilter for edit action.',
					'stashType' => 'miss'
				],
				[ 'added_lines', 'action' ]
			],
			[
				[ 2 ],
				[
					'action' => 'move',
					'target' => 'Test page',
					'newTitle' => 'Another test page'
				],
				[ 'action', 'moved_to_title' ]
			],
			[
				[ 5 ],
				[
					'action' => 'edit',
					'target' => 'User:FilteredUser',
					'oldText' => 'Hey.',
					'newText' => 'I am a very nice user, really!',
					'summary' => ''
				],
				[ 'user_name' ]
			],
			[
				[ 2, 3, 7, 8 ],
				[
					'action' => 'edit',
					'target' => 'Link',
					'oldText' => 'What is a link?',
					'newText' => 'A link is something like this: [[Link|]].',
					'summary' => 'Explaining'
				],
				[ 'action', 'timestamp', 'added_lines_pst' ]
			],
			[
				[ 2, 3, 7, 8 ],
				[
					'action' => 'stashedit',
					'target' => 'Link',
					'oldText' => 'What is a link?',
					'newText' => 'A link is something like this: [[Link|]].',
					'summary' => 'Explaining',
					'stashType' => 'hit'
				],
				[ 'action', 'timestamp', 'added_lines_pst' ]
			],
			[
				[ 2, 3, 7, 8 ],
				[
					'action' => 'stashedit',
					'target' => 'Link',
					'oldText' => 'What is a link?',
					'newText' => 'A link is something like this: [[Link|]].',
					'summary' => 'Explaining',
					'stashType' => 'miss'
				],
				[ 'action', 'timestamp', 'added_lines_pst' ]
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
				[ 'added_lines_pst' ]
			],
			[
				[ 2, 3 ],
				[
					'action' => 'delete',
					'target' => 'Test page'
				],
				[ 'action', 'page_prefixedtitle' ]
			],
			[
				[ 10, 13 ],
				[
					'action' => 'edit',
					'target' => 'Tyger! Tyger! Burning bright',
					'oldText' => 'In the forests of the night',
					'newText' => 'What immortal hand or eye',
					'summary' => 'Could frame thy fearful symmetry?'
				],
				[]
			],
			[
				[ 15 ],
				[
					'action' => 'createaccount',
					'target' => 'User:AnotherUser',
					'username' => 'AnotherUser'
				],
				[ 'action' ]
			],
			[
				[ 16 ],
				[
					'action' => 'edit',
					'target' => 'Random',
					'oldText' => 'Old text',
					'newText' => 'Some new text which will not match',
					'summary' => 'No summary'
				],
				[ 'edit_diff_pst', 'new_pst', 'new_html' ]
			],
			[
				[ 16 ],
				[
					'action' => 'stashedit',
					'target' => 'Random',
					'oldText' => 'Old text',
					'newText' => 'Some new text which will not match',
					'summary' => 'No summary',
					'stashType' => 'miss'
				],
				[ 'edit_diff_pst', 'new_pst', 'new_html' ]
			],
			[
				[ 16 ],
				[
					'action' => 'stashedit',
					'target' => 'Random',
					'oldText' => 'Old text',
					'newText' => 'Some new text which will not match',
					'summary' => 'No summary',
					'stashType' => 'hit'
				],
				[ 'edit_diff_pst', 'new_pst', 'new_html' ]
			],
		];
	}

	/**
	 * Same as testFilterConsequences but only for stashed edits
	 *
	 * @param string $type Either "hit" or "miss". The former saves the edit from stash, the second
	 *   stashes the edit but doesn't reuse it.
	 * @param int[] $createIds IDs of the filters to create
	 * @param array $actionParams Details of the action we need to execute to trigger filters
	 * @param array $consequences The consequences we're expecting
	 * @dataProvider provideStashedEdits
	 */
	public function testStashedEdit( $type, $createIds, $actionParams, $consequences ) {
		if ( $type !== 'hit' && $type !== 'miss' ) {
			throw new InvalidArgumentException( '$type must be either "hit" or "miss"' );
		}
		// Add some info in actionParams identical for all tests
		$actionParams['action'] = 'stashedit';
		$actionParams['stashType'] = $type;

		$loggerMock = new TestLogger();
		$loggerMock->setCollect( true );
		$this->setLogger( 'StashEdit', $loggerMock );

		self::createFilters( $createIds );
		$result = $this->doAction( $actionParams );

		// Check that we stored the edit and then hit/missed the cache
		$foundStore = false;
		$foundHitOrMiss = false;
		// The conversion back and forth is needed because if the wiki language is not english
		// the given namespace has been localized and thus wouldn't match.
		$title = Title::newFromText( $actionParams['target'] )->getPrefixedText();
		foreach ( $loggerMock->getBuffer() as $entry ) {
			if ( preg_match( "/AbuseFilter::filterAction: cache $type for '$title'/", $entry[1] ) ) {
				$foundHitOrMiss = true;
			}
			if ( preg_match( "/AbuseFilter::filterAction: cache store for '$title'/", $entry[1] ) ) {
				$foundStore = true;
			}
			if ( $foundStore && $foundHitOrMiss ) {
				break;
			}
		}
		if ( !$foundStore ) {
			$this->fail( 'Did not store the edit in cache as expected for a stashed edit.' );
		} elseif ( !$foundHitOrMiss ) {
			$this->fail( "Did not $type the cache as expected for a stashed edit." );
		}

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
	 * Data provider for testStashedEdit
	 *
	 * @return array
	 */
	public function provideStashedEdits() {
		$sets = [
			[
				[ 1, 2 ],
				[
					'target' => 'Test page',
					'oldText' => 'Some old text for the test.',
					'newText' => 'I like foo',
					'summary' => 'Test AbuseFilter for edit action.'
				],
				[ 'warn'  => [ 1 ] ]
			],
			[
				[ 5 ],
				[
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
					'target' => 'Help:Help',
					'oldText' => 'Some help.',
					'newText' => 'Some help for you',
					'summary' => 'Help! I need somebody'
				],
				[ 'disallow' => [ 6 ] ]
			],
			[
				[ 2, 3, 7, 8 ],
				[
					'target' => 'Link',
					'oldText' => 'What is a link?',
					'newText' => 'A link is something like this: [[Link|]].',
					'summary' => 'Explaining'
				],
				[ 'degroup' => [ 7 ], 'block' => [ 8 ] ]
			],
			[
				[ 8, 9 ],
				[
					'target' => 'Whatever',
					'oldText' => 'Whatever is whatever',
					'newText' => 'Whatever is whatever, whatever it is. BTW, here is a [[Link|]]',
					'summary' => 'Whatever'
				],
				[ 'disallow' => [ 8 ], 'block' => [ 8 ] ]
			],
			[
				[ 10 ],
				[
					'target' => 'Buffalo',
					'oldText' => 'Buffalo',
					'newText' => 'Buffalo buffalo Buffalo buffalo buffalo buffalo Buffalo buffalo.',
					'summary' => 'Buffalo!'
				],
				[ 'tag' => [ 10 ] ]
			],
			[
				[ 1, 3, 7, 12 ],
				[
					'target' => 'User:FilteredUser',
					'oldText' => '',
					'newText' => 'A couple of lines about me...',
					'summary' => 'My user page'
				],
				[ 'block' => [ 12 ], 'degroup' => [ 7, 12 ] ]
			],
			[
				[ 10, 13 ],
				[
					'target' => 'Tyger! Tyger! Burning bright',
					'oldText' => 'In the forests of the night',
					'newText' => 'What immortal hand or eye',
					'summary' => 'Could frame thy fearful symmetry?'
				],
				[ 'tag' => [ 10 ] ]
			],
			[
				[ 14 ],
				[
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
					'target' => 'My page',
					'oldText' => '',
					'newText' => 'AbuseFilter will not block me',
					'summary' => ''
				],
				[]
			],
		];

		$finalSets = [];
		foreach ( $sets as $set ) {
			// Test both successfully saving a stashed edit and stashing the edit but re-executing filters
			$finalSets[] = array_merge( [ 'miss' ], $set );
			$finalSets[] = array_merge( [ 'hit' ], $set );
		}
		return $finalSets;
	}

	/**
	 * Test filter profiling, both for total and per-filter stats. NOTE: This test performs several
	 * actions for every test set, and is thus HEAVY.
	 *
	 * @param int[] $createIds IDs of the filters to create
	 * @param array $actionParams Details of the action we need to execute to trigger filters
	 * @param array $expectedGlobal Expected global stats
	 * @param array $expectedPerFilter Expected stats for every created filter
	 * @covers AbuseFilter::filterMatchesKey
	 * @covers AbuseFilter::filterUsedKey
	 * @covers AbuseFilter::filterLimitReachedKey
	 * @covers AbuseFilter::getFilterProfile
	 * @covers AbuseFilter::checkAllFilters
	 * @covers AbuseFilter::recordStats
	 * @covers AbuseFilter::checkEmergencyDisable
	 * @dataProvider provideProfilingFilters
	 */
	public function testProfiling( $createIds, $actionParams, $expectedGlobal, $expectedPerFilter ) {
		$this->setMwGlobals( [
			'wgAbuseFilterConditionLimit' => $actionParams[ 'condsLimit' ]
		] );
		self::createFilters( $createIds );
		for ( $i = 1; $i <= $actionParams['repeatAction'] - 1; $i++ ) {
			// First make some other actions to increase stats
			// @ToDo This doesn't works well with account creations
			$this->doAction( $actionParams );
			$actionParams['target'] .= $i;
		}
		// This is the magic value used by filter 16 to change the amount of used condition
		MWTimestamp::setFakeTime( self::MAGIC_TIMESTAMP );
		// We don't care about consequences here
		$this->doAction( $actionParams );
		MWTimestamp::setFakeTime( false );

		$stash = MediaWikiServices::getInstance()->getMainObjectStash();
		// Global stats shown on the top of Special:AbuseFilter
		$actualGlobalStats = [
			'totalMatches' => $stash->get( AbuseFilter::filterMatchesKey() ),
			'totalActions' => $stash->get( AbuseFilter::filterUsedKey( 'default' ) ),
			'totalOverflows' => $stash->get( AbuseFilter::filterLimitReachedKey() )
		];
		$this->assertSame(
			$expectedGlobal,
			$actualGlobalStats,
			'Global profiling stats are not computed correctly.'
		);

		// Per-filter stats shown on the top of Special:AbuseFilter/xxx
		foreach ( $createIds as $id ) {
			$actualStats = [
				'matches' => $stash->get( AbuseFilter::filterMatchesKey( $id ) ),
				'actions' => $stash->get( AbuseFilter::filterUsedKey( 'default' ) ),
				'averageConditions' => AbuseFilter::getFilterProfile( $id )[1]
			];
			$this->assertSame(
				$expectedPerFilter[ $id ],
				$actualStats,
				"Profiling stats are not computed correctly for filter $id."
			);
		}
	}

	/**
	 * Data provider for testProfiling. We only want filters which let the edit pass, since
	 * we'll perform multiple edits. How this test works: we repeat the action X times. For 1 to
	 * X - 1, it would take 1 + 1 + 5 + 1 conditions, but it will overflow without checking filter
	 * 19 (since the conds limit is 7). Then we perform the last execution using a trick that will
	 * make filter 17 only consume 1 condition.
	 *
	 * @todo All these values should be more customizable, or just hardcoded in the test method.
	 *
	 * @return array
	 */
	public function provideProfilingFilters() {
		return [
			'Basic test for statistics recording on edit.' => [
				[ 4, 5, 17, 19 ],
				[
					'action' => 'edit',
					'target' => 'Some page',
					'oldText' => 'Some old text',
					'newText' => 'Some new text',
					'summary' => 'Some summary',
					'condsLimit' => 7,
					'repeatAction' => 6
				],
				[
					'totalMatches' => 6,
					'totalActions' => 6,
					'totalOverflows' => 5
				],
				[
					4 => [
						'matches' => 0,
						'actions' => 6,
						'averageConditions' => 1.0
					],
					5 => [
						'matches' => 6,
						'actions' => 6,
						'averageConditions' => 1.0
					],
					17 => [
						'matches' => 1,
						'actions' => 6,
						'averageConditions' => 4.0
					],
					19 => [
						'matches' => 1,
						'actions' => 6,
						'averageConditions' => 1.0
					],
				]
			],
			'Test for statistics recording on a successfully stashed edit.' => [
				[ 4, 5, 17, 19 ],
				[
					'action' => 'stashedit',
					'target' => 'Some page',
					'oldText' => 'Some old text',
					'newText' => 'Some new text',
					'summary' => 'Some summary',
					'stashType' => 'hit',
					'condsLimit' => 7,
					'repeatAction' => 6
				],
				[
					'totalMatches' => 6,
					'totalActions' => 6,
					'totalOverflows' => 5
				],
				[
					4 => [
						'matches' => 0,
						'actions' => 6,
						'averageConditions' => 1.0
					],
					5 => [
						'matches' => 6,
						'actions' => 6,
						'averageConditions' => 1.0
					],
					17 => [
						'matches' => 1,
						'actions' => 6,
						'averageConditions' => 4.0
					],
					19 => [
						'matches' => 1,
						'actions' => 6,
						'averageConditions' => 1.0
					],
				]
			],
			'Test for statistics recording on an unsuccessfully stashed edit.' => [
				[ 4, 5, 17, 19 ],
				[
					'action' => 'stashedit',
					'target' => 'Some page',
					'oldText' => 'Some old text',
					'newText' => 'Some new text',
					'summary' => 'Some summary',
					'stashType' => 'miss',
					'condsLimit' => 7,
					'repeatAction' => 6
				],
				[
					'totalMatches' => 6,
					'totalActions' => 6,
					'totalOverflows' => 5
				],
				[
					4 => [
						'matches' => 0,
						'actions' => 6,
						'averageConditions' => 1.0
					],
					5 => [
						'matches' => 6,
						'actions' => 6,
						'averageConditions' => 1.0
					],
					17 => [
						'matches' => 1,
						'actions' => 6,
						'averageConditions' => 4.0
					],
					19 => [
						'matches' => 1,
						'actions' => 6,
						'averageConditions' => 1.0
					],
				]
			]
		];
	}

	/**
	 * Tests for global filters, defined on a central wiki and executed on another (e.g. a filter
	 *   defined on meta but triggered on another wiki using meta's global filters).
	 *   We emulate an external database by using different tables prefixed with
	 *   self::DB_EXTERNAL_PREFIX
	 *
	 * @param int[] $createIds IDs of the filters to create
	 * @param array $actionParams Details of the action we need to execute to trigger filters
	 * @param array $consequences The consequences we're expecting
	 * @dataProvider provideGlobalFilters
	 */
	public function testGlobalFilters( $createIds, $actionParams, $consequences ) {
		self::createFilters( $createIds, true );

		$result = $this->doAction( $actionParams );

		list( $expected, $actual ) = $this->checkConsequences( $result, $actionParams, $consequences );

		// First check that the filter work as expected
		$expectedDisplay = implode( ', ', $expected );
		$actualDisplay = implode( ', ', $actual );
		$this->assertEquals(
			$expected,
			$actual,
			"The action should have returned the following error messages: $expectedDisplay. " .
			"Got $actualDisplay instead."
		);

		// Check that the hits were logged on the "external" DB
		$logged = $this->db->selectFieldValues(
			self::DB_EXTERNAL_PREFIX . 'abuse_filter_log',
			'afl_filter',
			[ 'afl_wiki IS NOT NULL' ],
			__METHOD__
		);
		// Don't use assertSame because the DB holds strings here (T42757)
		$this->assertEquals(
			$createIds,
			$logged,
			'Some filter hits were not logged in the external DB.'
		);
	}

	/**
	 * Data provider for testGlobalFilters
	 *
	 * @return array
	 */
	public function provideGlobalFilters() {
		return [
			[
				[ 18 ],
				[
					'action' => 'edit',
					'target' => 'Global',
					'oldText' => 'Old text',
					'newText' => 'New text',
					'summary' => ''
				],
				[ 'disallow' => [ 18 ], 'warn' => [ 18 ] ]
			],
			[
				[ 19 ],
				[
					'action' => 'edit',
					'target' => 'A global page',
					'oldText' => 'Foo',
					'newText' => 'Bar',
					'summary' => 'Baz'
				],
				[ 'tag' => [ 19 ] ]
			],
			[
				[ 19, 20 ],
				[
					'action' => 'edit',
					'target' => 'Cellar door',
					'oldText' => '',
					'newText' => 'Yay, that\'s cool',
					'summary' => 'Unit test'
				],
				[ 'disallow' => [ 20 ] ]
			],
			[
				[ 18 ],
				[
					'action' => 'move',
					'target' => 'Cellar door',
					'newTitle' => 'Attic door'
				],
				[ 'warn' => [ 18 ] ]
			],
			[
				[ 19, 20 ],
				[
					'action' => 'delete',
					'target' => 'Cellar door',
				],
				[ 'disallow' => [ 20 ] ]
			],
			[
				[ 19 ],
				[
					'action' => 'stashedit',
					'target' => 'Cellar door',
					'oldText' => '',
					'newText' => 'Too many doors',
					'summary' => '',
					'stashType' => 'hit'
				],
				[ 'tag' => [ 19 ] ]
			],
			[
				[ 18 ],
				[
					'action' => 'createaccount',
					'target' => 'User:GlobalUser',
					'username' => 'GlobalUser'
				],
				[ 'warn' => [ 18 ] ]
			]
		];
	}
}
