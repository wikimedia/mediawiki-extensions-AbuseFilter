<?php
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use MediaWiki\Extension\AbuseFilter\BlockedDomainStorage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MessageSpecifier;
use Title;
use User;

/**
 * This hook handler is for very simple checks, rather than the much more advanced ones
 * undertaken by the FilteredActionsHandler.
 */
class EditPermissionHandler implements GetUserPermissionsErrorsHook {

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors
	 *
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param array|string|MessageSpecifier &$result
	 * @return bool|void
	 */
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		$services = MediaWikiServices::getInstance();

		// Only do anything if we're enabled on this wiki.
		if ( !$services->getMainConfig()->get( 'AbuseFilterEnableBlockedExternalDomain' ) ) {
			return;
		}

		// Ignore all actions and pages except MediaWiki: edits (and creates)
		// to the page we care about
		if (
			!( $action == 'create' || $action == 'edit' ) ||
			!$title->inNamespace( NS_MEDIAWIKI ) ||
			$title->getDBkey() !== BlockedDomainStorage::TARGET_PAGE
		) {
			return;
		}

		// Prohibit direct actions on our page.
		$result = [ 'abusefilter-blocked-domains-cannot-edit-directly', BlockedDomainStorage::TARGET_PAGE ];
		return false;
	}

}
