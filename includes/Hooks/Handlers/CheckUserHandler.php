<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use MediaWiki\CheckUser\Hook\CheckUserInsertChangesRowHook;
use MediaWiki\CheckUser\Hook\CheckUserInsertLogEventRowHook;
use MediaWiki\CheckUser\Hook\CheckUserInsertPrivateEventRowHook;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNameUtils;
use RecentChange;

class CheckUserHandler implements
	CheckUserInsertChangesRowHook,
	CheckUserInsertPrivateEventRowHook,
	CheckUserInsertLogEventRowHook
{

	/** @var FilterUser */
	private $filterUser;

	/** @var UserNameUtils */
	private $userNameUtils;

	/**
	 * @param FilterUser $filterUser
	 * @param UserNameUtils $userNameUtils
	 */
	public function __construct(
		FilterUser $filterUser,
		UserNameUtils $userNameUtils
	) {
		$this->filterUser = $filterUser;
		$this->userNameUtils = $userNameUtils;
	}

	/**
	 * Any edits by the filter user should always be marked as by the software
	 * using IP 127.0.0.1, no XFF and no UA.
	 *
	 * @inheritDoc
	 */
	public function onCheckUserInsertChangesRow(
		string &$ip, &$xff, array &$row, UserIdentity $user, ?RecentChange $rc
	) {
		$isTemp = $this->userNameUtils->isTemp( $user->getName() );
		if (
			$user->isRegistered() && !$isTemp &&
			$this->filterUser->getUserIdentity()->getId() == $user->getId()
		) {
			$ip = '127.0.0.1';
			$xff = false;
			$row['cuc_agent'] = '';
		}
	}

	/**
	 * Any log actions by the filter user should always be marked as by the software
	 * using IP 127.0.0.1, no XFF and no UA.
	 *
	 * @inheritDoc
	 */
	public function onCheckUserInsertLogEventRow(
		string &$ip, &$xff, array &$row, UserIdentity $user, int $id, ?RecentChange $rc
	) {
		$isTemp = $this->userNameUtils->isTemp( $user->getName() );
		if (
			$user->isRegistered() && !$isTemp &&
			$this->filterUser->getUserIdentity()->getId() == $user->getId()
		) {
			$ip = '127.0.0.1';
			$xff = false;
			$row['cule_agent'] = '';
		}
	}

	/**
	 * Any log actions by the filter user should always be marked as by the software
	 * using IP 127.0.0.1, no XFF and no UA.
	 *
	 * @inheritDoc
	 */
	public function onCheckUserInsertPrivateEventRow(
		string &$ip, &$xff, array &$row, UserIdentity $user, ?RecentChange $rc
	) {
		$isTemp = $this->userNameUtils->isTemp( $user->getName() );
		if (
			$user->isRegistered() && !$isTemp &&
			$this->filterUser->getUserIdentity()->getId() == $user->getId()
		) {
			$ip = '127.0.0.1';
			$xff = false;
			$row['cupe_agent'] = '';
		}
	}
}
