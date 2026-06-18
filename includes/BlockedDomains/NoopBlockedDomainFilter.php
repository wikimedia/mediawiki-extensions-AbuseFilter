<?php

namespace MediaWiki\Extension\AbuseFilter\BlockedDomains;

use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Page\PageReference;
use MediaWiki\Status\Status;
use MediaWiki\User\UserIdentity;

class NoopBlockedDomainFilter implements IBlockedDomainFilter {

	/** @inheritDoc */
	public function filter( VariableHolder $vars, UserIdentity $user, PageReference $title ): Status {
		return Status::newGood();
	}
}
