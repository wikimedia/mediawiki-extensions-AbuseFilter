<?php

use MediaWiki\Extension\AbuseFilter\ChangeTagger;

/**
 * @group Test
 * @group AbuseFilter
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\ChangeTagger
 */
class AbuseFilterChangeTaggerTest extends MediaWikiUnitTestCase {
	/**
	 * @return ChangeTagger
	 */
	private function getTagger() : ChangeTagger {
		return new ChangeTagger();
	}

	/**
	 * @return Generator
	 */
	public function getActionData() : Generator {
		$titleText = 'FOO';
		$title = new TitleValue( NS_MAIN, $titleText );
		$userName = 'Foobar';
		$getRCFromAttribs = function ( array $attribs ) : RecentChange {
			$rc = $this->createMock( RecentChange::class );
			$rc->method( 'getAttribute' )->willReturnCallback(
				function ( $name ) use ( $attribs ) {
					return $attribs[$name];
				}
			);
			return $rc;
		};
		$baseAttribs = [
			'rc_namespace' => NS_MAIN,
			'rc_title' => $titleText,
			'rc_user_text' => $userName
		];
		$baseSpecs = [ 'username' => $userName, 'target' => $title ];

		$rcAttribs = [ 'rc_log_type' => null ] + $baseAttribs;
		yield 'edit' => [
			'specifier' => [ 'action' => 'edit' ] + $baseSpecs,
			'recentchange' => $getRCFromAttribs( $rcAttribs )
		];

		$rcAttribs = [ 'rc_log_type' => 'newusers', 'rc_log_action' => 'create2' ] + $baseAttribs;
		yield 'createaccount' => [
			'specifier' => [ 'action' => 'createaccount', 'accountname' => $userName ] + $baseSpecs,
			'recentchange' => $getRCFromAttribs( $rcAttribs )
		];

		$rcAttribs = [ 'rc_log_type' => 'newusers', 'rc_log_action' => 'autocreate' ] + $baseAttribs;
		yield 'autocreate' => [
			'specifier' => [ 'action' => 'autocreateaccount', 'accountname' => $userName ] + $baseSpecs,
			'recentchange' => $getRCFromAttribs( $rcAttribs )
		];
	}

	/**
	 * @inheritDoc
	 */
	public function setUp() : void {
		$this->getTagger()->clearBuffer();
	}

	/**
	 * @param array $specifier
	 * @param RecentChange $rc
	 * @covers ::bufferTagsToSetByAction
	 * @dataProvider getActionData
	 */
	public function testTagsToSetWillNotContainDuplicates( array $specifier, RecentChange $rc ) {
		$tagger = $this->getTagger();

		$iterations = 3;
		while ( $iterations-- ) {
			$tagger->addTags( $specifier, [ 'uniqueTag' ] );
			$this->assertSame( [ 'uniqueTag' ], $tagger->getTagsForRecentChange( $rc ) );
		}
	}

	/**
	 * @param array $specifier
	 * @param RecentChange $rc
	 * @covers ::clearBuffer
	 * @dataProvider getActionData
	 */
	public function testClearBuffer( array $specifier, RecentChange $rc ) {
		$tagger = $this->getTagger();

		$tagger->addTags( $specifier, [ 'a', 'b', 'c' ] );
		$tagger->clearBuffer();
		$this->assertSame( [], $tagger->getTagsForRecentChange( $rc ) );
	}

	/**
	 * @param array $specifier
	 * @param RecentChange $rc
	 * @covers ::addConditionsLimitTag
	 * @dataProvider getActionData
	 */
	public function testAddConditionsLimitTag( array $specifier, RecentChange $rc ) {
		$tagger = $this->getTagger();

		$tagger->addConditionsLimitTag( $specifier );
		$this->assertCount( 1, $tagger->getTagsForRecentChange( $rc ) );
	}

	/**
	 * @param array $specifier
	 * @param RecentChange $rc
	 * @covers ::addTags
	 * @covers ::getTagsForRecentChange
	 * @covers ::getIDFromRecentChange
	 * @covers ::getActionID
	 * @covers ::getTagsForID
	 * @covers ::bufferTagsToSetByAction
	 * @dataProvider getActionData
	 */
	public function testAddGetTags( array $specifier, RecentChange $rc ) {
		$tagger = $this->getTagger();

		$expected = [ 'foo', 'bar', 'baz' ];
		$tagger->addTags( $specifier, $expected );
		$this->assertSame( $expected, $tagger->getTagsForRecentChange( $rc ) );
	}

	/**
	 * @param array $specifier
	 * @param RecentChange $rc
	 * @covers ::addTags
	 * @covers ::getActionID
	 * @covers ::bufferTagsToSetByAction
	 * @dataProvider getActionData
	 */
	public function testAddTags_multiple( array $specifier, RecentChange $rc ) {
		$tagger = $this->getTagger();

		$expected = [ 'foo', 'bar', 'baz' ];
		foreach ( $expected as $tag ) {
			$tagger->addTags( $specifier, [ $tag ] );
		}
		$this->assertSame( $expected, $tagger->getTagsForRecentChange( $rc ) );
	}

	/**
	 * @param array $specifier
	 * @param RecentChange $rc
	 * @covers ::getTagsForRecentChange
	 * @covers ::getIDFromRecentChange
	 * @covers ::getActionID
	 * @covers ::getTagsForID
	 * @dataProvider getActionData
	 */
	public function testGetTags_clear( array $specifier, RecentChange $rc ) {
		$tagger = $this->getTagger();

		$expected = [ 'foo', 'bar', 'baz' ];
		$tagger->addTags( $specifier, $expected );

		$tagger->getTagsForRecentChange( $rc, false );
		$this->assertSame( $expected, $tagger->getTagsForRecentChange( $rc ), 'no clear' );
		$tagger->getTagsForRecentChange( $rc );
		$this->assertSame( [], $tagger->getTagsForRecentChange( $rc ), 'clear' );
	}
}