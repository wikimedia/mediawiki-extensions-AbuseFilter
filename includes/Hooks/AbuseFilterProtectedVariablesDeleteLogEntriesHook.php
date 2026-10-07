<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AbuseFilter\Hooks;

interface AbuseFilterProtectedVariablesDeleteLogEntriesHook {
	/**
	 * Allows other extensions to define their protected variables as sensitive enough to
	 * require outright deletion of any abuse_filter_log row that contains the variable once
	 * $wgAbuseFilterLogProtectedVariablesMaxAge seconds have passed since the creation of
	 * the row.
	 *
	 * If $wgAbuseFilterLogProtectedVariablesMaxAge seconds is set to 0, then the rows will
	 * never be deleted.
	 *
	 * @since 1.48
	 * @param string[] &$variables The protected variables which cause log entries to be deleted
	 */
	public function onAbuseFilterProtectedVariablesDeleteLogEntries( array &$variables ): void;
}
