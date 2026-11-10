<?php

namespace MediaWiki\Extension\AbuseFilter\Consequences;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\Consequences\Consequence\Consequence;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\ServiceNames;
use RuntimeException;

class ConsequencesRegistry {
	public const SERVICE_NAME = ServiceNames::ConsequencesRegistry;

	public const CONSTRUCTOR_OPTIONS = [
		'AbuseFilterActions',
		'AbuseFilterActionRestrictions',
	];
	private const DANGEROUS_ACTIONS = [
		'block',
		'blockautopromote',
		'degroup',
		'rangeblock'
	];

	/** @var array<string,bool> */
	private readonly array $configActions;
	/** @var string[]|null */
	private ?array $dangerousActionsCache = null;
	/** @var array<string,callable>|null */
	private ?array $customActionsCache = null;
	/** @var string[]|null */
	private ?array $restrictedActionsCache = null;

	public function __construct(
		private readonly AbuseFilterHookRunner $hookRunner,
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->configActions = array_map(
			static fn ( $enabled ) => (bool)$enabled,
			$options->get( 'AbuseFilterActions' )
		);
	}

	/**
	 * Get an array of actions which harm the user.
	 *
	 * @return string[]
	 */
	public function getDangerousActionNames(): array {
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
	 * Filter a list of action names to include only dangerous actions.
	 *
	 * @param string[] $actionNames
	 * @return string[]
	 */
	public function filterDangerousActionNames( array $actionNames ): array {
		return array_values(
			array_intersect( $actionNames, $this->getDangerousActionNames() )
		);
	}

	/**
	 * @return string[]
	 */
	public function getAllActionNames(): array {
		return array_unique(
			array_merge(
				array_keys( $this->configActions ),
				array_keys( $this->getCustomActions() )
			)
		);
	}

	/**
	 * @return array<string,callable(Parameters,array):Consequence>
	 */
	public function getCustomActions(): array {
		if ( $this->customActionsCache === null ) {
			$this->customActionsCache = [];
			$this->hookRunner->onAbuseFilterCustomActions( $this->customActionsCache );
			$this->validateCustomActions();
		}
		return $this->customActionsCache;
	}

	/**
	 * Ensure that extensions aren't putting crap in this array, since we can't enforce types on closures otherwise
	 */
	private function validateCustomActions(): void {
		foreach ( $this->customActionsCache as $name => $cb ) {
			if ( !is_string( $name ) ) {
				throw new RuntimeException( 'Custom actions keys should be strings!' );
			}
			// Validating parameters and return value will happen later at runtime.
			if ( !is_callable( $cb ) ) {
				throw new RuntimeException( 'Custom actions values should be callables!' );
			}
		}
	}

	/**
	 * @return string[]
	 */
	public function getAllEnabledActionNames(): array {
		$disabledActions = array_keys( $this->configActions, false, true );
		return array_values( array_diff( $this->getAllActionNames(), $disabledActions ) );
	}

	/**
	 * Gets a list of restricted action names.
	 *
	 * @return string[]
	 */
	public function getRestrictedActionNames(): array {
		$this->restrictedActionsCache ??= array_keys(
			// Loose comparison: collect keys that have truthy values
			$this->options->get( 'AbuseFilterActionRestrictions' ), true, false
		);
		return $this->restrictedActionsCache;
	}

	/**
	 * Filter a list of action names to include only restricted actions.
	 *
	 * @param string[] $actionNames
	 * @return string[]
	 */
	public function filterRestrictedActionNames( array $actionNames ): array {
		return array_values( array_intersect( $actionNames, $this->getRestrictedActionNames() ) );
	}

	/**
	 * Checks whether the specified action is restricted.
	 */
	public function isRestrictedActionName( string $actionName ): bool {
		return in_array( $actionName, $this->getRestrictedActionNames(), true );
	}
}
