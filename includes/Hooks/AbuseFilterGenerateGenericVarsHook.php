<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks;

use AbuseFilterVariableHolder;
use RecentChange;

interface AbuseFilterGenerateGenericVarsHook {
	/**
	 * Hook runner for the `AbuseFilter-generateGenericVars` hook
	 *
	 * Allows altering generic variables, i.e. independent from page and user
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param ?RecentChange $rc If the variables should be generated for an RC entry,
	 *     this is the entry. Null if it's for the current action being filtered.
	 * @return bool|void True or no return value to continue or false to abort
	 */
	  public function onAbuseFilterGenerateGenericVars(
		AbuseFilterVariableHolder $vars,
		?RecentChange $rc
	);
}
