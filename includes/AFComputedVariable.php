<?php

class AFComputedVariable {
	/**
	 * @var string The method used to compute the variable
	 */
	public $mMethod;
	/**
	 * @var array Parameters to be used with the specified method
	 */
	public $mParameters;

	/**
	 * @param string $method
	 * @param array $parameters
	 */
	public function __construct( $method, $parameters ) {
		$this->mMethod = $method;
		$this->mParameters = $parameters;
	}
}
