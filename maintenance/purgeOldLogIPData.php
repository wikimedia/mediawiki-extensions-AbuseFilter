<?php

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = dirname( __FILE__ ) . '/../../..';
}
require_once( "$IP/maintenance/Maintenance.php" );

class PurgeOldLogIPData extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Purge old IP Address data from AbuseFilter logs";
		$this->setBatchSize( 200 );
	}

	public function execute() {
		global $wgAbuseFilterLogIPMaxAge;

		$this->output( "Purging old IP Address data from abuse_filter_log..." );
		$dbw = wfGetDB( DB_MASTER );

		$count = 0;
		while ( true ) {
			$dbw->begin();
			$res = $dbw->update(
				'abuse_filter_log',
				array( 'afl_ip' => '' ),
				array(
					'afl_ip <> ""',
					"afl_timestamp < " . $dbw->addQuotes( $dbw->timestamp( time() - $wgAbuseFilterLogIPMaxAge ) )
				),
				__METHOD__,
				array( 'LIMIT' => $this->mBatchSize )
			);
			$count += $dbw->affectedRows();
			$dbw->commit();
			if ( $res->numRows() < $this->mBatchSize ) {
				break; // all updated
			}

			wfWaitForSlaves();
		}

		$this->output( $count . " rows.\n" );

		$this->output( "Done.\n" );
	}

}

$maintClass = "PurgeOldLogIPData";
require_once( RUN_MAINTENANCE_IF_MAIN );
