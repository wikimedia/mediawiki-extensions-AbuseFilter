<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use InvalidArgumentException;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPreAuthenticationProvider;

/**
 * This class runs a callback when the extension is registered, right after configuration has been
 * loaded (not really a hook, but almost).
 * @codeCoverageIgnore Mainly deprecation warnings and other things that can be tested by running the updater
 */
class RegistrationCallback {

	public static function onRegistration() : void {
		global $wgAuthManagerAutoConfig, $wgActionFilteredLogs, $wgAbuseFilterProfile,
			   $wgAbuseFilterProfiling, $wgAbuseFilterPrivateLog, $wgAbuseFilterForceSummary,
			   $wgGroupPermissions, $wgAbuseFilterRestrictions, $wgAbuseFilterDisallowGlobalLocalBlocks,
			   $wgAbuseFilterActionRestrictions, $wgAbuseFilterLocallyDisabledGlobalActions,
			   $wgAbuseFilterAflFilterMigrationStage;

		// @todo Remove this in a future release (added in 1.33)
		if ( isset( $wgAbuseFilterProfile ) || isset( $wgAbuseFilterProfiling ) ) {
			wfWarn( '$wgAbuseFilterProfile and $wgAbuseFilterProfiling have been removed and ' .
				'profiling is now enabled by default.' );
		}

		if ( isset( $wgAbuseFilterPrivateLog ) ) {
			global $wgAbuseFilterLogPrivateDetailsAccess;
			$wgAbuseFilterLogPrivateDetailsAccess = $wgAbuseFilterPrivateLog;
			wfWarn( '$wgAbuseFilterPrivateLog has been renamed to $wgAbuseFilterLogPrivateDetailsAccess. ' .
				'Please make the change in your settings; the format is identical.'
			);
		}
		if ( isset( $wgAbuseFilterForceSummary ) ) {
			global $wgAbuseFilterPrivateDetailsForceReason;
			$wgAbuseFilterPrivateDetailsForceReason = $wgAbuseFilterForceSummary;
			wfWarn( '$wgAbuseFilterForceSummary has been renamed to ' .
				'$wgAbuseFilterPrivateDetailsForceReason. Please make the change in your settings; ' .
				'the format is identical.'
			);
		}

		$found = false;
		foreach ( $wgGroupPermissions as &$perms ) {
			if ( array_key_exists( 'abusefilter-private', $perms ) ) {
				$perms['abusefilter-privatedetails'] = $perms[ 'abusefilter-private' ];
				unset( $perms[ 'abusefilter-private' ] );
				$found = true;
			}
			if ( array_key_exists( 'abusefilter-private-log', $perms ) ) {
				$perms['abusefilter-privatedetails-log'] = $perms[ 'abusefilter-private-log' ];
				unset( $perms[ 'abusefilter-private-log' ] );
				$found = true;
			}
		}
		unset( $perms );

		if ( $found ) {
			wfWarn( 'The group permissions "abusefilter-private-log" and "abusefilter-private" have ' .
				'been renamed, respectively, to "abusefilter-privatedetails-log" and ' .
				'"abusefilter-privatedetails". Please update the names in your settings.'
			);
		}

		// @todo Remove this in a future release (added in 1.36)
		if ( isset( $wgAbuseFilterDisallowGlobalLocalBlocks ) ) {
			wfWarn( '$wgAbuseFilterDisallowGlobalLocalBlocks has been removed and replaced by ' .
				'$wgAbuseFilterLocallyDisabledGlobalActions. You can now specify which actions to disable. ' .
				'If you had set the former to true, you should set to true all of the actions in ' .
				'$wgAbuseFilterRestrictions (if you were manually setting the variable) or ' .
				'ConsequencesRegistry::DANGEROUS_ACTIONS. ' .
				'If you had set it to false (or left the default), just remove it from your wiki settings.'
			);
			if ( $wgAbuseFilterDisallowGlobalLocalBlocks === true ) {
				$wgAbuseFilterLocallyDisabledGlobalActions = [
					'throttle' => false,
					'warn' => false,
					'disallow' => false,
					'blockautopromote' => true,
					'block' => true,
					'rangeblock' => true,
					'degroup' => true,
					'tag' => false
				];
			}
		}

		// @todo Remove this in a future release (added in 1.36)
		if ( isset( $wgAbuseFilterRestrictions ) ) {
			wfWarn( '$wgAbuseFilterRestrictions has been renamed to $wgAbuseFilterActionRestrictions.' );
			$wgAbuseFilterActionRestrictions = $wgAbuseFilterRestrictions;
		}

		$wgAuthManagerAutoConfig['preauth'][AbuseFilterPreAuthenticationProvider::class] = [
			'class' => AbuseFilterPreAuthenticationProvider::class,
			// Run after normal preauth providers to keep the log cleaner
			'sort' => 5,
		];

		$wgActionFilteredLogs['suppress'] = array_merge(
			$wgActionFilteredLogs['suppress'],
			// Message: log-action-filter-suppress-abuselog
			[ 'abuselog' => [ 'hide-afl', 'unhide-afl' ] ]
		);
		$wgActionFilteredLogs['rights'] = array_merge(
			$wgActionFilteredLogs['rights'],
			// Messages: log-action-filter-rights-blockautopromote,
			// log-action-filter-rights-restoreautopromote
			[
				'blockautopromote' => [ 'blockautopromote' ],
				'restoreautopromote' => [ 'restoreautopromote' ]
			]
		);

		if ( strpos( $wgAbuseFilterAflFilterMigrationStage, 'Bogus value' ) !== false ) {
			// Set the value here, because extension.json is very unfriendly towards PHP constants
			$wgAbuseFilterAflFilterMigrationStage = SCHEMA_COMPAT_NEW;
		}
		$stage = $wgAbuseFilterAflFilterMigrationStage;
		// Validation for the afl_filter migration stage, stolen from ActorMigration
		if ( ( $stage & SCHEMA_COMPAT_WRITE_BOTH ) === 0 ) {
			throw new InvalidArgumentException( '$stage must include a write mode' );
		}
		if ( ( $stage & SCHEMA_COMPAT_READ_BOTH ) === 0 ) {
			throw new InvalidArgumentException( '$stage must include a read mode' );
		}
		if ( ( $stage & SCHEMA_COMPAT_READ_BOTH ) === SCHEMA_COMPAT_READ_BOTH ) {
			throw new InvalidArgumentException( 'Cannot read both schemas' );
		}
		if ( ( $stage & SCHEMA_COMPAT_READ_OLD ) && !( $stage & SCHEMA_COMPAT_WRITE_OLD ) ) {
			throw new InvalidArgumentException( 'Cannot read the old schema without also writing it' );
		}
		if ( ( $stage & SCHEMA_COMPAT_READ_NEW ) && !( $stage & SCHEMA_COMPAT_WRITE_NEW ) ) {
			throw new InvalidArgumentException( 'Cannot read the new schema without also writing it' );
		}
	}

}
