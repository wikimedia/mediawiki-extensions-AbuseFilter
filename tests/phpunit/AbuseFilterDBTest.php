<?php

use MediaWiki\MediaWikiServices;
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
	 * @covers AbuseFilter::generateTitleVars
	 */
	public function testRecentContributors() {
		$prefixes = [ 'page', 'moved_from', 'moved_to' ];
		foreach ( $prefixes as $prefix ) {
			$varName = "{$prefix}_recent_contributors";
			$pageName = "Page to test $varName";
			$title = Title::newFromText( $pageName );

			$expected = $this->computeRecentContributors( $title );
			$vars = AbuseFilter::generateTitleVars( $title, $prefix );
			$actual = $vars->getVar( $varName )->toNative();
			$this->assertSame( $expected, $actual, "Prefix: $prefix" );
		}
	}

	/**
	 * Given the name of a variable, naturally sets it to a determined amount
	 *
	 * @param string $old The old wikitext of the page
	 * @param string $new The new wikitext of the page
	 * @param WikiPage $page The page to use
	 * @param string $summary
	 * @return array First position is an AbuseFilterVariableHolder filled with base vars
	 *   (old/new_wikitext and summary) to be passed to the tested code. Second position is the
	 *   array of values we expect for all variables.
	 */
	private function computeExpectedEditVariable( $old, $new, WikiPage $page, $summary ) {
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

		$computedVariables = [];
		$baseVars = new AbuseFilterVariableHolder();

		// Set required variables
		$baseVars->setVar( 'old_wikitext', $old );
		$computedVariables['old_wikitext'] = $old;
		$baseVars->setVar( 'new_wikitext', $new );
		$computedVariables['new_wikitext'] = $new;
		$baseVars->setVar( 'summary', $summary );
		$computedVariables['summary'] = $summary;

		foreach ( $variables as $varName ) {
			// Reset text variables since some operations are changing them.
			$oldText = $old;
			$newText = $new;
			switch ( $varName ) {
				case 'edit_diff_pst':
					$newText = $computedVariables['new_pst'];
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
					$diffVariable = $varName === 'added_lines_pst' ? 'edit_diff_pst' : 'edit_diff';
					$diff = $computedVariables[$diffVariable];
					$line_prefix = $varName === 'removed_lines' ? '-' : '+';
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
					$newHtml = $computedVariables['new_html'];
					$result = StringUtils::delimiterReplace( '<', '>', '', $newHtml );
					break;
				case 'new_pst':
				case 'new_html':
					$content = ContentHandler::makeContent( $newText, $page->getTitle() );
					$editInfo = $page->prepareContentForEdit( $content );

					if ( $varName === 'new_pst' ) {
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
					$oldLinks = $computedVariables['old_links'];
					$newLinks = $computedVariables['all_links'];

					if ( $varName === 'added_links' ) {
						$result = array_diff( $newLinks, $oldLinks );
					} else {
						$result = array_diff( $oldLinks, $newLinks );
					}
					break;
				default:
					throw new Exception( "Given unknown edit variable $varName." );
			}
			$computedVariables[$varName] = $result;
		}
		return [ $baseVars, $computedVariables ];
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
		$pageName = $summary = __METHOD__;
		$title = Title::makeTitle( 0, $pageName );
		$page = WikiPage::factory( $title );

		$this->editPage( $pageName, $oldText, 'Creating the test page' );
		$this->editPage( $pageName, $newText, $summary );

		list( $baseVars, $expected ) =
			$this->computeExpectedEditVariable( $oldText, $newText, $page, $summary );

		$baseVars->addHolders( AbuseFilter::getEditVars( $title, $page ) );
		$actual = $baseVars->exportAllVars( true );

		$this->assertEquals( $expected, $actual );
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

		$flags = $this->db->selectField(
			'text',
			'old_flags',
			'',
			__METHOD__,
			[ 'ORDER BY' => 'old_id DESC' ]
		);
		$this->assertNotFalse( $flags, 'The var dump has not been saved.' );
		$flags = explode( ',', $flags );

		$expectedFlags = [ 'nativeDataArray', 'utf-8' ];
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

	/**
	 * @param RevisionRecord $rev The revision being converted
	 * @param bool $sysop Whether the user should be a sysop (i.e. able to see deleted stuff)
	 * @param string $expected The expected textual representation of the Revision
	 * @covers AbuseFilter::revisionToString
	 * @dataProvider provideRevisionToString
	 * @todo This should be a unit test...
	 */
	public function testRevisionToString( $rev, $sysop, $expected ) {
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
		yield 'Revision instance' => [
			new Revision( $revRec ),
			false,
			'Main slot text.'
		];

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
}
