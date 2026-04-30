<?php

namespace MediaWiki\Extension\AbuseFilter\Filter;

use MediaWiki\User\UserIdentity;

/**
 * Immutable value object representing a "complete" filter. This can be used to represent filters
 * that already exist in the database, but you should probably use subclasses for that.
 */
class Filter extends AbstractFilter {

	protected LastEditInfo $lastEditInfo;

	/**
	 * @param Specs $specs
	 * @param Flags $flags
	 * @param array[]|callable():array[] $actions Array with params or callable that will return them
	 * @param LastEditInfo $lastEditInfo
	 * @param int|null $id Can be null if not specified
	 * @param int|null $hitCount Can be null if the filter is not current
	 * @param bool|null $throttled Can be null if the filter is not current
	 */
	public function __construct(
		Specs $specs,
		Flags $flags,
		$actions,
		LastEditInfo $lastEditInfo,
		protected ?int $id = null,
		protected ?int $hitCount = null,
		protected ?bool $throttled = null
	) {
		parent::__construct( $specs, $flags, $actions );
		$this->lastEditInfo = clone $lastEditInfo;
	}

	public function getLastEditInfo(): LastEditInfo {
		return clone $this->lastEditInfo;
	}

	public function getID(): ?int {
		return $this->id;
	}

	/**
	 * @deprecated since 1.45 Use ::getUserIdentity() instead
	 */
	public function getUserID(): int {
		return $this->lastEditInfo->getUserID();
	}

	/**
	 * @deprecated since 1.45 Use ::getUserIdentity() instead
	 */
	public function getUserName(): string {
		return $this->lastEditInfo->getUserName();
	}

	public function getUserIdentity(): UserIdentity {
		return $this->lastEditInfo->getUserIdentity();
	}

	public function getTimestamp(): string {
		return $this->lastEditInfo->getTimestamp();
	}

	public function getHitCount(): ?int {
		return $this->hitCount;
	}

	public function isThrottled(): ?bool {
		return $this->throttled;
	}

	/**
	 * Make sure we don't leave any (writeable) reference
	 */
	public function __clone() {
		parent::__clone();
		$this->lastEditInfo = clone $this->lastEditInfo;
	}
}
