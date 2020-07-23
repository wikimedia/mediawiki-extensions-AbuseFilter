<?php

use Psr\Log\LoggerInterface;

class AbuseFilterVariableHolder {
	/**
	 * Used in self::getVar() to determine what to do if the requested variable is missing
	 */
	public const GET_LAX = 0;
	public const GET_STRICT = 1;

	/** @var LoggerInterface */
	private $logger;

	/**
	 * @var (AFPData|AFComputedVariable)[]
	 * @fixme This should be private, but it isn't because of T231542: there are serialized instances
	 *  stored in the DB, and mVars wouldn't be available in HHVM after deserializing them (T213006)
	 */
	public $mVars = [];

	/** @var bool Whether this object is being used for an ongoing action being filtered */
	public $forFilter = false;

	/** @var int 2 is the default and means that new variables names (from T173889) should be used.
	 *    1 means that the old ones should be used, e.g. if this object is constructed from an
	 *    afl_var_dump which still bears old variables.
	 */
	public $mVarsVersion = 2;

	/**
	 * To avoid injecting a logger directly, since it's here only temporarily.
	 */
	public function __construct() {
		$this->logger = new Psr\Log\NullLogger();
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Utility function to translate an array with shape [ varname => value ] into a self instance
	 *
	 * @param array $vars
	 * @return AbuseFilterVariableHolder
	 */
	public static function newFromArray( array $vars ) : AbuseFilterVariableHolder {
		$ret = new self;
		foreach ( $vars as $var => $value ) {
			$ret->setVar( $var, $value );
		}
		return $ret;
	}

	/**
	 * @param string $variable
	 * @param mixed $datum
	 */
	public function setVar( $variable, $datum ) {
		$variable = strtolower( $variable );
		if ( !( $datum instanceof AFPData || $datum instanceof AFComputedVariable ) ) {
			$datum = AFPData::newFromPHPVar( $datum );
		}

		$this->mVars[$variable] = $datum;
	}

	/**
	 * Get all variables stored in this object
	 *
	 * @return (AFPData|AFComputedVariable)[]
	 */
	public function getVars() {
		return $this->mVars;
	}

	/**
	 * Get a lazy loader for a variable. This method is here for testing ease
	 * @param string $method
	 * @param array $parameters
	 * @return AFComputedVariable
	 */
	public function getLazyLoader( $method, $parameters ) {
		return new AFComputedVariable( $method, $parameters );
	}

	/**
	 * @param string $variable
	 * @param string $method
	 * @param array $parameters
	 */
	public function setLazyLoadVar( $variable, $method, $parameters ) {
		$placeholder = $this->getLazyLoader( $method, $parameters );
		$this->setVar( $variable, $placeholder );
	}

	/**
	 * Get a variable from the current object
	 *
	 * @param string $varName The variable name
	 * @param int $flags If self::GET_STRICT is set, this will throw if
	 *   the requested variable is not set. Otherwise it will return a DUNDEFINED AFPData.
	 *   NOTE: For now, it will return DUNDEFINED even with GET_STRICT.
	 * @param string|null $tempFilter Filter ID, if available; only used for debugging (temporarily)
	 * @return AFPData
	 */
	public function getVar( $varName, $flags = self::GET_STRICT, $tempFilter = null ) : AFPData {
		$varName = strtolower( $varName );
		$deprecatedVars = AbuseFilter::getDeprecatedVariables();
		if ( $this->mVarsVersion === 1 && in_array( $varName, $deprecatedVars ) ) {
			// Variables are stored with old names, but the parser has given us
			// a new name. Translate it back.
			$varName = array_search( $varName, $deprecatedVars );
		}

		if ( $this->varIsSet( $varName ) ) {
			/** @var $variable AFComputedVariable|AFPData */
			$variable = $this->mVars[$varName];
			if ( $variable instanceof AFComputedVariable ) {
				$value = $variable->compute( $this );
				$this->setVar( $varName, $value );
				return $value;
			} elseif ( $variable instanceof AFPData ) {
				return $variable;
			} else {
				throw new UnexpectedValueException(
					"Variable $varName has unexpected type " . gettype( $variable )
				);
			}
		} elseif ( !( $flags & self::GET_STRICT ) ) {
			return new AFPData( AFPData::DUNDEFINED );
		} else {
			$this->logger->warning(
				__METHOD__ . ": requested unset variable {varname} in strict mode, filter: {filter}",
				[
					'varname' => $varName,
					'exception' => new RuntimeException(),
					'filter' => $tempFilter ?? 'unavailable'
				]
			);
			// @todo change the line below to throw an exception in a future MW version
			return new AFPData( AFPData::DUNDEFINED );
		}
	}

	/**
	 * @param AbuseFilterVariableHolder ...$holders
	 * @return AbuseFilterVariableHolder
	 */
	public static function merge( AbuseFilterVariableHolder ...$holders ) {
		$newHolder = new AbuseFilterVariableHolder;
		$newHolder->addHolders( ...$holders );

		return $newHolder;
	}

	/**
	 * Merge any number of holders given as arguments into this holder.
	 *
	 * @param AbuseFilterVariableHolder ...$holders
	 */
	public function addHolders( AbuseFilterVariableHolder ...$holders ) {
		foreach ( $holders as $addHolder ) {
			$this->mVars = array_merge( $this->mVars, $addHolder->mVars );
		}
	}

	/**
	 * Export all variables stored in this object with their native (PHP) types.
	 *
	 * @return array
	 */
	public function exportAllVars() {
		$exported = [];
		foreach ( array_keys( $this->mVars ) as $varName ) {
			$exported[ $varName ] = $this->getVar( $varName )->toNative();
		}

		return $exported;
	}

	/**
	 * Export all non-lazy variables stored in this object as string
	 *
	 * @return string[]
	 */
	public function exportNonLazyVars() {
		$exported = [];
		foreach ( $this->mVars as $varName => $data ) {
			if ( !( $data instanceof AFComputedVariable ) ) {
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
		$coreVariables = [];

		if ( !$includeUserVars ) {
			// Compile a list of all variables set by the extension to be able
			// to filter user set ones by name
			$activeVariables = array_keys( AbuseFilter::getBuilderValues()['vars'] );
			$deprecatedVariables = array_keys( AbuseFilter::getDeprecatedVariables() );
			$disabledVariables = array_keys( AbuseFilter::DISABLED_VARS );
			$coreVariables = array_merge( $activeVariables, $deprecatedVariables, $disabledVariables );
			$coreVariables = array_map( 'strtolower', $coreVariables );
		}

		$exported = [];
		foreach ( array_keys( $this->mVars ) as $varName ) {
			$computeThis = ( is_array( $compute ) && in_array( $varName, $compute ) ) || $compute === true;
			if (
				( $includeUserVars || in_array( strtolower( $varName ), $coreVariables ) ) &&
				// Only include variables set in the extension in case $includeUserVars is false
				( $computeThis || $this->mVars[$varName] instanceof AFPData )
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
	public function varIsSet( $var ) {
		return array_key_exists( $var, $this->mVars );
	}

	/**
	 * Compute all vars which need DB access. Useful for vars which are going to be saved
	 * cross-wiki or used for offline analysis.
	 */
	public function computeDBVars() {
		static $dbTypes = [
			'links-from-wikitext-or-database',
			'load-recent-authors',
			'page-age',
			'get-page-restrictions',
			'simple-user-accessor',
			'user-age',
			'user-groups',
			'user-rights',
			'revision-text-by-id',
			'revision-text-by-timestamp'
		];

		/** @var AFComputedVariable[] $missingVars */
		$missingVars = array_filter( $this->mVars, function ( $el ) {
			return ( $el instanceof AFComputedVariable );
		} );
		foreach ( $missingVars as $name => $value ) {
			if ( in_array( $value->mMethod, $dbTypes ) ) {
				$value = $value->compute( $this );
				$this->setVar( $name, $value );
			}
		}
	}
}
