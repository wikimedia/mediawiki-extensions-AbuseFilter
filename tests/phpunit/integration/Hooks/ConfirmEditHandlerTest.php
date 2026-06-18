<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Hooks;

use MediaWiki\Content\Content;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\Hooks\Handlers\ConfirmEditHandler;
use MediaWiki\Extension\ConfirmEdit\AbuseFilter\CaptchaConsequence;
use MediaWiki\Extension\ConfirmEdit\CaptchaTriggers;
use MediaWiki\Extension\ConfirmEdit\Services\CaptchaFactory;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Hooks\Handlers\ConfirmEditHandler
 * @group Database
 */
class ConfirmEditHandlerTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );
	}

	protected function tearDown(): void {
		if ( ExtensionRegistry::getInstance()->isLoaded( 'ConfirmEdit' ) ) {
			/** @var CaptchaFactory $captchaFactory */
			$captchaFactory = $this->getServiceContainer()->get( 'ConfirmEditCaptchaFactory' );
			$captchaFactory->unsetGlobalInstancesForTests();
		}
		parent::tearDown();
	}

	public function testOnEditFilterMergedContent() {
		$this->markTestSkipped( 'Disable while updating CaptchaConsequence parameters' );
		$this->clearHook( 'ConfirmEditBeforeForceShowCaptcha' );
		$this->overrideConfigValue(
			'CaptchaTriggers',
			[ 'edit' => [
				'trigger' => true,
				'class' => 'SimpleCaptcha',
			] ]
		);
		$this->editPage( 'Test', 'Foo' );
		$confirmEditHandler = new ConfirmEditHandler(
			$this->getServiceContainer()->get( 'ConfirmEditCaptchaFactory' )
		);
		$status = Status::newGood();
		$title = $this->getServiceContainer()->getTitleFactory()->newFromText( 'Test' );
		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$confirmEditHandler->onEditFilterMergedContent(
			$context,
			$this->createMock( Content::class ),
			$status,
			'',
			$this->createMock( User::class ),
			false
		);
		$this->assertStatusGood( $status, 'The default is to not show a CAPTCHA' );

		/** @var CaptchaFactory $captchaFactory */
		$captchaFactory = $this->getServiceContainer()->get( 'ConfirmEditCaptchaFactory' );
		$simpleCaptcha = $captchaFactory->getGlobalInstance( CaptchaTriggers::EDIT );
		$simpleCaptcha->setEditFilterMergedContentHandlerInvoked();
		$simpleCaptcha->setAction( CaptchaTriggers::EDIT );
		$parameters = $this->createMock( Parameters::class );
		$parameters->method( 'getAction' )->willReturn( 'edit' );

		$mockUserFactory = $this->createMock( UserFactory::class );
		$mockUser = $this->createMock( User::class );
		$mockUserFactory->method( 'newFromUserIdentity' )->willReturn( $mockUser );

		$captchaConsequence = new CaptchaConsequence(
			$parameters,
			$this->getServiceContainer()->getHookContainer(),
			$captchaFactory,
			$mockUserFactory,
		);
		$captchaConsequence->execute();
		$confirmEditHandler->onEditFilterMergedContent(
			$context,
			$this->createMock( Content::class ),
			$status,
			'',
			$this->createMock( User::class ),
			false
		);

		$this->assertStatusError( 'captcha-edit', $status );
	}
}
