<?php

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
 */
class AbuseFilterDBTest extends MediaWikiTestCase {
	/**
	 * @var array These tables will be deleted in parent::tearDown.
	 *   We need it to happen to make tests on fresh pages.
	 */
	protected $tablesUsed = [
		'abuse_filter',
		'abuse_filter_history',
		'abuse_filter_log'
	];

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
