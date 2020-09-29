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
 * @todo Make this a unit test once MediaWikiServices is no longer used in RevisionRecord::userCanBitfield
 */
class TextExtractorTest extends MediaWikiIntegrationTestCase {

	/**
	 * @param RevisionRecord|null $rev The revision being converted
	 * @param bool $sysop Whether the user should be a sysop (i.e. able to see deleted stuff)
	 * @param string $expected The expected textual representation of the Revision
	 * @covers \MediaWiki\Extension\AbuseFilter\TextExtractor::revisionToString
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

}
