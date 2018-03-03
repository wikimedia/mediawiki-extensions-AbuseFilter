<?php
/**
 * Automatically applies heuristics to edits.
 *
 * @file
 * @ingroup Extensions
 * @author Andrew Garrett <andrew@epstone.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * Includes GFDL-licensed images retrieved from
 * http://commons.wikimedia.org/wiki/File:Yes_check.svg and
 * http://commons.wikimedia.org/wiki/File:Red_x.svg -- both have been
 * downsampled and converted to PNG.
 * @link http://www.mediawiki.org/wiki/Extension:AbuseFilter Documentation
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'AbuseFilter' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['AbuseFilter'] = __DIR__ . '/i18n';
	$wgExtensionMessagesFiles['AbuseFilterAliases'] = __DIR__ . '/AbuseFilter.alias.php';
	/* wfWarn(
		'Deprecated PHP entry point used for AbuseFilter extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	); */
	return;
} else {
	die( 'This version of the AbuseFilter extension requires MediaWiki 1.31+' );
}

// Global declarations and documentation kept for IDEs and PHP documentors.
// This code is never executed.

/**
 * The possible actions that can be taken by abuse filters.
 *
 * @var array [action name => is enabled?] At the end of setup, false values will be filtered out
 */
$wgAbuseFilterActions = [ /* See extension.json */ ];

$wgAbuseFilterAvailableActions = 'REMOVED'; // use $wgAbuseFilterActions instead

/**
 * The maximum number of 'conditions' that can be used each time the filters are run against a
 * change. (More complex filters require more 'conditions').
 */
$wgAbuseFilterConditionLimit = 1000;

/**
 * Disable filters if they match more than X edits, constituting more than Y%
 * of the last Z edits, if they have been changed in the last S seconds.
 */
$wgAbuseFilterEmergencyDisableThreshold['default'] = 0.05;
/** @see $wgAbuseFilterEmergencyDisableThreshold */
$wgAbuseFilterEmergencyDisableCount['default'] = 2;
/** @see $wgAbuseFilterEmergencyDisableThreshold */
$wgAbuseFilterEmergencyDisableAge['default'] = 86400; // One day.

/** Abuse filter parser class */
$wgAbuseFilterParserClass = 'AbuseFilterParser';

/**
 * Do users need "abusefilter-modify-restricted" user right as well as "abusefilter-modify"
 * in order to create or modify filters which carry out this action?
 *
 * @var array action name => is restricted?
 */
$wgAbuseFilterRestrictions = [ /* See extension.json */ ];

$wgAbuseFilterRestrictedActions = 'REMOVED'; // use $wgAbuseFilterRestrictions instead

/**
 * Allows to configure the extension to send hit notifications to Special:RecentChanges or UDP.
 * Available options: rc, udp, rcandudp
 * @var string|false
 */
$wgAbuseFilterNotifications = false;

/** Enable notifications for private filters */
$wgAbuseFilterNotificationsPrivate = false;

/** Name of a database where global abuse filters will be stored in */
$wgAbuseFilterCentralDB = null;
/** Set this variable to true for the wiki where global AbuseFilters are stored in */
$wgAbuseFilterIsCentral = false;

/**
 * Disallow centralised filters from taking actions that locally
 * block, remove from groups, or revoke permissions
 */
$wgAbuseFilterDisallowGlobalLocalBlocks = false;

/** Block duration for logged in users */
$wgAbuseFilterBlockDuration = 'indefinite';
/** Block duration for anonymous users ($wgAbuseFilterBlockDuration will be used if null) */
$wgAbuseFilterAnonBlockDuration = null;

/** Callback functions for custom actions */
$wgAbuseFilterCustomActionsHandlers = [];

/**
 * The list of "groups" filters can be divided into – used for applying edit filters to certain
 * types of actions. By default there is only one group.
 */
$wgAbuseFilterValidGroups = [ 'default' ];

/** Default warning messages, per filter group */
$wgAbuseFilterDefaultWarningMessage = [ /* See extension.json */ ];

/**
 * Age used as cutoff when purging old IP log data.
 * Used by maintenance script purgeOldLogIPData.php
 */
$wgAbuseFilterLogIPMaxAge = 3 * 30 * 24 * 3600; // 3 months

/**
 * Whether to record the average time taken and average number of conditions used by each filter.
 */
$wgAbuseFilterProfile = false;

/**
 * Whether to record runtime metrics for all filters combined.
 */
$wgAbuseFilterRuntimeProfile = false;

/**
 * Runtime in milliseconds before a filter is considered slow.
 */
$wgAbuseFilterSlowFilterRuntimeLimit = 500;

/**
 * Whether to include IP in the abuse_filter_log
 */
$wgAbuseFilterLogIP = true;
