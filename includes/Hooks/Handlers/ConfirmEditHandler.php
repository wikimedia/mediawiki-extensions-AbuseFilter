<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ConfirmEdit\AbuseFilter\CaptchaConsequence;
use MediaWiki\Extension\ConfirmEdit\Hooks\ConfirmEditTriggersCaptchaHook;
use MediaWiki\Page\PageIdentity;

/**
 * Integration with Extension:ConfirmEdit, if loaded.
 */
class ConfirmEditHandler implements ConfirmEditTriggersCaptchaHook {

	/** @inheritDoc */
	public function onConfirmEditTriggersCaptcha( string $action, ?PageIdentity $page, bool &$result ) {
		// Check the request to see if ConfirmEdit's CaptchaConsequence was triggered, as
		// that sets a property on the request. If so, we know that we want to trigger the CAPTCHA.
		// Note that users with 'skipcaptcha' right will not be shown the CAPTCHA even if this
		// return true.
		if ( RequestContext::getMain()->getRequest()->getBool( CaptchaConsequence::FLAG ) ) {
			$result = true;
		}
	}
}
