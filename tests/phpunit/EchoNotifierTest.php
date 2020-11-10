<?php

use MediaWiki\Extension\AbuseFilter\EchoNotifier;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Extension\AbuseFilter\FilterLookup;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\EchoNotifier
 */
class EchoNotifierTest extends MediaWikiIntegrationTestCase {

	private const USER_IDS = [
		'1' => 1,
		'2' => 42,
	];

	private function getFilterLookup() : FilterLookup {
		$lookup = $this->createMock( FilterLookup::class );
		$lookup->method( 'getFilter' )
			->willReturnCallback( function ( $filter, $global ) {
				$filterObj = MutableFilter::newDefault();
				$filterObj->setUserID( self::USER_IDS[ $global ? "global-$filter" : $filter ] ?? 0 );
				return $filterObj;
			} );
		return $lookup;
	}

	public function provideDataForEvent() : array {
		return [
			[ true, 1, 1 ],
			[ true, 2, 42 ],
			[ false, 1, 1 ],
			[ false, 2, 42 ],
		];
	}

	/**
	 * @dataProvider provideDataForEvent
	 * @covers ::getDataForEvent
	 * @covers ::__construct
	 */
	public function testGetDataForEvent( bool $loaded, int $filter, int $userID ) {
		$notifier = new EchoNotifier( $this->getFilterLookup(), $loaded );
		[
			'type' => $type,
			'title' => $title,
			'extra' => $extra
		] = $notifier->getDataForEvent( $filter );

		$this->assertSame( EchoNotifier::EVENT_TYPE, $type );
		$this->assertInstanceOf( Title::class, $title );
		$this->assertSame( -1, $title->getNamespace() );
		[ $page, $subpage ] = explode( '/', $title->getText(), 2 );
		$this->assertSame( (string)$filter, $subpage );
		$this->assertSame( [ 'user' => $userID ], $extra );
	}

}
