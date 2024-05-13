<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Hooks;

use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\Hooks\Handlers\ConfirmEditHandler;
use MediaWiki\Extension\ConfirmEdit\AbuseFilter\CaptchaConsequence;
use MediaWiki\Page\PageIdentity;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Hooks\Handlers\ConfirmEditHandler
 */
class ConfirmEditHandlerTest extends MediaWikiIntegrationTestCase {

	public function testConfirmEditTriggersCaptcha() {
		$this->markTestSkippedIfExtensionNotLoaded( 'ConfirmEdit' );
		$confirmEditHandler = new ConfirmEditHandler();
		$result = false;
		$confirmEditHandler->onConfirmEditTriggersCaptcha(
			'edit',
			$this->createMock( PageIdentity::class ),
			$result
		);
		$this->assertFalse( $result, 'The default is to not show a CAPTCHA' );

		$captchaConsequence = new CaptchaConsequence( $this->createMock( Parameters::class ) );
		$captchaConsequence->execute();
		$confirmEditHandler->onConfirmEditTriggersCaptcha(
			'edit',
			$this->createMock( PageIdentity::class ),
			$result
		);
		$this->assertTrue( $result, 'CaptchaConsequence specifies that a CAPTCHA should be shown' );
	}
}
