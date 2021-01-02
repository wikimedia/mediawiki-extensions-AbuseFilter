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
	AbuseFilterCustomActionsHook,
	AbuseFilterDeprecatedVariablesHook,
	AbuseFilterFilterActionHook,
	AbuseFilterGenerateGenericVarsHook,
	AbuseFilterGenerateTitleVarsHook,
	AbuseFilterGenerateUserVarsHook,
	AbuseFilterInterceptVariableHook,
	AbuseFilterShouldFilterActionHook,
	AbuseFilterGetDangerousActionsHook
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
	 * @inheritDoc
	 */
	public function onAbuseFilterBuilder( array &$realValues ) {
		return $this->hookContainer->run(
			'AbuseFilter-builder',
			[ &$realValues ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterDeprecatedVariables( array &$deprecatedVariables ) {
		return $this->hookContainer->run(
			'AbuseFilter-deprecatedVariables',
			[ &$deprecatedVariables ]
		);
	}

	/**
	 * @inheritDoc
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
	 * @inheritDoc
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
	 * @inheritDoc
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
	 * @inheritDoc
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
	 * @inheritDoc
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
	 * @inheritDoc
	 */
	public function onAbuseFilterGenerateUserVars(
		AbuseFilterVariableHolder $vars,
		User $user,
		?RecentChange $rc
	) {
		return $this->hookContainer->run(
			'AbuseFilter-generateUserVars',
			[ $vars, $user, $rc ]
		);
	}

	/**
	 * @inheritDoc
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
	 * @inheritDoc
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
	 * @inheritDoc
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

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterGetDangerousActions( array &$actions ) : void {
		$this->hookContainer->run(
			'AbuseFilterGetDangerousActions',
			[ &$actions ],
			[ 'abortable' => false ]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function onAbuseFilterCustomActions( array &$actions ) : void {
		$this->hookContainer->run(
			'AbuseFilterCustomActions',
			[ &$actions ],
			[ 'abortable' => false ]
		);
	}
}
