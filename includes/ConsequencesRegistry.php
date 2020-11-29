<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;

class ConsequencesRegistry {
	public const SERVICE_NAME = 'AbuseFilterConsequencesRegistry';

	private const DANGEROUS_ACTIONS = [
		'block',
		'blockautopromote',
		'degroup',
		'rangeblock'
	];

	/** @var AbuseFilterHookRunner */
	private $hookRunner;
	/** @var bool[] */
	private $configActions;
	/** @var callable[] */
	private $customHandlers;

	/** @var string[]|null */
	private $dangerousActionsCache;

	/**
	 * @param AbuseFilterHookRunner $hookRunner
	 * @param bool[] $configActions
	 * @param callable[] $customHandlers
	 */
	public function __construct(
		AbuseFilterHookRunner $hookRunner,
		array $configActions,
		array $customHandlers
	) {
		$this->hookRunner = $hookRunner;
		$this->configActions = $configActions;
		$this->customHandlers = $customHandlers;
	}

	/**
	 * Get an array of actions which harm the user.
	 *
	 * @return string[]
	 */
	public function getDangerousActionNames() : array {
		if ( $this->dangerousActionsCache === null ) {
			$extActions = [];
			$this->hookRunner->onAbuseFilterGetDangerousActions( $extActions );
			$this->dangerousActionsCache = array_unique(
				array_merge( $extActions, self::DANGEROUS_ACTIONS )
			);
		}
		return $this->dangerousActionsCache;
	}

	/**
	 * @return string[]
	 */
	public function getAllActionNames() : array {
		return array_unique(
			array_merge(
				array_keys( $this->configActions ),
				array_keys( $this->customHandlers )
			)
		);
	}
}
