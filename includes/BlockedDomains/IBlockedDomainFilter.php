<?php

namespace MediaWiki\Extension\AbuseFilter\BlockedDomains;

use MediaWiki\Extension\AbuseFilter\ServiceNames;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Page\PageReference;
use MediaWiki\Status\Status;
use MediaWiki\User\UserIdentity;

interface IBlockedDomainFilter {

	public const SERVICE_NAME = ServiceNames::BlockedDomainFilter;

	/**
	 * Check for any disallowed domains
	 *
	 * This function logs any hits under Special:Log.
	 *
	 * @param VariableHolder $vars variables by the action
	 * @param UserIdentity $user User that tried to add the domain, used for logging
	 * @param PageReference $title Title of the page that was attempted on, used for logging
	 * @return Status Error status if it's a match, good status if not
	 */
	public function filter( VariableHolder $vars, UserIdentity $user, PageReference $title ): Status;
}
