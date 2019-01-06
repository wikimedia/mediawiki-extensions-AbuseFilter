<?php

use MediaWiki\Extension\AbuseFilter\Filter\Filter;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
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
	 * @param ?array $expectedValues Null to use $variables
	 * @covers AbuseFilter::storeVarDump
	 * @covers AbuseFilter::loadVarDump
	 * @covers AbuseFilterVariableHolder::dumpAllVars
	 * @dataProvider provideVariables
	 */
	public function testVarDump( array $variables, array $expectedValues = null ) {
		global $wgCompressRevisions, $wgDefaultExternalStore;

		$holder = AbuseFilterVariableHolder::newFromArray( $variables );

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

		$dump = AbuseFilter::loadVarDump( "tt:$insertID" );
		$expected = $expectedValues ? AbuseFilterVariableHolder::newFromArray( $expectedValues ) : $holder;
		$this->assertEquals( $expected, $dump, 'The var dump is not saved correctly' );
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
				],
				[
					'action' => 'edit',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'page_id' => 11745,
					'page_first_contributor' => 'Good guy'
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

	/**
	 * @param array $rawConsequences A raw, unfiltered list of consequences
	 * @param array $expectedKeys
	 * @param Title $title
	 * @covers AbuseFilterRunner::getFilteredConsequences
	 * @covers AbuseFilterRunner::replaceArraysWithConsequences
	 * @dataProvider provideConsequences
	 */
	public function testGetFilteredConsequences( $rawConsequences, $expectedKeys, Title $title ) {
		$this->setMwGlobals( [
			'wgAbuseFilterLocallyDisabledGlobalActions' => [
				'flag' => false,
				'throttle' => false,
				'warn' => false,
				'disallow' => false,
				'blockautopromote' => true,
				'block' => true,
				'rangeblock' => true,
				'degroup' => true,
				'tag' => false
			]
		] );
		$fakeFilter = $this->createMock( Filter::class );
		$fakeFilter->method( 'getName' )->willReturn( 'unused name' );
		$fakeLookup = $this->createMock( FilterLookup::class );
		$fakeLookup->method( 'getFilter' )->willReturn( $fakeFilter );
		$this->setService( FilterLookup::SERVICE_NAME, $fakeLookup );
		$user = $this->getTestUser()->getUser();
		$vars = AbuseFilterVariableHolder::newFromArray( [ 'action' => 'edit' ] );
		$runner = new AbuseFilterRunner( $user, $title, $vars, 'default' );
		$actual = $runner->getFilteredConsequences( $runner->replaceArraysWithConsequences( $rawConsequences ) );

		$actualKeys = [];
		foreach ( $actual as $filter => $actions ) {
			$actualKeys[$filter] = array_keys( $actions );
		}

		$this->assertEquals( $expectedKeys, $actualKeys );
	}

	/**
	 * Data provider for testGetFilteredConsequences
	 * @todo Split these
	 * @return array
	 */
	public function provideConsequences() {
		$pageName = 'TestFilteredConsequences';
		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedText' )->willReturn( $pageName );

		return [
			'warn and throttle exclude other actions' => [
				[
					2 => [
						'warn' => [
							'abusefilter-warning'
						],
						'tag' => [
							'some tag'
						]
					],
					13 => [
						'throttle' => [
							'13',
							'14,15',
							'user'
						],
						'disallow' => []
					],
					168 => [
						'degroup' => []
					]
				],
				[
					2 => [ 'warn' ],
					13 => [ 'throttle' ],
					168 => [ 'degroup' ]
				],
				$title
			],
			'warn excludes other actions, block excludes disallow' => [
				[
					3 => [
						'tag' => [
							'some tag'
						]
					],
					'global-2' => [
						'warn' => [
							'abusefilter-beautiful-warning'
						],
						'degroup' => []
					],
					4 => [
						'disallow' => [],
						'block' => [
							'blocktalk',
							'15 minutes',
							'indefinite'
						]
					]
				],
				[
					3 => [ 'tag' ],
					'global-2' => [ 'warn' ],
					4 => [ 'block' ]
				],
				$title
			],
			'some global actions are disabled locally, the longest block is chosen' => [
				[
					'global-1' => [
						'blockautopromote' => [],
						'block' => [
							'blocktalk',
							'indefinite',
							'indefinite'
						]
					],
					1 => [
						'block' => [
							'blocktalk',
							'4 hours',
							'4 hours'
						]
					],
					2 => [
						'degroup' => [],
						'block' => [
							'blocktalk',
							'infinity',
							'never'
						]
					]
				],
				[
					'global-1' => [],
					1 => [],
					2 => [ 'degroup', 'block' ]
				],
				$title
			],
		];
	}
}
