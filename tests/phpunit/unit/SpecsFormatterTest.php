<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use Generator;
use MediaWiki\Extension\AbuseFilter\Filter\AbstractFilter;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Extension\AbuseFilter\SpecsFormatter;
use MediaWiki\Language\Language;
use MediaWiki\Message\Message;
use MediaWikiUnitTestCase;
use MessageLocalizer;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\SpecsFormatter
 */
class SpecsFormatterTest extends MediaWikiUnitTestCase {
	/**
	 * @param bool $msgDisabled Should the message be disabled?
	 * @return SpecsFormatter
	 */
	private function getFormatter( bool $msgDisabled = false ): SpecsFormatter {
		$localizer = $this->createMock( MessageLocalizer::class );
		$localizer->method( 'msg' )->willReturnCallback( function ( $k ) use ( $msgDisabled ) {
			if ( $k === 'abusefilter-throttle-details' ) {
				// Special case
				$msg = $this->createMock( Message::class );
				$msg->method( 'params' )->willReturnCallback(
					fn ( ...$p ) => $this->getMockMessage( $k . '|' . implode( '|', $p ) )
				);
				return $msg;
			}
			$msg = $this->getMockMessage( $k );
			$msg->method( 'isDisabled' )->willReturn( $msgDisabled );
			return $msg;
		} );
		return new SpecsFormatter( $localizer );
	}

	public function testSetMessageLocalizer() {
		$formatter = $this->getFormatter();
		$ml = $this->createMock( MessageLocalizer::class );
		$formatter->setMessageLocalizer( $ml );
		/** @var SpecsFormatter $wrapper */
		$wrapper = TestingAccessWrapper::newFromObject( $formatter );
		$this->assertSame( $ml, $wrapper->messageLocalizer );
	}

	/**
	 * @param string $action
	 * @param bool $msgDisabled
	 * @param string $expected
	 * @dataProvider provideActionDisplay
	 */
	public function testGetActionDisplay( string $action, bool $msgDisabled, string $expected ) {
		$formatter = $this->getFormatter( $msgDisabled );
		$this->assertSame( $expected, $formatter->getActionDisplay( $action ) );
	}

	public static function provideActionDisplay(): array {
		return [
			'exists' => [ 'foobar', false, 'abusefilter-action-foobar' ],
			'does not exist' => [ 'foobar', true, 'foobar' ],
		];
	}

	private function getMockLanguage(): Language {
		$lang = $this->createMock( Language::class );
		$lang->method( 'translateBlockExpiry' )->willReturnCallback( static function ( $x ) {
			return "expiry-$x";
		} );
		$lang->method( 'commaList' )->willReturnCallback( static function ( $x ) {
			return implode( ',', $x );
		} );
		$lang->method( 'listToText' )->willReturnCallback( static function ( $x ) {
			return implode( ',', $x );
		} );
		$lang->method( 'semicolonList' )->willReturnCallback( static function ( $x ) {
			return implode( ';', $x );
		} );
		return $lang;
	}

	/**
	 * @param string $action
	 * @param array $params
	 * @param string $expected
	 * @dataProvider provideFormatAction
	 */
	public function testFormatAction( string $action, array $params, string $expected ) {
		$formatter = $this->getFormatter();
		$lang = $this->getMockLanguage();
		$this->assertSame( $expected, $formatter->formatAction( $action, $params, $lang ) );
	}

	public static function provideFormatAction() {
		yield 'no params' => [ 'foobar', [], 'abusefilter-action-foobar' ];
		yield 'legacy block' => [ 'block', [], 'abusefilter-action-block' ];

		$colon = 'colon-separator';
		$baseBlock = "abusefilter-block-anon{$colon}expiry-1 day,abusefilter-block-user{$colon}expiry-1 week";
		yield 'new block, no talk' => [ 'block', [ 'noTalkBlockSet', '1 day', '1 week' ], $baseBlock ];
		yield 'new block, with talk' => [
			'block',
			[ 'blocktalk', '1 day', '1 week' ],
			"$baseBlock,abusefilter-block-talk"
		];
		yield 'throttle' => [
			'throttle',
			[ 'ignored', '42,163', 'ip,user', 'site' ],
			"abusefilter-action-throttle{$colon}abusefilter-throttle-details|42|163|" .
				'abusefilter-throttle-ip,abusefilter-throttle-user;abusefilter-throttle-site'
		];
		yield 'generic parametrized' => [ 'myaction', [ 'foo', 'bar' ], "abusefilter-action-myaction{$colon}foo;bar" ];
	}

	/**
	 * @param string $flags
	 * @param string $expected
	 * @dataProvider provideFlags
	 */
	public function testFormatFlags( string $flags, string $expected ) {
		$formatter = $this->getFormatter();
		$lang = $this->createMock( Language::class );
		$lang->method( 'commaList' )->willReturnCallback( static function ( $x ) {
			return implode( ',', $x );
		} );
		$this->assertSame( $expected, $formatter->formatFlags( $flags, $lang ) );
	}

	public static function provideFlags(): array {
		return [
			'empty' => [ '', '' ],
			'single' => [ 'foo', 'abusefilter-history-foo' ],
			'multiple' => [ 'foo,bar,baz', 'abusefilter-history-foo,abusefilter-history-bar,abusefilter-history-baz' ]
		];
	}

	/**
	 * @param AbstractFilter $filter
	 * @param string $expected
	 * @dataProvider provideFilterFlags
	 */
	public function testFormatFilterFlags( AbstractFilter $filter, string $expected ) {
		$formatter = $this->getFormatter();
		$lang = $this->createMock( Language::class );
		$lang->method( 'commaList' )->willReturnCallback( static function ( $x ) {
			return implode( ',', $x );
		} );
		$this->assertSame( $expected, $formatter->formatFilterFlags( $filter, $lang ) );
	}

	public static function provideFilterFlags(): Generator {
		$none = MutableFilter::newDefault();
		$none->setEnabled( false );
		yield 'none' => [ $none, '' ];

		yield 'single' => [ MutableFilter::newDefault(), 'abusefilter-history-enabled' ];

		$multiple = MutableFilter::newDefault();
		$multiple->setHidden( true );
		$multiple->setProtected( true );
		yield 'multiple' => [
			$multiple,
			'abusefilter-history-enabled,abusefilter-history-hidden,abusefilter-history-protected'
		];
	}

	/**
	 * @param string $group
	 * @param bool $msgDisabled
	 * @param string $expected
	 * @dataProvider provideGroup
	 */
	public function testNameGroup( string $group, bool $msgDisabled, string $expected ) {
		$formatter = $this->getFormatter( $msgDisabled );
		$this->assertSame( $expected, $formatter->nameGroup( $group ) );
	}

	public static function provideGroup(): array {
		return [
			'exists' => [ 'foobar', false, 'abusefilter-group-foobar' ],
			'does not exist' => [ 'foobar', true, 'foobar' ],
		];
	}
}
