<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks;

use AbuseFilterVariableHolder;

interface AbuseFilterComputeVariableHook {
	/**
	 * Hook runner for the `AbuseFilter-computeVariable` hook
	 *
	 * Like AbuseFilter-interceptVariable but called if the requested method wasn't found.
	 * Return true to indicate that the method is known to the hook and was computed successful.
	 *
	 * @param string $method Method to generate the variable
	 * @param AbuseFilterVariableHolder $vars
	 * @param array $parameters Parameters with data to compute the value
	 * @param ?string &$result Result of the computation
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onAbuseFilterComputeVariable(
		string $method,
		AbuseFilterVariableHolder $vars,
		array $parameters,
		?string &$result
	);
}
