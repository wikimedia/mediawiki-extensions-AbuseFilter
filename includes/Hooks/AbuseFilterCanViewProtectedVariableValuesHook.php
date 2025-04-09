<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks;

use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionStatus;
use MediaWiki\Permissions\Authority;

interface AbuseFilterCanViewProtectedVariableValuesHook {
	/**
	 * Called when determining if the user can view the values of the specified protected variables.
	 *
	 * Implement this hook to define additional restrictions on viewing the value of protected
	 * variable(s).
	 *
	 * @since 1.44
	 * @unstable Will be removed before 1.44 release
	 * @param Authority $performer The user viewing the protected variable values.
	 * @param string[] $variables The protected variables that are being viewed.
	 * @param AbuseFilterPermissionStatus $status Modify this status to make it fatal if user does
	 *   not meet the additional restrictions. You can call {@link AbuseFilterPermissionStatus::setBlock}
	 *   and {@link AbuseFilterPermissionStatus::setPermission} where relevant.
	 */
	public function onAbuseFilterCanViewProtectedVariableValues(
		Authority $performer, array $variables, AbuseFilterPermissionStatus $status
	): void;
}
