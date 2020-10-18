<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Parser\AFPData;
use MediaWiki\Extension\AbuseFilter\UnsetVariableException;
use MediaWiki\Extension\AbuseFilter\VariablesManager;

/**
 * Mutable value object that holds a list of variables
 */
class AbuseFilterVariableHolder {
	/**
	 * @var (AFPData|AFComputedVariable)[]
	 */
	private $mVars = [];

	/** @var bool Whether this object is being used for an ongoing action being filtered */
	public $forFilter = false;

	/**
	 * Utility function to translate an array with shape [ varname => value ] into a self instance
	 *
	 * @param array $vars
	 * @return AbuseFilterVariableHolder
	 */
	public static function newFromArray( array $vars ) : AbuseFilterVariableHolder {
		$ret = new self();
		foreach ( $vars as $var => $value ) {
			$ret->setVar( $var, $value );
		}
		return $ret;
	}

	/**
	 * @param string $variable
	 * @param mixed $datum
	 */
	public function setVar( string $variable, $datum ) : void {
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
	public function getVars() : array {
		return $this->mVars;
	}

	/**
	 * @param string $variable
	 * @param string $method
	 * @param array $parameters
	 */
	public function setLazyLoadVar( string $variable, string $method, array $parameters ) : void {
		$placeholder = new AFComputedVariable( $method, $parameters );
		$this->setVar( $variable, $placeholder );
	}

	/**
	 * @deprecated since 1.36 Use VariablesManager
	 * @param $varName
	 * @param int $mode
	 * @return AFPData
	 */
	public function getVar( $varName, $mode = VariablesManager::GET_STRICT ) : AFPData {
		$varManager = AbuseFilterServices::getVariablesManager();
		return $varManager->getVar( $this, $varName, $mode );
	}

	/**
	 * Get a variable from the current object, or throw if not set
	 *
	 * @param string $varName The variable name
	 * @return AFPData|AFComputedVariable
	 */
	public function getVarThrow( string $varName ) {
		$varName = strtolower( $varName );
		if ( !$this->varIsSet( $varName ) ) {
			throw new UnsetVariableException( $varName );
		}
		return $this->mVars[$varName];
	}

	/**
	 * A stronger version of self::getVarThrow that also asserts that the variable was computed
	 * @param string $varName
	 * @return AFPData
	 * @codeCoverageIgnore
	 */
	public function getComputedVariable( string $varName ) : AFPData {
		return $this->getVarThrow( $varName );
	}

	/**
	 * Merge any number of holders given as arguments into this holder.
	 *
	 * @param AbuseFilterVariableHolder ...$holders
	 */
	public function addHolders( AbuseFilterVariableHolder ...$holders ) : void {
		foreach ( $holders as $addHolder ) {
			$this->mVars = array_merge( $this->mVars, $addHolder->mVars );
		}
	}

	/**
	 * @param string $var
	 * @return bool
	 */
	public function varIsSet( string $var ) : bool {
		return array_key_exists( $var, $this->mVars );
	}

	/**
	 * @param string $varName
	 */
	public function removeVar( string $varName ) : void {
		unset( $this->mVars[$varName] );
	}
}
