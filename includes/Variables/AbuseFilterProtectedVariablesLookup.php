<?php

namespace MediaWiki\Extension\AbuseFilter\Variables;

use MediaWiki\Config\ServiceOptions;

/**
 * This service is used to generate the list of variables which are protected variables.
 *
 * @unstable
 */
class AbuseFilterProtectedVariablesLookup {
	public const SERVICE_NAME = 'AbuseFilterProtectedVariablesLookup';

	public const CONSTRUCTOR_OPTIONS = [
		'AbuseFilterProtectedVariables',
	];

	private ServiceOptions $options;

	public function __construct( ServiceOptions $options ) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
	}

	/**
	 * Returns an array of all variables which are considered protected variables, and therefore can only be used
	 * in protected filters.
	 *
	 * @return string[]
	 */
	public function getAllProtectedVariables(): array {
		return $this->options->get( 'AbuseFilterProtectedVariables' );
	}
}
