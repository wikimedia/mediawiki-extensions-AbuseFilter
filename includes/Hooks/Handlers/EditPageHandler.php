<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Hook\EditPage__showEditForm_initialHook;
use MediaWiki\Output\OutputPage;
use MobileContext;

class EditPageHandler implements EditPage__showEditForm_initialHook {

	private bool $notificationsEnabled;
	/** @phan-suppress-next-line PhanUndeclaredTypeProperty */
	private ?MobileContext $mobileContext;

	/**
	 * @param Config $config
	 * @param MobileContext|null $mobileContext
	 */
	public function __construct(
		Config $config,
		// @phan-suppress-next-line PhanUndeclaredTypeParameter
		?MobileContext $mobileContext
	) {
		$this->notificationsEnabled = $config->get( 'AbuseFilterBlockedExternalDomainsNotifications' );
		$this->mobileContext = $mobileContext;
	}

	/**
	 * @param EditPage $editor
	 * @param OutputPage $out
	 */
	public function onEditPage__showEditForm_initial( $editor, $out ): void {
		if ( !$this->notificationsEnabled ) {
			return;
		}
		// @phan-suppress-next-line PhanUndeclaredClassMethod
		$isMobileView = $this->mobileContext && $this->mobileContext->shouldDisplayMobileView();
		if ( !$isMobileView ) {
			$out->addModules( 'ext.abuseFilter.wikiEditor' );
		}
	}
}
