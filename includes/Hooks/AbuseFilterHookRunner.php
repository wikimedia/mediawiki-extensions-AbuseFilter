<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks;

use AbuseFilterVariableHolder;
use Content;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\MediaWikiServices;
use RecentChange;
use Title;
use User;

/**
 * Handle running AbuseFilter's hooks
 * @author DannyS712
 */
class AbuseFilterHookRunner implements
	AbuseFilterAlterVariablesHook,
	AbuseFilterBuilderHook,
	AbuseFilterComputeVariableHook,
	AbuseFilterContentToStringHook,
	AbuseFilterDeprecatedVariablesHook,
	AbuseFilterFilterActionHook,
	AbuseFilterGenerateGenericVarsHook,
	AbuseFilterGenerateTitleVarsHook,
	AbuseFilterGenerateUserVarsHook,
	AbuseFilterInterceptVariableHook,
	AbuseFilterShouldFilterActionHook
{

	/** @var HookContainer */
	private $hookContainer;

	/**
	 * @param HookContainer $hookContainer
	 */
	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
	}

	/**
	 * Convenience getter for static contexts
	 *
	 * See also core's Hooks::runner
	 *
	 * @return AbuseFilterHookRunner
	 */
	public static function getRunner() : AbuseFilterHookRunner {
		return new AbuseFilterHookRunner(
			MediaWikiServices::getInstance()->getHookContainer()
		);
	}

	/**
	 * Hook runner for the `AbuseFilter-builder` hook
	 *
	 * Allows overwriting of the builder values returned by AbuseFilter::getBuilderValues
	 *
	 * @param array &$realValues Builder values
	 * @return bool|void
	 */
	public function onAbuseFilterBuilder( array &$realValues ) {
		return $this->hookContainer->run(
			'AbuseFilter-builder',
			[ &$realValues ]
		);
	}

	/**
	 * Hook runner for the `AbuseFilter-deprecatedVariables` hook
	 *
	 * Allows adding deprecated variables. If a filter uses an old variable, the parser
	 * will automatically translate it to the new one.
	 *
	 * @param array &$deprecatedVariables deprecated variables, syntax: [ 'old_name' => 'new_name' ]
	 * @return bool|void
	 */
	public function onAbuseFilterDeprecatedVariables( array &$deprecatedVariables ) {
		return $this->hookContainer->run(
			'AbuseFilter-deprecatedVariables',
			[ &$deprecatedVariables ]
		);
	}

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
	 * @return bool|void
	 */
	public function onAbuseFilterComputeVariable(
		string $method,
		AbuseFilterVariableHolder $vars,
		array $parameters,
		?string &$result
	) {
		return $this->hookContainer->run(
			'AbuseFilter-computeVariable',
			[ $method, $vars, $parameters, &$result ]
		);
	}

	/**
	 * Hook runner for the `AbuseFilter-contentToString` hook
	 *
	 * Called when converting a Content object to a string to which
	 * filters can be applied. If the hook function returns true, Content::getTextForSearchIndex()
	 * will be used for non-text content.
	 *
	 * @param Content $content
	 * @param ?string &$text
	 * @return bool|void
	 */
	public function onAbuseFilterContentToString(
		Content $content,
		?string &$text
	) {
		return $this->hookContainer->run(
			'AbuseFilter-contentToString',
			[ $content, &$text ]
		);
	}

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
	 * @return bool|void
	 */
	public function onAbuseFilterFilterAction(
		AbuseFilterVariableHolder &$vars,
		Title $title
	) {
		return $this->hookContainer->run(
			'AbuseFilter-filterAction',
			[ &$vars, $title ]
		);
	}

	/**
	 * Hook runner for the `AbuseFilterAlterVariables` hook
	 *
	 * Allows overwriting of abusefilter variables just before they're
	 * checked against filters. Note that you may specify custom variables in a saner way using other hooks:
	 * AbuseFilter-generateTitleVars, AbuseFilter-generateUserVars and AbuseFilter-generateGenericVars.
	 *
	 * @param AbuseFilterVariableHolder &$vars
	 * @param Title $title Title object target of the action
	 * @param User $user User object performer of the action
	 * @return bool|void
	 */
	public function onAbuseFilterAlterVariables(
		AbuseFilterVariableHolder &$vars,
		Title $title,
		User $user
	) {
		return $this->hookContainer->run(
			'AbuseFilterAlterVariables',
			[ &$vars, $title, $user ]
		);
	}

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
	 * @return bool|void
	 */
	public function onAbuseFilterGenerateTitleVars(
		AbuseFilterVariableHolder $vars,
		Title $title,
		string $prefix,
		?RecentChange $rc
	) {
		return $this->hookContainer->run(
			'AbuseFilter-generateTitleVars',
			[ $vars, $title, $prefix, $rc ]
		);
	}

	/**
	 * Hook runner for the `AbuseFilter-generateUserVars` hook
	 *
	 * Allows altering the variables generated for a specific user
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param User $user
	 * @param ?RecentChange $rc If the variables should be generated for an RC entry,
	 *     this is the entry. Null if it's for the current action being filtered.
	 * @return bool|void
	 */
	public function onAbuseFilterGenerateUserVars(
		AbuseFilterVariableHolder $vars,
		User $user,
		?RecentChange $rc
	) {
		return $this->hookContainer->run(
			'AbuseFilter-generateTitleVars',
			[ $vars, $user, $rc ]
		);
	}

	/**
	 * Hook runner for the `AbuseFilter-generateGenericVars` hook
	 *
	 * Allows altering generic variables, i.e. independent from page and user
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param ?RecentChange $rc If the variables should be generated for an RC entry,
	 *     this is the entry. Null if it's for the current action being filtered.
	 * @return bool|void
	 */
	public function onAbuseFilterGenerateGenericVars(
		AbuseFilterVariableHolder $vars,
		?RecentChange $rc
	) {
		return $this->hookContainer->run(
			'AbuseFilter-generateGenericVars',
			[ $vars, $rc ]
		);
	}

	/**
	 * Hook runner for the `AbuseFilter-interceptVariable` hook
	 *
	 * Called before a variable is set in AFComputedVariable::compute to be able to set
	 * it before the core code runs. Return false to make the function return right after.
	 *
	 * @param string $method Method to generate the variable
	 * @param AbuseFilterVariableHolder $vars
	 * @param array $parameters Parameters with data to compute the value
	 * @param mixed &$result Result of the computation
	 * @return bool|void
	 */
	public function onAbuseFilterInterceptVariable(
		string $method,
		AbuseFilterVariableHolder $vars,
		array $parameters,
		&$result
	) {
		return $this->hookContainer->run(
			'AbuseFilter-interceptVariable',
			[ $method, $vars, $parameters, &$result ]
		);
	}

	/**
	 * Hook runner for the `AbuseFilterShouldFilterAction` hook
	 *
	 * Called before filtering an action. If the current action should not be filtered,
	 * return false and add a useful reason to $skipReasons.
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param Title $title Title object target of the action
	 * @param User $user User object performer of the action
	 * @param array &$skipReasons Array of reasons why the action should be skipped
	 * @return bool|void
	 */
	public function onAbuseFilterShouldFilterAction(
		AbuseFilterVariableHolder $vars,
		Title $title,
		User $user,
		array &$skipReasons
	) {
		return $this->hookContainer->run(
			'AbuseFilterShouldFilterAction',
			[ $vars, $title, $user, &$skipReasons ]
		);
	}

}
