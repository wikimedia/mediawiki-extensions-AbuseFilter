<?php

use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\TextExtractor;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @group Test
 * @group AbuseFilter
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\TextExtractor
 * @covers ::__construct
 * @todo Make this a unit test once MediaWikiServices is no longer used in RevisionRecord::userCanBitfield
 */
class TextExtractorTest extends MediaWikiIntegrationTestCase {

	/**
	 * @param RevisionRecord|null $rev The revision being converted
	 * @param bool $sysop Whether the user should be a sysop (i.e. able to see deleted stuff)
	 * @param string $expected The expected textual representation of the Revision
	 * @covers ::revisionToString
	 * @dataProvider provideRevisionToString
	 */
	public function testRevisionToString( ?RevisionRecord $rev, bool $sysop, string $expected ) {
		/** @var MockObject|User $user */
		$user = $this->getMockBuilder( User::class )
			->setMethods( [ 'getEffectiveGroups' ] )
			->getMock();
		$groups = $sysop ? [ 'user', 'sysop' ] : [ 'user' ];
		$user->method( 'getEffectiveGroups' )->willReturn( $groups );

		$hookRunner = new AbuseFilterHookRunner( $this->createHookContainer() );
		$converter = new TextExtractor( $hookRunner );
		$actual = $converter->revisionToString( $rev, $user );
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
	 * @param Content $content
	 * @param string $expected
	 * @covers ::contentToString
	 * @dataProvider provideContentToString
	 */
	public function testContentToString( Content $content, string $expected ) {
		$hookRunner = new AbuseFilterHookRunner( $this->createHookContainer() );
		$converter = new TextExtractor( $hookRunner );
		$this->assertSame( $expected, $converter->contentToString( $content ) );
	}

	/**
	 * @return Generator
	 */
	public function provideContentToString() : Generator {
		$text = 'Some dummy text';
		yield 'text' => [ new TextContent( $text ), $text ];
		yield 'wikitext' => [ new WikitextContent( $text ), $text ];
		yield 'non-text' => [ new DummyNonTextContent( $text ), '' ];
	}

	/**
	 * @covers ::contentToString
	 */
	public function testContentToString__hook() {
		$expected = 'Text changed by hook';
		$hookCb = function ( Content $content, ?string &$text ) use ( $expected ) {
			$text = $expected;
			return false;
		};
		$hookRunner = new AbuseFilterHookRunner(
			$this->createHookContainer( [ 'AbuseFilter-contentToString' => $hookCb ] )
		);
		$converter = new TextExtractor( $hookRunner );
		$unusedContent = new TextContent( 'You should not see me' );
		$this->assertSame( $expected, $converter->contentToString( $unusedContent ) );
	}
}