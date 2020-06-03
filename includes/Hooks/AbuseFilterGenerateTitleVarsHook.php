<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks;

use AbuseFilterVariableHolder;
use RecentChange;
use Title;

interface AbuseFilterGenerateTitleVarsHook {
	/**
	 * Hook runner for the `AbuseFilter-generateTitleVars` hook
	 *
	 * Allows altering the variables generated for a title
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param Title $title
	 * @param string $prefix Variable name prefix
	 * @param ?RecentChange $rc If the variables should be generated for an RC entry,
	 *     this is the entry. Null if it's for the current action being filtered.
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onAbuseFilterGenerateTitleVars(
		AbuseFilterVariableHolder $vars,
		Title $title,
		string $prefix,
		?RecentChange $rc
	);
}
