<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use MediaWiki\CheckUser\Hook\CheckUserInsertChangesRow;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use MediaWiki\User\UserIdentity;

class CheckUserHandler implements CheckUserInsertChangesRow {

	/** @var FilterUser */
	private $filterUser;

	/**
	 * @param FilterUser $filterUser
	 */
	public function __construct( FilterUser $filterUser ) {
		$this->filterUser = $filterUser;
	}

	/**
	 * Any actions by the filter user should always be marked as by the software
	 * using IP 127.0.0.1, no XFF and no UA.
	 *
	 * @inheritDoc
	 */
	public function onCheckUserInsertChangesRow( string &$ip, &$xff, array &$row, UserIdentity $user ) {
		if (
			$user->isRegistered() &&
			$this->filterUser->getUserIdentity()->getId() == $user->getId()
		) {
			$ip = '127.0.0.1';
			$xff = false;
			$row['cuc_agent'] = '';
		}
	}
}
