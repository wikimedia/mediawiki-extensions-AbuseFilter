<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\LazyVariableComputer;
use MediaWiki\Extension\AbuseFilter\Parser\AFPData;
use Psr\Log\LoggerInterface;

class AbuseFilterVariableHolder {
	/**
	 * Used in self::getVar() to determine what to do if the requested variable is missing. See
	 * the docs of that method for an explanation.
	 */
	public const GET_LAX = 0;
	public const GET_STRICT = 1;
	public const GET_BC = 2;

	/** @var KeywordsManager */
	private $keywordsManager;

	/** @var LoggerInterface */
	private $logger;

	/**
	 * Temporary hack. Only retrieve via getLazyComputer()
	 * @var LazyVariableComputer|null
	 */
	private $lazyComputer;

	/**
	 * @var (AFPData|AFComputedVariable)[]
	 */
	private $mVars = [];

	/** @var bool Whether this object is being used for an ongoing action being filtered */
	public $forFilter = false;

	/**
	 * @param KeywordsManager|null $keywordsManager Optional for BC
	 */
	public function __construct( KeywordsManager $keywordsManager = null ) {
		$this->keywordsManager = $keywordsManager ?? AbuseFilterServices::getKeywordsManager();
		// Avoid injecting a Logger, as it's just temporary
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
	 * @param KeywordsManager|null $keywordsManager Optional for BC
	 * @return AbuseFilterVariableHolder
	 */
	public static function newFromArray(
		array $vars,
		KeywordsManager $keywordsManager = null
	) : AbuseFilterVariableHolder {
		$ret = new self( $keywordsManager );
		foreach ( $vars as $var => $value ) {
			$ret->setVar( $var, $value );
		}
		return $ret;
	}

	/**
	 * Checks whether any deprecated variable is stored with the old name, and replaces it with
	 * the new name. This should normally only happen when a DB dump is retrieved from the DB.
	 */
	public function translateDeprecatedVars() : void {
		$deprecatedVars = $this->keywordsManager->getDeprecatedVariables();
		foreach ( $this->mVars as $name => $value ) {
			if ( array_key_exists( $name, $deprecatedVars ) ) {
				$this->mVars[ $deprecatedVars[$name] ] = $value;
				unset( $this->mVars[$name] );
			}
		}
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
	 * @param int $mode One of the self::GET_* constants, determines how to behave when the variable is unset:
	 *  - GET_STRICT -> In the future, this will throw an exception. For now it returns a DUNDEFINED and logs a warning
	 *  - GET_LAX -> Return a DUNDEFINED AFPData
	 *  - GET_BC -> Return a DNULL AFPData (this should only be used for BC, see T230256)
	 * @param string|null $tempFilter Filter ID, if available; only used for debugging (temporarily)
	 * @return AFPData
	 */
	public function getVar( $varName, $mode = self::GET_STRICT, $tempFilter = null ) : AFPData {
		$varName = strtolower( $varName );
		if ( $this->varIsSet( $varName ) ) {
			/** @var $variable AFComputedVariable|AFPData */
			$variable = $this->mVars[$varName];
			if ( $variable instanceof AFComputedVariable ) {
				$value = $this->getLazyComputer()->compute( $variable, $this );
				$this->setVar( $varName, $value );
				return $value;
			} elseif ( $variable instanceof AFPData ) {
				return $variable;
			} else {
				throw new UnexpectedValueException(
					"Variable $varName has unexpected type " . gettype( $variable )
				);
			}
		}

		// The variable is not set.
		switch ( $mode ) {
			case self::GET_STRICT:
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
			case self::GET_LAX:
				return new AFPData( AFPData::DUNDEFINED );
			case self::GET_BC:
				// Old behaviour, which can sometimes lead to unexpected results (e.g.
				// `edit_delta < -5000` will match any non-edit action).
				return new AFPData( AFPData::DNULL );
			default:
				throw new LogicException( "Mode '$mode' not recognized." );
		}
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
	 * @return-taint none
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
			$activeVariables = array_keys( $this->keywordsManager->getVarsMappings() );
			$deprecatedVariables = array_keys( $this->keywordsManager->getDeprecatedVariables() );
			$disabledVariables = array_keys( $this->keywordsManager->getDisabledVariables() );
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
			'revision-text-by-id',
		];

		/** @var AFComputedVariable[] $missingVars */
		$missingVars = array_filter( $this->mVars, function ( $el ) {
			return ( $el instanceof AFComputedVariable );
		} );
		foreach ( $missingVars as $name => $var ) {
			if ( in_array( $var->mMethod, $dbTypes ) ) {
				$value = $this->getLazyComputer()->compute( $var, $this );
				$this->setVar( $name, $value );
			}
		}
	}

	/**
	 * Temporary hack.
	 * @return LazyVariableComputer
	 */
	public function getLazyComputer() : LazyVariableComputer {
		return $this->lazyComputer ?? AbuseFilterServices::getLazyVariableComputer();
	}

	/**
	 * Temporary hack.
	 * @param LazyVariableComputer $computer
	 */
	public function setLazyComputer( LazyVariableComputer $computer ) : void {
		$this->lazyComputer = $computer;
	}
}
