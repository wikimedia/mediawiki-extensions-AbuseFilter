<?php

namespace MediaWiki\Extension\AbuseFilter\Consequence;

use MediaWiki\Extension\AbuseFilter\Filter\Filter;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\User\UserIdentity;

/**
 * Immutable value object that provides "base" parameters to Consequence objects
 */
class Parameters {
	/** @var Filter */
	private $filter;

	/** @var bool */
	private $isGlobalFilter;

	/** @var UserIdentity */
	private $user;

	/** @var LinkTarget */
	private $target;

	/** @var string */
	private $action;

	/**
	 * @param Filter $filter
	 * @param bool $isGlobalFilter
	 * @param UserIdentity $user
	 * @param LinkTarget $target
	 * @param string $action
	 */
	public function __construct(
		Filter $filter,
		bool $isGlobalFilter,
		UserIdentity $user,
		LinkTarget $target,
		string $action
	) {
		$this->filter = $filter;
		$this->isGlobalFilter = $isGlobalFilter;
		$this->user = $user;
		$this->target = $target;
		$this->action = $action;
	}

	/**
	 * @return Filter
	 */
	public function getFilter(): Filter {
		return $this->filter;
	}

	/**
	 * @return bool
	 */
	public function getIsGlobalFilter(): bool {
		return $this->isGlobalFilter;
	}

	/**
	 * @return UserIdentity
	 */
	public function getUser(): UserIdentity {
		return $this->user;
	}

	/**
	 * @return LinkTarget
	 */
	public function getTarget(): LinkTarget {
		return $this->target;
	}

	/**
	 * @return string
	 */
	public function getAction(): string {
		return $this->action;
	}
}
