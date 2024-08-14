<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Preferences\Hook\GetPreferencesHook;

class PreferencesHandler implements GetPreferencesHook {
	private PermissionManager $permissionManager;

	public function __construct(
		PermissionManager $permissionManager
	) {
		$this->permissionManager = $permissionManager;
	}

	/** @inheritDoc */
	public function onGetPreferences( $user, &$preferences ): void {
		if ( !$this->permissionManager->userHasRight( $user, 'abusefilter-access-protected-vars' ) ) {
			return;
		}

		$preferences['abusefilter-protected-vars-view-agreement'] = [
			'type' => 'toggle',
			'label-message' => 'abusefilter-preference-protected-vars-view-agreement',
			'section' => 'personal/abusefilter',
			'noglobal' => true,
		];
	}
}
