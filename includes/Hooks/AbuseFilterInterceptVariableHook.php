<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks;

use MediaWiki\Extension\AbuseFilter\Variables\AbuseFilterVariableHolder;

interface AbuseFilterInterceptVariableHook {
	/**
	 * Hook runner for the `AbuseFilter-interceptVariable` hook
	 *
	 * Called before a lazy-loaded variable is computed to be able to set
	 * it before the core code runs. Return false to make the function return right after.
	 *
	 * @param string $method Method to generate the variable
	 * @param AbuseFilterVariableHolder $vars
	 * @param array $parameters Parameters with data to compute the value
	 * @param mixed &$result Result of the computation
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onAbuseFilterInterceptVariable(
		string $method,
		AbuseFilterVariableHolder $vars,
		array $parameters,
		&$result
	);
}
