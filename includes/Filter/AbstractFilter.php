<?php

namespace MediaWiki\Extension\AbuseFilter\Filter;

use Wikimedia\Assert\Assert;

/**
 * Immutable value object that represents a single filter. This object can be used to represent
 * filters that do not necessarily exist in the database. You'll usually want to use subclasses.
 */
class AbstractFilter {
	/** @var Specs */
	protected $specs;
	/** @var Flags */
	protected $flags;
	/**
	 * @var array[]|null Actions and parameters, can be lazy-loaded with $actionsCallback
	 */
	protected $actions;
	/**
	 * @var callable|null
	 * @todo Evaluate whether this can be avoided, e.g. by using a JOIN. This property also makes
	 *   the class not serializable.
	 */
	protected $actionsCallback;

	/**
	 * @param Specs $specs
	 * @param Flags $flags
	 * @param callable|array[] $actions Array with params or callable that will return them
	 * @phan-param array[]|callable():array[] $actions
	 */
	public function __construct(
		Specs $specs,
		Flags $flags,
		$actions
	) {
		$this->specs = clone $specs;
		$this->flags = clone $flags;
		Assert::parameterType( [ 'callable', 'array' ], $actions, '$actions' );
		if ( is_callable( $actions ) ) {
			$this->actionsCallback = $actions;
		} elseif ( is_array( $actions ) ) {
			$this->setActions( $actions );
		}
	}

	public function getSpecs(): Specs {
		return clone $this->specs;
	}

	public function getFlags(): Flags {
		return clone $this->flags;
	}

	public function getRules(): string {
		return $this->specs->getRules();
	}

	public function getComments(): string {
		return $this->specs->getComments();
	}

	public function getName(): string {
		return $this->specs->getName();
	}

	/**
	 * @note Callers should not rely on the order, because it's nondeterministic.
	 * @return string[]
	 */
	public function getActionsNames(): array {
		return $this->specs->getActionsNames();
	}

	public function getGroup(): string {
		return $this->specs->getGroup();
	}

	public function isEnabled(): bool {
		return $this->flags->getEnabled();
	}

	public function isDeleted(): bool {
		return $this->flags->getDeleted();
	}

	public function isHidden(): bool {
		return $this->flags->getHidden();
	}

	public function isProtected(): bool {
		return $this->flags->getProtected();
	}

	public function getPrivacyLevel(): int {
		return $this->flags->getPrivacyLevel();
	}

	public function isGlobal(): bool {
		return $this->flags->getGlobal();
	}

	/**
	 * @return array[]
	 */
	public function getActions(): array {
		if ( $this->actions === null ) {
			$this->setActions( ( $this->actionsCallback )() );
			// This is to ease testing
			$this->actionsCallback = null;
		}
		return $this->actions;
	}

	protected function setActions( array $actions ): void {
		$this->actions = $actions;
		$this->specs->setActionsNames( array_keys( $actions ) );
	}

	/**
	 * Make sure we don't leave any (writeable) reference
	 */
	public function __clone() {
		$this->specs = clone $this->specs;
		$this->flags = clone $this->flags;
	}

}
