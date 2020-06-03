<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks;

use AbuseFilterVariableHolder;
use Title;

interface AbuseFilterFilterActionHook {
	/**
	 * Hook runner for the `AbuseFilter-filterAction` hook
	 *
	 * DEPRECATED! Use AbuseFilterAlterVariables instead.
	 *
	 * Allows overwriting of abusefilter variables in AbuseFilter::filterAction just before they're
	 * checked against filters. Note that you may specify custom variables in a saner way using other hooks:
	 * AbuseFilter-generateTitleVars, AbuseFilter-generateUserVars and AbuseFilter-generateGenericVars.
	 *
	 * @param AbuseFilterVariableHolder &$vars
	 * @param Title $title
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onAbuseFilterFilterAction(
		AbuseFilterVariableHolder &$vars,
		Title $title
	);
}
