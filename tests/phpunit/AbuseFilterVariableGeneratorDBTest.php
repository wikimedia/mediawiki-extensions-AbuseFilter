<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Parser\AFPData;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\RCVariableGenerator;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator;
use MediaWiki\MediaWikiServices;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterGeneric
 * @group Database
 */
class AbuseFilterVariableGeneratorDBTest extends MediaWikiIntegrationTestCase {
	use AbuseFilterCreateAccountTestTrait;
	use AbuseFilterUploadTestTrait;

	protected $tablesUsed = [
		'page',
		'text',
		'page_restrictions',
		'user',
		'recentchanges',
		'image',
		'oldimage',
	];

	/**
	 * @inheritDoc
	 */
	protected function tearDown() : void {
		MWTimestamp::setFakeTime( false );
		$this->clearUploads();
		parent::tearDown();
	}

	/**
	 * Check that the generated variables for edits are correct
	 *
	 * @param string $oldText The old wikitext of the page
	 * @param string $newText The new wikitext of the page
	 * @param string $summary
	 * @param array $expected Expected edit vars
	 * @covers \MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator::addEditVars
	 * @covers \MediaWiki\Extension\AbuseFilter\LazyVariableComputer
	 * @dataProvider provideEditVars
	 */
	public function testAddEditVars( $oldText, $newText, $summary, array $expected ) {
		$pageName = __METHOD__;
		$title = Title::makeTitle( 0, $pageName );
		$page = WikiPage::factory( $title );

		$this->editPage( $pageName, $oldText, 'Creating the test page' );
		$this->editPage( $pageName, $newText, $summary );

		$baseVars = AbuseFilterVariableHolder::newFromArray( [
			'old_wikitext' => $oldText,
			'new_wikitext' => $newText,
			'summary' => $summary
		] );

		$generator = new VariableGenerator( $baseVars );
		$actual = $generator->addEditVars( $title, $page, $this->createMock( User::class ) )
			->getVariableHolder()
			->exportAllVars();

		// Special case for new_html: avoid flaky tests, and only check containment
		$this->assertStringContainsString( '<div class="mw-parser-output', $actual['new_html'] );
		$this->assertNotRegExp( "/<!--\s*NewPP limit/", $actual['new_html'] );
		$this->assertNotRegExp( "/<!--\s*Transclusion/", $actual['new_html'] );
		foreach ( $expected['new_html'] as $needle ) {
			$this->assertStringContainsString( $needle, $actual['new_html'], 'Checking new_html' );
		}
		unset( $actual['new_html'], $expected['new_html'] );

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Data provider for testAddEditVars
	 * @return Generator|array
	 */
	public function provideEditVars() {
		$summary = __METHOD__;

		// phpcs:disable Generic.Files.LineLength
		$old = '[https://a.com Test] foo';
		$new = "'''Random'''.\nSome ''special'' chars: àèìòù 名探偵コナン.\n[[Help:PST|]] test, [//www.b.com link]";
		$expected = [
			'old_wikitext' => $old,
			'new_wikitext' => $new,
			'summary' => $summary,
			'new_html' => [ '<p><b>Random</b>', '<i>special</i>', 'PST</a>', 'link</a>' ],
			'new_pst' => "'''Random'''.\nSome ''special'' chars: àèìòù 名探偵コナン.\n[[Help:PST|PST]] test, [//www.b.com link]",
			'new_text' => "Random.\nSome special chars: àèìòù 名探偵コナン.\nPST test, link",
			'edit_diff' => "@@ -1,1 +1,3 @@\n-[https://a.com Test] foo\n+'''Random'''.\n+Some ''special'' chars: àèìòù 名探偵コナン.\n+[[Help:PST|]] test, [//www.b.com link]\n",
			'edit_diff_pst' => "@@ -1,1 +1,3 @@\n-[https://a.com Test] foo\n+'''Random'''.\n+Some ''special'' chars: àèìòù 名探偵コナン.\n+[[Help:PST|PST]] test, [//www.b.com link]\n",
			'new_size' => strlen( $new ),
			'old_size' => strlen( $old ),
			'edit_delta' => strlen( $new ) - strlen( $old ),
			'added_lines' => explode( "\n", $new ),
			'removed_lines' => [ $old ],
			'added_lines_pst' => [ "'''Random'''.", "Some ''special'' chars: àèìòù 名探偵コナン.", '[[Help:PST|PST]] test, [//www.b.com link]' ],
			'all_links' => [ '//www.b.com' ],
			'old_links' => [ 'https://a.com' ],
			'added_links' => [ '//www.b.com' ],
			'removed_links' => [ 'https://a.com' ]
		];

		yield 'PST and special chars' => [ $old, $new, $summary, $expected ];

		$old = "'''Random'''.\nSome ''special'' chars: àèìòù 名探偵コナン.\n[[Help:PST|]] test, [//www.b.com link]";
		$new = '[https://a.com Test] foo';
		$expected = [
			'old_wikitext' => $old,
			'new_wikitext' => $new,
			'summary' => $summary,
			'new_html' => [ 'Test</a>' ],
			'new_pst' => '[https://a.com Test] foo',
			'new_text' => 'Test foo',
			'edit_diff' => "@@ -1,3 +1,1 @@\n-'''Random'''.\n-Some ''special'' chars: àèìòù 名探偵コナン.\n-[[Help:PST|]] test, [//www.b.com link]\n+[https://a.com Test] foo\n",
			'edit_diff_pst' => "@@ -1,3 +1,1 @@\n-'''Random'''.\n-Some ''special'' chars: àèìòù 名探偵コナン.\n-[[Help:PST|]] test, [//www.b.com link]\n+[https://a.com Test] foo\n",
			'new_size' => strlen( $new ),
			'old_size' => strlen( $old ),
			'edit_delta' => strlen( $new ) - strlen( $old ),
			'added_lines' => [ $new ],
			'removed_lines' => explode( "\n", $old ),
			'added_lines_pst' => [ $new ],
			'all_links' => [ 'https://a.com' ],
			'old_links' => [ '//www.b.com' ],
			'added_links' => [ 'https://a.com' ],
			'removed_links' => [ '//www.b.com' ]
		];

		yield 'PST and special chars, reverse' => [ $old, $new, $summary, $expected ];
		// phpcs:enable Generic.Files.LineLength

		$old = 'This edit will be pretty smal';
		$new = $old . 'l';

		$expected = [
			'old_wikitext' => $old,
			'new_wikitext' => $new,
			'summary' => $summary,
			'new_html' => [ "<p>This edit will be pretty small\n</p>" ],
			'new_pst' => $new,
			'new_text' => $new,
			'edit_diff' => "@@ -1,1 +1,1 @@\n-$old\n+$new\n",
			'edit_diff_pst' => "@@ -1,1 +1,1 @@\n-$old\n+$new\n",
			'new_size' => strlen( $new ),
			'old_size' => strlen( $old ),
			'edit_delta' => 1,
			'added_lines' => [ $new ],
			'removed_lines' => [ $old ],
			'added_lines_pst' => [ $new ],
			'all_links' => [],
			'old_links' => [],
			'added_links' => [],
			'removed_links' => []
		];

		yield 'Small edit' => [ $old, $new, $summary, $expected ];
	}

	/**
	 * Make different users edit a page, so that we can check their names against
	 * the actual value of a _recent_contributors variable
	 * @param Title $title
	 * @return string[]
	 */
	private function computeRecentContributors( Title $title ) {
		// This test uses a custom DB query and it's hard to use mocks
		$user = $this->getMutableTestUser()->getUser();
		// Create the page and make a couple of edits from different users
		$this->editPage(
			$title->getText(),
			'AbuseFilter test for title variables',
			'',
			$title->getNamespace(),
			$user
		);
		$mockContributors = [ 'X>Alice', 'X>Bob', 'X>Charlie' ];
		foreach ( $mockContributors as $contributor ) {
			$this->editPage(
				$title->getText(),
				"page revision by $contributor",
				'',
				$title->getNamespace(),
				User::newFromName( $contributor, false )
			);
		}
		$contributors = array_reverse( $mockContributors );
		$contributors[] = $user->getName();
		return $contributors;
	}

	/**
	 * Test _recent_contributors variables. They perform a custom DB query and thus are tested
	 * here instead of in AbuseFilterTest.
	 *
	 * @covers MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator::addTitleVars
	 * @covers \MediaWiki\Extension\AbuseFilter\LazyVariableComputer::getLastPageAuthors
	 */
	public function testRecentContributors() {
		$prefixes = [ 'page', 'moved_from', 'moved_to' ];
		foreach ( $prefixes as $prefix ) {
			$varName = "{$prefix}_recent_contributors";
			$pageName = "Page to test $varName";
			$title = Title::newFromText( $pageName );

			$expected = $this->computeRecentContributors( $title );
			$vars = new AbuseFilterVariableHolder;
			$generator = new VariableGenerator( $vars );
			$vars = $generator->addTitleVars( $title, $prefix )->getVariableHolder();
			$actual = $vars->getVar( $varName )->toNative();
			$this->assertSame( $expected, $actual, "Prefix: $prefix" );
		}
	}

	/**
	 * Test for the page_first_contributor variable.
	 *
	 * @covers MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator::addTitleVars
	 * @covers \MediaWiki\Extension\AbuseFilter\LazyVariableComputer::compute
	 */
	public function testFirstContributorVar() {
		$prefixes = [ 'page', 'moved_from', 'moved_to' ];
		foreach ( $prefixes as $prefix ) {
			$varName = "{$prefix}_first_contributor";
			$title = Title::makeTitle( NS_MAIN, "Page to test $varName" );
			$user = $this->getMutableTestUser()->getUser();
			$this->editPage(
				$title->getText(),
				'AbuseFilter test for title variables',
				'',
				$title->getNamespace(),
				$user
			);
			$expected = $user->getName();

			$vars = new AbuseFilterVariableHolder;
			$generator = new VariableGenerator( $vars );
			$vars = $generator->addTitleVars( $title, $prefix )->getVariableHolder();
			$actual = $vars->getVar( $varName )->toNative();
			$this->assertSame( $expected, $actual, "Prefix: $prefix" );
		}
	}

	/**
	 * Check all methods used to retrieve variables from an RC row
	 *
	 * @param string $type Type of the action the row refers to
	 * @param string $action Same as the 'action' variable
	 * @covers \MediaWiki\Extension\AbuseFilter\VariableGenerator\RCVariableGenerator
	 * @covers AbuseFilterVariableHolder
	 * @dataProvider provideRCRowTypes
	 */
	public function testGetVarsFromRCRow( string $type, string $action ) {
		$timestamp = '1514700000';
		MWTimestamp::setFakeTime( $timestamp );
		$user = $this->getMutableTestUser()->getUser();
		$title = Title::newFromText( 'AbuseFilter testing page' );
		$page = $type === 'create' ? WikiPage::factory( $title ) : $this->getExistingTestPage( $title );
		$page->clear();

		$summary = 'Abuse Filter summary for RC tests';
		$expectedValues = [
			'user_name' => $user->getName(),
			'action' => $action,
			'summary' => $summary,
			'timestamp' => $timestamp
		];

		switch ( $type ) {
			case 'create':
				$expectedValues['old_wikitext'] = '';
				// Fallthrough
			case 'edit':
				$newText = 'Some new text for testing RC vars.';
				$this->editPage( $title->getText(), $newText, $summary, $title->getNamespace(), $user );
				$expectedValues += [
					'page_id' => $page->getId(),
					'page_namespace' => $title->getNamespace(),
					'page_title' => $title->getText(),
					'page_prefixedtitle' => $title->getPrefixedText()
				];
				break;
			case 'move':
				$newTitle = Title::newFromText( 'Another AbuseFilter testing page' );
				$mpf = MediaWikiServices::getInstance()->getMovePageFactory();
				$mp = $mpf->newMovePage( $title, $newTitle );
				$mp->move( $user, $summary, false );
				$newID = WikiPage::factory( $newTitle )->getId();

				$expectedValues += [
					'moved_from_id' => $page->getId(),
					'moved_from_namespace' => $title->getNamespace(),
					'moved_from_title' => $title->getText(),
					'moved_from_prefixedtitle' => $title->getPrefixedText(),
					'moved_to_id' => $newID,
					'moved_to_namespace' => $newTitle->getNamespace(),
					'moved_to_title' => $newTitle->getText(),
					'moved_to_prefixedtitle' => $newTitle->getPrefixedText()
				];
				break;
			case 'delete':
				$page->doDeleteArticleReal( $summary, $user );
				$expectedValues += [
					'page_id' => $page->getId(),
					'page_namespace' => $title->getNamespace(),
					'page_title' => $title->getText(),
					'page_prefixedtitle' => $title->getPrefixedText()
				];
				break;
			case 'newusers':
				$accountName = 'AbuseFilter dummy user';
				$this->createAccount( $accountName, $user, $action === 'autocreateaccount' );

				$expectedValues = [
					'action' => $action,
					'accountname' => $accountName,
					'user_name' => $user->getName(),
					'timestamp' => $timestamp
				];
				break;
			case 'upload':
				$fileName = 'My File.svg';
				$destTitle = Title::makeTitle( NS_FILE, $fileName );
				$page = WikiPage::factory( $destTitle );
				[ $status, $this->clearPath ] = $this->doUpload( $user, $fileName, 'Some text', $summary );
				if ( !$status->isGood() ) {
					throw new LogicException( "Cannot upload file:\n$status" );
				}

				// Since the SVG is randomly generated, we need to read some properties live
				$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->newFile( $destTitle );
				$expectedValues += [
					'page_id' => $page->getId(),
					'page_namespace' => $destTitle->getNamespace(),
					'page_title' => $destTitle->getText(),
					'page_prefixedtitle' => $destTitle->getPrefixedText(),
					'file_sha1' => \Wikimedia\base_convert( $file->getSha1(), 36, 16, 40 ),
					'file_size' => $file->getSize(),
					'file_mime' => 'image/svg+xml',
					'file_mediatype' => 'DRAWING',
					'file_width' => $file->getWidth(),
					'file_height' => $file->getHeight(),
					'file_bits_per_channel' => $file->getBitDepth(),
				];
				break;
			default:
				throw new LogicException( "Type $type not recognized!" );
		}

		if ( $type === 'edit' ) {
			$where = [ 'rc_source' => 'mw.edit' ];
		} elseif ( $type === 'create' ) {
			$where = [ 'rc_source' => 'mw.new' ];
		} else {
			$where = [ 'rc_log_type' => $type ];
		}
		$rcQuery = RecentChange::getQueryInfo();
		$row = $this->db->selectRow(
			$rcQuery['tables'],
			$rcQuery['fields'],
			$where,
			__METHOD__,
			[ 'ORDER BY rc_id DESC' ],
			$rcQuery['joins']
		);

		$rc = RecentChange::newFromRow( $row );
		$varGenerator = new RCVariableGenerator(
			new AbuseFilterVariableHolder(),
			$rc,
			$this->getTestSysop()->getUser()
		);
		$actual = $varGenerator->getVars()->getVars();

		// Convert PHP variables to AFPData
		$expected = array_map( [ AFPData::class, 'newFromPHPVar' ], $expectedValues );

		// Remove lazy variables (covered in other tests) and variables coming
		// from other extensions (may not be generated, depending on the test environment)
		$coreVariables = AbuseFilterServices::getKeywordsManager()->getCoreVariables();
		foreach ( $actual as $var => $value ) {
			if ( !in_array( $var, $coreVariables, true ) || $value instanceof AFComputedVariable ) {
				unset( $actual[ $var ] );
			}
		}

		// Not assertSame because we're comparing different AFPData objects
		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Data provider for testGetVarsFromRCRow
	 * @return array
	 */
	public function provideRCRowTypes() {
		return [
			'edit' => [ 'edit', 'edit' ],
			'create' => [ 'create', 'edit' ],
			'move' => [ 'move', 'move' ],
			'delete' => [ 'delete', 'delete' ],
			'createaccount' => [ 'newusers', 'createaccount' ],
			'autocreateaccount' => [ 'newusers', 'autocreateaccount' ],
			'upload' => [ 'upload', 'upload' ],
		];
	}
}
