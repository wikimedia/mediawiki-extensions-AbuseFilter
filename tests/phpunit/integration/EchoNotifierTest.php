<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesRegistry;
use MediaWiki\Extension\AbuseFilter\EchoNotifier;
use MediaWiki\Extension\AbuseFilter\Filter\ExistingFilter;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Notification\NotificationService;
use MediaWiki\Notification\RecipientSet;
use MediaWiki\Notification\Types\TitleNotification;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\EchoNotifier
 */
class EchoNotifierTest extends MediaWikiIntegrationTestCase {

	private const USER_IDS = [
		'1' => 1,
		'2' => 42,
	];

	private function getFilterLookup(): FilterLookup {
		$lookup = $this->createMock( FilterLookup::class );
		$lookup->method( 'getFilter' )
			->willReturnCallback( function ( $filter, $global ) {
				$userID = self::USER_IDS[ $global ? "global-$filter" : $filter ] ?? 0;
				$filterObj = $this->createMock( ExistingFilter::class );
				$filterObj->method( 'getUserIdentity' )->willReturn(
					UserIdentityValue::newRegistered( $userID, 'Test' )
				);
				$filterObj->method( 'getID' )->willReturn( $filter );
				return $filterObj;
			} );
		return $lookup;
	}

	public static function provideDataForEvent(): array {
		return [
			[ 1, 1 ],
			[ 2, 42 ]
		];
	}

	/**
	 * @dataProvider provideDataForEvent
	 */
	public function testNotifyForFilterHasCorrectData( int $filter, int $userID ) {
		$notifications = $this->createMock( NotificationService::class );
		$notifications->expects( $this->once() )
			->method( 'notify' )
			->with(
				$this->isInstanceOf( TitleNotification::class ),
				$this->isInstanceOf( RecipientSet::class )
			)
			->willReturnCallback( function (
				TitleNotification $notification, RecipientSet $recipients
			) use ( $filter, $userID ) {
				$title = $notification->getTitle();
				$this->assertInstanceOf( Title::class, $title );
				$this->assertSame( -1, $title->getNamespace() );
				[ , $subpage ] = explode( '/', $title->getText(), 2 );
				$this->assertSame( (string)$filter, $subpage );
				$this->assertSame( EchoNotifier::EVENT_TYPE, $notification->getType() );
				$this->assertSame( [], $notification->getProperties()['throttled-actions'] );
				$this->assertSame(
					[ $userID ],
					array_map( static fn ( $u ) => $u->getId(), $recipients->getRecipients() )
				);
			} );

		$notifier = new EchoNotifier(
			$this->getFilterLookup(),
			$this->createMock( ConsequencesRegistry::class ),
			$notifications
		);
		$notifier->notifyForFilter( $filter );
	}

}
