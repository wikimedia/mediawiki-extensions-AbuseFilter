<?php

namespace MediaWiki\Extension\AbuseFilter\Filter;

use MediaWiki\User\UserIdentity;

/**
 * (Mutable) value object that holds information about the last edit to a filter.
 */
class LastEditInfo {

	public function __construct(
		private UserIdentity $userIdentity,
		private string $timestamp,
	) {
	}

	/**
	 * Set the UserIdentity
	 *
	 * @param UserIdentity $identity
	 */
	public function setUserIdentity( UserIdentity $identity ): void {
		$this->userIdentity = $identity;
	}

	/**
	 * Get the UserIdentity object
	 *
	 * @return UserIdentity
	 */
	public function getUserIdentity(): UserIdentity {
		return $this->userIdentity;
	}

	public function getUserName(): string {
		return $this->userIdentity->getName();
	}

	public function getUserID(): int {
		return $this->userIdentity->getId();
	}

	public function getTimestamp(): string {
		return $this->timestamp;
	}

	public function setTimestamp( string $timestamp ): void {
		$this->timestamp = $timestamp;
	}
}
