<?php
/**
 * Internationalisation file for extension AbuseFilter.
 *
 * @addtogroup Extensions
 */

$messages = array();

/** English
 * @author Andrew Garrett
 */
$messages['en'] = array(
	// Special pages
	'abusefilter' => 'Abuse Filter Configuration',
	'abuselog' => 'Abuse Log',
	
	// Hooks
	'abusefilter-warning' => "<big>'''Warning'''</big>: This action has been automatically identified as harmful. Unconstructive edits will be quickly reverted, and egregious or repeated unconstructive editing will result in your account or computer being blocked. If you believe this edit to be constructive, you may click Submit again to confirm it. A brief description of the abuse rule which your action matched is: $1",
	'abusefilter-disallowed' => "This action has been automatically identified as harmful, and therefore disallowed. If you believe your edit was constructive, please contact an administrator, and inform them of what you were trying to do. A brief description of the abuse rule which your action matched is: $1",
	'abusefilter-blocked-display' => "This action has been automatically identified as harmful, and you have been prevented from executing it. In addition, to protect {{SITENAME}}, your user account and all associated IP addresses have been blocked from editing. If this has occurred in error, please contact an administrator. A brief description of the abuse rule which your action matched is: $1",
	'abusefilter-degrouped' => "This action has been automatically identified as harmful. Consequently, it has been disallowed, and, since your account is suspected of being compromised, all rights have been revoked. If you believe this to have been in error, please contact a bureaucrat with an explanation of this action, and your rights may be restored. A brief description of the abuse rule which your action matched is: $1",
	'abusefilter-autopromote-blocked' => "This action has been automatically identified as harmful, and it has been disallowed. In addition, as a security measure, some privileges routinely granted to established accounts have been temporarily revoked from your account. A brief description of the abuse rule which your action matched is: $1",
	'abusefilter-blocker' => 'Abuse Filter',
	'abusefilter-blockreason' => 'Automatically blocked by abuse filter. Rule description: $1',
	
	'abusefilter-accountreserved' => 'This account name is reserved for use by the abuse filter.',
	
	// Permissions
	'right-abusefilter-modify' => 'Modify abuse filters',
	'right-abusefilter-view' => 'View abuse filters',
	'right-abusefilter-log' => 'View the abuse log',
	'right-abusefilter-log-detail' => 'View detailed abuse log entries',
	'right-abusefilter-private' => 'View private data in the abuse log',
	
	// Abuse Log
	'abusefilter-log' => 'Abuse Filter Log',
	'abusefilter-log-search' => 'Search the abuse log',
	'abusefilter-log-search-user' => 'User:',
	'abusefilter-log-search-filter' => 'Filter ID:',
	'abusefilter-log-search-title' => 'Title:',
	'abusefilter-log-search-submit' => 'Search',
	'abusefilter-log-entry' => '$1: $2 triggered an abuse filter, making a $3 on $4. Actions taken: $5; Filter description: $6',
	'abusefilter-log-detailedentry' => '$1: $2 triggered filter $3, making a $4 on $5. Actions taken: $6; Filter description: $7 ($8)',
	'abusefilter-log-detailslink' => 'details',
	'abusefilter-log-details-legend' => 'Details for log entry $1',
	'abusefilter-log-details-var' => 'Variable',
	'abusefilter-log-details-val' => 'Value',
	'abusefilter-log-details-vars' => 'Action parameters',
	'abusefilter-log-details-private' => 'Private data',
	'abusefilter-log-details-ip' => 'Originating IP address',
	'abusefilter-log-noactions' => 'none',
	
	// Abuse filter management
	'abusefilter-management' => 'Abuse Filter Management',
	'abusefilter-list' => 'All filters',
	'abusefilter-list-id' => 'Filter ID',
	'abusefilter-list-status' => 'Status',
	'abusefilter-list-public' => 'Public description',
	'abusefilter-list-consequences' => 'Consequences',
	'abusefilter-list-visibility' => 'Visibility',
	'abusefilter-list-hitcount' => 'Hit count',
	'abusefilter-list-edit' => 'Edit',
	'abusefilter-list-details' => 'Details',
	'abusefilter-hidden' => 'Private',
	'abusefilter-unhidden' => 'Public',
	'abusefilter-enabled' => 'Enabled',
	'abusefilter-disabled' => 'Disabled',
	'abusefilter-hitcount' => '$1 {{PLURAL:$1|hit|hits}}',
	'abusefilter-list-new' => 'New filter',
	
	// The edit screen
	'abusefilter-edit-subtitle' => 'Editing filter $1',
	'abusefilter-edit-new' => 'New filter',
	'abusefilter-edit-save' => 'Save Filter',
	'abusefilter-edit-id' => 'Filter ID:',
	'abusefilter-edit-description' => "Description:\n:''(publicly viewable)''",
	'abusefilter-edit-flags' => 'Flags:',
	'abusefilter-edit-enabled' => 'Enable this filter',
	'abusefilter-edit-hidden' => 'Hide details of this filter from public view',
	'abusefilter-edit-rules' => 'Ruleset:',
	'abusefilter-edit-notes' => "Notes:\n:''(private)",
	'abusefilter-edit-lastmod' => 'Filter last modified:',
	'abusefilter-edit-lastuser' => 'Last user to modify this filter:',
	'abusefilter-edit-hitcount' => 'Filter hits:',
	'abusefilter-edit-consequences' => 'Actions taken on hit',
	'abusefilter-edit-action-warn' => 'Trigger these actions after giving the user a warning',
	'abusefilter-edit-action-disallow' => 'Disallow the action',
	'abusefilter-edit-action-flag' => 'Flag the edit in the abuse log',
	'abusefilter-edit-action-blockautopromote' => "Revoke the users' autoconfirmed status",
	'abusefilter-edit-action-degroup' => 'Remove all privileged groups from the user',
	'abusefilter-edit-action-block' => 'Block the user from editing',
	'abusefilter-edit-action-throttle' => 'Trigger actions only if the user trips a rate limit',
	'abusefilter-edit-throttle-count' => 'Number of actions to allow:',
	'abusefilter-edit-throttle-period' => 'Period of time:',
	'abusefilter-edit-throttle-seconds' => '$1 seconds',
	'abusefilter-edit-throttle-groups' => "Group throttle by:\n:''(one per line, combine with commas)''",
	'abusefilter-edit-denied' => "You may not view details of this filter, because it is hidden from public view",
	'abusefilter-edit-main' => 'Filter parameters',
	'abusefilter-edit-done-subtitle' => 'Filter edited',
	'abusefilter-edit-done' => "You have successfully saved your changes to the filter.\n\n[[Special:AbuseFilter|Return]]",
);