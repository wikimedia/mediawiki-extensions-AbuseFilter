<?php

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Adds rows missing per https://bugzilla.wikimedia.org/show_bug.cgi?id=52919
 */
class AddMissingLoggingEntries extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'Abuse Filter' );
	}

	/**
	 * @see Maintenance::execute
	 */
	public function execute() {
		$logParams = [];
		$afhRows = [];

		// Find all entries in abuse_filter_history without logging entry of same timestamp
		$afhResult = wfGetDB( DB_REPLICA, 'vslow' )->select(
			[ 'abuse_filter_history', 'logging' ],
			[ 'afh_id', 'afh_filter', 'afh_timestamp', 'afh_user', 'afh_deleted', 'afh_user_text' ],
			[ 'log_id IS NULL' ],
			__METHOD__,
			[],
			[ 'logging' => [
				'LEFT JOIN',
				'afh_timestamp = log_timestamp AND ' .
					'SUBSTRING_INDEX(log_params, \'\n\', 1) = afh_id AND log_type = \'abusefilter\''
			] ]
		);

		// Because the timestamp matches aren't exact (sometimes a couple of
		// seconds off), we need to check all our results and ignore those that
		// do actually have log entries
		foreach ( $afhResult as $row ) {
			$logParams[] = $row->afh_id . "\n" . $row->afh_filter;
			$afhRows[$row->afh_id] = $row;
		}

		if ( !count( $afhRows ) ) {
			$this->error( "Nothing to do.", 1 );
		}

		$logResult = wfGetDB( DB_REPLICA )->select(
			'logging',
			[ 'log_params' ],
			[ 'log_type' => 'abusefilter', 'log_params' => $logParams ],
			__METHOD__
		);

		foreach ( $logResult as $row ) {
			// id . '\n' . filter
			$params = explode( "\n", $row->log_params );
			// id
			$afhId = $params[0];
			// Forget this row had any issues - it just has a different timestamp in the log
			unset( $afhRows[$afhId] );
		}

		if ( !count( $afhRows ) ) {
			$this->error( "Nothing to do.", 1 );
		}

		$dbw = wfGetDB( DB_MASTER );

		$count = 0;
		foreach ( $afhRows as $row ) {
			if ( $count % 100 == 0 ) {
				wfWaitForSlaves();
			}
			$user = User::newFromAnyId( $row->afh_user, $row->afh_user_text, null );
			$dbw->insert(
				'logging',
				[
					'log_type' => 'abusefilter',
					'log_action' => 'modify',
					'log_timestamp' => $row->afh_timestamp,
					'log_namespace' => -1,
					'log_title' => SpecialPageFactory::getLocalNameFor( 'AbuseFilter' ) . '/' . $row->afh_filter,
					'log_params' => $row->afh_id . '\n' . $row->afh_filter,
					'log_deleted' => $row->afh_deleted,
				] + CommentStore::getStore()->insert( $dbw, 'log_comment', '' )
					+ ActorMigration::newMigration()->getInsertValues( $dbw, 'log_user', $user ),
				__METHOD__
			);
			$count++;
		}

		$this->output( "Inserted " . $count . " rows.\n" );
	}
}

$maintClass = 'AddMissingLoggingEntries';
require_once RUN_MAINTENANCE_IF_MAIN;
