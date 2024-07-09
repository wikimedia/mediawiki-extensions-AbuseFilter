<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Hooks;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\Hooks\Handlers\ConfirmEditHandler;
use MediaWiki\Extension\ConfirmEdit\AbuseFilter\CaptchaConsequence;
use MediaWiki\Extension\ConfirmEdit\Hooks;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Hooks\Handlers\ConfirmEditHandler
 * @group Database
 */
class ConfirmEditHandlerTest extends MediaWikiIntegrationTestCase {

	protected function tearDown(): void {
		parent::tearDown();
		Hooks::getInstance()->setForceShowCaptcha( false );
	}

	public function testOnEditFilterMergedContent() {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );
		$confirmEditHandler = new ConfirmEditHandler();
		$status = Status::newGood();
		$title = $this->createMock( Title::class );
		$title->method( 'canExist' )->willReturn( true );
		$context = RequestContext::getMain();
		$context->setTitle( $title );
		$confirmEditHandler->onEditFilterMergedContent(
			$context,
			$this->createMock( \Content::class ),
			$status,
			'',
			$this->createMock( User::class ),
			false
		);
		$this->assertStatusGood( $status, 'The default is to not show a CAPTCHA' );

		$simpleCaptcha = Hooks::getInstance();
		$simpleCaptcha->setEditFilterMergedContentHandlerInvoked();
		$simpleCaptcha->setAction( 'edit' );
		$captchaConsequence = new CaptchaConsequence( $this->createMock( Parameters::class ) );
		$captchaConsequence->execute();
		$confirmEditHandler->onEditFilterMergedContent(
			$context,
			$this->createMock( \Content::class ),
			$status,
			'',
			$this->createMock( User::class ),
			false
		);
		$this->assertStatusError(
			'captcha-edit-fail',
			$status,
			'CaptchaConsequence specifies that a CAPTCHA should be shown'
		);
	}
}
