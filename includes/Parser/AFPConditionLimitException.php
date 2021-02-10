<?php

namespace MediaWiki\Extension\AbuseFilter\Parser;

/**
 * Exceptions thrown upon reaching the condition limit of the AbuseFilter parser.
 */
class AFPConditionLimitException extends AFPException {
	public function __construct() {
		parent::__construct( 'Condition limit reached.' );
	}
}
