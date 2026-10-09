<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Extension\AbuseFilter\EchoNotifier;
use MediaWiki\Extension\AbuseFilter\ThrottleFilterPresentationModel;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\ThrottleFilterPresentationModel
 * @group Database
 */
class ThrottleFilterPresentationModelTest extends MediaWikiIntegrationTestCase {

	private User $recipient;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );
		$this->recipient = $this->getTestSysop()->getUser();
	}

	private function mockEvent( Title $title, ?array $throttledActions ): Event {
		$event = $this->getMockBuilder( Event::class )
			->disableOriginalConstructor()
			->onlyMethods( [
				'getExtraParam',
				'getTitle',
				'getType',
			] )
			->getMock();

		$event->method( 'getExtraParam' )->willReturnCallback(
			static function ( $key ) use ( $throttledActions ) {
				if ( $key === 'throttled-actions' ) {
					return $throttledActions;
				}
				return null;
			}
		);
		$event->method( 'getTitle' )->willReturn( $title );
		$event->method( 'getType' )->willReturn( EchoNotifier::EVENT_TYPE );

		return $event;
	}

	private function newPresentationModel(
		?array $throttledActions,
		string $distributionType
	): ThrottleFilterPresentationModel {
		$title = Title::makeTitle( NS_SPECIAL, 'AbuseFilter/123' );
		$event = $this->mockEvent( $title, $throttledActions );

		$model = EchoEventPresentationModel::factory(
			$event,
			$this->getServiceContainer()->getLanguageFactory()->getLanguage( 'qqx' ),
			$this->recipient,
			$distributionType
		);

		$this->assertInstanceOf( ThrottleFilterPresentationModel::class, $model );

		return $model;
	}

	/**
	 * @dataProvider provideDisabledActions
	 */
	public function testPresentationModelWebNotification( array $disabledActions ): void {
		$model = $this->newPresentationModel( $disabledActions, 'web' );
		$msg = $model->getHeaderMessage();
		$recipient = $this->recipient->getName();

		if ( $disabledActions === [] ) {
			$this->assertSame(
				'placeholder',
				$model->getIconType()
			);
			$this->assertSame(
				"(notification-header-throttle-filter-no-actions: $recipient, 123)",
				$msg->text()
			);
		} else {
			$this->assertSame(
				'alert',
				$model->getIconType()
			);
			$this->assertStringContainsString(
				"(notification-header-throttle-filter-actions: $recipient, 123,",
				$msg->text()
			);
			$this->assertSame(
				array_map( static fn ( $action ) => "(abusefilter-action-$action)", $disabledActions ),
				array_map(
					static fn ( $param ) => $param->getValue(),
					$msg->getParams()[2]->getValue()
				)
			);
			$this->assertSame(
				count( $disabledActions ),
				$msg->getParams()[3]
			);
		}
		$this->assertSame(
			[
				'url' => Title::makeTitle( NS_SPECIAL, 'AbuseFilter/123' )->getFullURL(),
				'label' => '(notification-link-text-show-filter)',
			],
			$model->getPrimaryLink()
		);
	}

	/**
	 * @dataProvider provideDisabledActions
	 */
	public function testPresentationModelEmailNotification( array $disabledActions ): void {
		$model = $this->newPresentationModel( $disabledActions, 'email' );
		$msg = $model->getSubjectMessage();

		if ( $disabledActions === [] ) {
			$this->assertSame(
				'placeholder',
				$model->getIconType()
			);
			$this->assertSame(
				'(notification-subject-throttle-filter-no-actions: 123)',
				$msg->text()
			);
		} else {
			$this->assertSame(
				'alert',
				$model->getIconType()
			);
			$this->assertSame(
				'(notification-subject-throttle-filter-actions: 123)',
				$msg->text()
			);
		}
	}

	public static function provideDisabledActions(): array {
		return [
			'no disabled actions' => [ [] ],
			'one disabled action' => [ [ 'block' ] ],
			'multiple disabled actions' => [ [ 'block', 'degroup' ] ],
		];
	}

	public function testPresentationModelBC(): void {
		$model = $this->newPresentationModel( null, 'web' );
		$recipient = $this->recipient->getName();

		$this->assertSame(
			'placeholder',
			$model->getIconType()
		);
		$this->assertSame(
			"(notification-header-throttle-filter-no-actions: $recipient, 123)",
			$model->getHeaderMessage()->text()
		);
	}
}
