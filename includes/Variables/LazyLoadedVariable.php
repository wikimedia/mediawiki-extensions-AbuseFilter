<?php

namespace MediaWiki\Extension\AbuseFilter\Variables;

class LazyLoadedVariable {
	/**
	 * @var string The method used to compute the variable
	 */
	private $method;
	/**
	 * @var array Parameters to be used with the specified method
	 */
	private $parameters;

	public function __construct( string $method, array $parameters ) {
		$this->method = $method;
		$this->parameters = $parameters;
	}

	public function getMethod(): string {
		return $this->method;
	}

	public function getParameters(): array {
		return $this->parameters;
	}
}
