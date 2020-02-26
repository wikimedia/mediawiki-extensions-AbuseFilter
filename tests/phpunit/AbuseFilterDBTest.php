<?php

use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Generic tests for utility functions in AbuseFilter that require DB access
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
class AbuseFilterDBTest extends MediaWikiTestCase {
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
		array_push( $contributors, $user->getName() );
		return $contributors;
	}

	/**
	 * Test _recent_contributors variables. They perform a custom DB query and thus are tested
	 * here instead of in AbuseFilterTest.
	 *
	 * @covers \MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator::addTitleVars
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

		$holder = AbuseFilterVariableHolder::newFromArray( $variables );
		if ( array_intersect_key( AbuseFilter::getDeprecatedVariables(), $variables ) ) {
			$holder->mVarsVersion = 1;
		}

		$insertID = AbuseFilter::storeVarDump( $holder );

		$flags = $this->db->selectField(
			'text',
			'old_flags',
			'',
			__METHOD__,
			[ 'ORDER BY' => 'old_id DESC' ]
		);
		$this->assertNotFalse( $flags, 'The var dump has not been saved.' );
		$flags = $flags === '' ? [] : explode( ',', $flags );

		$expectedFlags = [ 'utf-8' ];
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
					'action' => 'edit',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text'
				]
			],
			'Normal case' => [
				[
					'action' => 'edit',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'user_editcount' => 15,
					'added_lines' => [ 'Foo', '', 'Bar' ]
				]
			],
			'Deprecated variables' => [
				[
					'action' => 'edit',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'article_articleid' => 11745,
					'article_first_contributor' => 'Good guy'
				]
			],
			'Move action' => [
				[
					'action' => 'move',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'all_links' => [ 'https://en.wikipedia.org' ],
					'moved_to_id' => 156,
					'moved_to_prefixedtitle' => 'MediaWiki:Foobar.js',
					'new_content_model' => CONTENT_MODEL_JAVASCRIPT
				]
			],
			'Delete action' => [
				[
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'timestamp' => 1546000295,
					'action' => 'delete',
					'page_namespace' => 114
				]
			],
			'Disabled vars' => [
				[
					'action' => 'edit',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'old_html' => 'Foo <small>bar</small> <s>lol</s>.',
					'old_text' => 'Foobar'
				]
			],
			'Account creation' => [
				[
					'action' => 'createaccount',
					'accountname' => 'XXX'
				]
			]
		];
	}

	/**
	 * @param RevisionRecord|null $rev The revision being converted
	 * @param bool $sysop Whether the user should be a sysop (i.e. able to see deleted stuff)
	 * @param string $expected The expected textual representation of the Revision
	 * @covers AbuseFilter::revisionToString
	 * @dataProvider provideRevisionToString
	 * @todo This should be a unit test...
	 */
	public function testRevisionToString( ?RevisionRecord $rev, bool $sysop, string $expected ) {
		/** @var MockObject|User $user */
		$user = $this->getMockBuilder( User::class )
			->setMethods( [ 'getEffectiveGroups' ] )
			->getMock();
		if ( $sysop ) {
			$user->expects( $this->atLeastOnce() )
				->method( 'getEffectiveGroups' )
				->willReturn( [ 'user', 'sysop' ] );
		} else {
			$user->expects( $this->any() )
				->method( 'getEffectiveGroups' )
				->willReturn( [ 'user' ] );
		}

		$user->clearInstanceCache();

		$actual = AbuseFilter::revisionToString( $rev, $user );
		$this->assertSame( $expected, $actual );
	}

	/**
	 * Data provider for testRevisionToString
	 *
	 * @return Generator|array
	 */
	public function provideRevisionToString() {
		yield 'no revision' => [ null, false, '' ];

		$title = Title::newFromText( __METHOD__ );
		$revRec = new MutableRevisionRecord( $title );
		$revRec->setContent( SlotRecord::MAIN, new TextContent( 'Main slot text.' ) );

		yield 'RevisionRecord instance' => [
			$revRec,
			false,
			'Main slot text.'
		];

		$revRec = new MutableRevisionRecord( $title );
		$revRec->setContent( SlotRecord::MAIN, new TextContent( 'Main slot text.' ) );
		$revRec->setContent( 'aux', new TextContent( 'Aux slot content.' ) );
		yield 'Multi-slot' => [
			$revRec,
			false,
			"Main slot text.\n\nAux slot content."
		];

		$revRec = new MutableRevisionRecord( $title );
		$revRec->setContent( SlotRecord::MAIN, new TextContent( 'Main slot text.' ) );
		$revRec->setVisibility( RevisionRecord::DELETED_TEXT );
		yield 'Suppressed revision, unprivileged' => [
			$revRec,
			false,
			''
		];

		yield 'Suppressed revision, privileged' => [
			$revRec,
			true,
			'Main slot text.'
		];
	}

	/**
	 * Check that our tag validation is working properly. Note that we only need one test
	 *   for each called function. Consistency within ChangeTags functions should be
	 *   assured by tests in core. The test for canAddTagsAccompanyingChange and canCreateTag
	 *   are missing because they won't actually fail, never. Resolving T173917 would
	 *   greatly improve the situation and could help writing better tests.
	 *
	 * @param string $tag The tag to validate
	 * @param string|null $error The expected error message. Null if validations should pass
	 * @covers AbuseFilter::isAllowedTag
	 * @dataProvider provideTags
	 */
	public function testIsAllowedTag( $tag, $error ) {
		$status = AbuseFilter::isAllowedTag( $tag );

		if ( !$status->isGood() ) {
			$actualError = $status->getErrors();
			$actualError = $actualError[0]['message'];
		} else {
			$actualError = null;
			if ( $error !== null ) {
				$this->fail( "Tag validation returned a valid status instead of the expected '$error' error." );
			}
		}

		$this->assertSame(
			$error,
			$actualError,
			"Expected message '$error', got '$actualError' while validating the tag '$tag'."
		);
	}

	/**
	 * Data provider for testIsAllowedTag
	 * @return array
	 */
	public function provideTags() {
		return [
			[ 'a|b', 'tags-create-invalid-chars' ],
			[ 'mw-undo', 'abusefilter-edit-bad-tags' ],
			[ 'abusefilter-condition-limit', 'abusefilter-tag-reserved' ],
			[ 'my_tag', null ],
		];
	}

	/**
	 * Test for the wiki_name variable.
	 *
	 * @covers AbuseFilter::generateGenericVars
	 * @covers AFComputedVariable::compute
	 */
	public function testWikiNameVar() {
		$name = 'foo';
		$prefix = 'bar';
		$this->setMwGlobals( [
			'wgDBname' => $name,
			'wgDBprefix' => $prefix
		] );

		$vars = new AbuseFilterVariableHolder();
		$vars->setLazyLoadVar( 'wiki_name', 'get-wiki-name', [] );
		$this->assertSame(
			"$name-$prefix",
			$vars->getVar( 'wiki_name' )->toNative()
		);
	}

	/**
	 * Test for the wiki_language variable.
	 *
	 * @covers AbuseFilter::generateGenericVars
	 * @covers AFComputedVariable::compute
	 */
	public function testWikiLanguageVar() {
		$fakeCode = 'foobar';
		$fakeLang = $this->getMockBuilder( Language::class )
			->setMethods( [ 'getCode' ] )
			->getMock();
		$fakeLang->method( 'getCode' )->willReturn( $fakeCode );
		$this->setService( 'ContentLanguage', $fakeLang );

		$vars = new AbuseFilterVariableHolder();
		$vars->setLazyLoadVar( 'wiki_language', 'get-wiki-language', [] );
		$this->assertSame(
			$fakeCode,
			$vars->getVar( 'wiki_language' )->toNative()
		);
	}

	/**
	 * @param RevisionRecord $revRec
	 * @param bool $privileged
	 * @param bool $expected
	 * @dataProvider provideUserCanViewRev
	 * @covers AbuseFilter::userCanViewRev
	 */
	public function testUserCanViewRev( RevisionRecord $revRec, bool $privileged, bool $expected ) {
		$user = $privileged
			? $this->getTestUser( 'suppress' )->getUser()
			: $this->getTestUser()->getUser();
		$this->assertSame( $expected, AbuseFilter::userCanViewRev( $revRec, $user ) );
	}

	/**
	 * @return Generator|array
	 */
	public function provideUserCanViewRev() {
		$title = Title::newFromText( __METHOD__ );

		$visible = new MutableRevisionRecord( $title );
		yield 'Visible, not privileged' => [ $visible, false, true ];
		yield 'Visible, privileged' => [ $visible, true, true ];

		$userSup = new MutableRevisionRecord( $title );
		$userSup->setVisibility( RevisionRecord::SUPPRESSED_USER );
		yield 'User suppressed, not privileged' => [ $userSup, false, false ];
		yield 'User suppressed, privileged' => [ $userSup, true, true ];

		$allSupp = new MutableRevisionRecord( $title );
		$allSupp->setVisibility( RevisionRecord::SUPPRESSED_ALL );
		yield 'All suppressed, not privileged' => [ $allSupp, false, false ];
		yield 'All suppressed, privileged' => [ $allSupp, true, true ];
	}
}
