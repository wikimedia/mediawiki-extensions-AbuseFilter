<?php

/*
 * Makes the required changes for the AbuseFilter extension
 */

require_once ( getenv('MW_INSTALL_PATH') !== false
	? getenv('MW_INSTALL_PATH')."/maintenance/commandLine.inc"
	: dirname( __FILE__ ) . '/../../maintenance/commandLine.inc' );

//dbsource( dirname( __FILE__ ) . '/abusefilter.tables.sql' );

// Create the Abuse Filter user.
wfLoadExtensionMessages( 'AbuseFilter' );
$user = User::newFromName( wfMsgForContent( 'abusefilter-blocker' ) );

if (!$user->getId()) {
	$user->addToDatabase();
	$user->saveSettings();
}

# Promote user so it doesn't look too crazy.
$user->addGroup( 'sysop' );

# Increment site_stats.ss_users
$ssu = new SiteStatsUpdate( 0, 0, 0, 0, 1 );
$ssu->doUpdate();
