<?php

use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterGeneric
 * @group Database
 */
class AbuseFilterVariableGeneratorDBTest extends MediaWikiIntegrationTestCase {
	protected $tablesUsed = [
		'page',
		'text',
		'page_restrictions',
		'user',
	];

	/**
	 * Check that the generated variables for edits are correct
	 *
	 * @param string $oldText The old wikitext of the page
	 * @param string $newText The new wikitext of the page
	 * @param string $summary
	 * @param array $expected Expected edit vars
	 * @covers \MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator::addEditVars
	 * @covers AFComputedVariable
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
		$actual = $generator->addEditVars( $title, $page )->getVariableHolder()->exportAllVars( true );

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
	 * @covers AFComputedVariable::getLastPageAuthors
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
	 * @covers AFComputedVariable::compute
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
}
