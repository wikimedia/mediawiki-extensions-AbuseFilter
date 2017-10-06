<?php

class AbuseFilterVariableHolder {
	public $mVars = [];

	public static $varBlacklist = [ 'context' ];

	public function __construct() {
		// Backwards-compatibility (unused now)
		$this->setVar( 'minor_edit', false );
	}

	/**
	 * @param string $variable
	 * @param mixed $datum
	 */
	function setVar( $variable, $datum ) {
		$variable = strtolower( $variable );
		if ( !( $datum instanceof AFPData || $datum instanceof AFComputedVariable ) ) {
			$datum = AFPData::newFromPHPVar( $datum );
		}

		$this->mVars[$variable] = $datum;
	}

	/**
	 * @param string $variable
	 * @param string $method
	 * @param array $parameters
	 */
	function setLazyLoadVar( $variable, $method, $parameters ) {
		$placeholder = new AFComputedVariable( $method, $parameters );
		$this->setVar( $variable, $placeholder );
	}

	/**
	 * Get a variable from the current object
	 *
	 * @param string $variable
	 * @return AFPData
	 */
	function getVar( $variable ) {
		$variable = strtolower( $variable );
		if ( isset( $this->mVars[$variable] ) ) {
			if ( $this->mVars[$variable] instanceof AFComputedVariable ) {
				$value = $this->mVars[$variable]->compute( $this );
				$this->setVar( $variable, $value );
				return $value;
			} elseif ( $this->mVars[$variable] instanceof AFPData ) {
				return $this->mVars[$variable];
			}
		}
		return new AFPData();
	}

	/**
	 * @return AbuseFilterVariableHolder
	 */
	public static function merge() {
		$newHolder = new AbuseFilterVariableHolder;
		call_user_func_array( [ $newHolder, "addHolders" ], func_get_args() );

		return $newHolder;
	}

	/**
	 * @param self $addHolder
	 * @throws MWException
	 * @deprecated use addHolders() instead
	 */
	public function addHolder( $addHolder ) {
		$this->addHolders( $addHolder );
	}

	/**
	 * Merge any number of holders given as arguments into this holder.
	 *
	 * @throws MWException
	 */
	public function addHolders() {
		$holders = func_get_args();

		foreach ( $holders as $addHolder ) {
			if ( !is_object( $addHolder ) ) {
				throw new MWException( 'Invalid argument to AbuseFilterVariableHolder::addHolders' );
			}
			$this->mVars = array_merge( $this->mVars, $addHolder->mVars );
		}
	}

	function __wakeup() {
		// Reset the context.
		$this->setVar( 'context', 'stored' );
	}

	/**
	 * Export all variables stored in this object as string
	 *
	 * @return string[]
	 */
	function exportAllVars() {
		$exported = [];
		foreach ( array_keys( $this->mVars ) as $varName ) {
			if ( !in_array( $varName, self::$varBlacklist ) ) {
				$exported[$varName] = $this->getVar( $varName )->toString();
			}
		}

		return $exported;
	}

	/**
	 * Export all non-lazy variables stored in this object as string
	 *
	 * @return string[]
	 */
	function exportNonLazyVars() {
		$exported = [];
		foreach ( $this->mVars as $varName => $data ) {
			if (
				!( $data instanceof AFComputedVariable )
				&& !in_array( $varName, self::$varBlacklist )
			) {
				$exported[$varName] = $this->getVar( $varName )->toString();
			}
		}

		return $exported;
	}

	/**
	 * Dump all variables stored in this object in their native types.
	 * If you want a not yet set variable to be included in the results you can
	 * either set $compute to an array with the name of the variable or set
	 * $compute to true to compute all not yet set variables.
	 *
	 * @param array|bool $compute Variables we should copute if not yet set
	 * @param bool $includeUserVars Include user set variables
	 * @return array
	 */
	public function dumpAllVars( $compute = [], $includeUserVars = false ) {
		$allVarNames = array_keys( $this->mVars );
		$exported = [];
		$coreVariables = [];

		if ( !$includeUserVars ) {
			// Compile a list of all variables set by the extension to be able
			// to filter user set ones by name
			global $wgRestrictionTypes;

			$coreVariables = AbuseFilter::getBuilderValues();
			$coreVariables = array_keys( $coreVariables['vars'] );

			// Title vars can have several prefixes
			$prefixes = [ 'ARTICLE', 'MOVED_FROM', 'MOVED_TO' ];
			$titleVars = [
				'_ARTICLEID',
				'_NAMESPACE',
				'_TEXT',
				'_PREFIXEDTEXT',
				'_recent_contributors'
			];
			foreach ( $wgRestrictionTypes as $action ) {
				$titleVars[] = "_restrictions_$action";
			}

			foreach ( $titleVars as $var ) {
				foreach ( $prefixes as $prefix ) {
					$coreVariables[] = $prefix . $var;
				}
			}
			$coreVariables = array_map( 'strtolower', $coreVariables );
		}

		foreach ( $allVarNames as $varName ) {
			if (
				( $includeUserVars || in_array( strtolower( $varName ), $coreVariables ) ) &&
				// Only include variables set in the extension in case $includeUserVars is false
				!in_array( $varName, self::$varBlacklist ) &&
				( $compute === true ||
					( is_array( $compute ) && in_array( $varName, $compute ) ) ||
					$this->mVars[$varName] instanceof AFPData
				)
			) {
				$exported[$varName] = $this->getVar( $varName )->toNative();
			}
		}

		return $exported;
	}

	/**
	 * @param string $var
	 * @return bool
	 */
	function varIsSet( $var ) {
		return array_key_exists( $var, $this->mVars );
	}

	/**
	 * Compute all vars which need DB access. Useful for vars which are going to be saved
	 * cross-wiki or used for offline analysis.
	 */
	function computeDBVars() {
		static $dbTypes = [
			'links-from-wikitext-or-database',
			'load-recent-authors',
			'get-page-restrictions',
			'simple-user-accessor',
			'user-age',
			'user-groups',
			'user-rights',
			'revision-text-by-id',
			'revision-text-by-timestamp'
		];

		foreach ( $this->mVars as $name => $value ) {
			if ( $value instanceof AFComputedVariable &&
				in_array( $value->mMethod, $dbTypes )
			) {
				$value = $value->compute( $this );
				$this->setVar( $name, $value );
			}
		}
	}
}
