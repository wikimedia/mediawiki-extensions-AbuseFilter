<?php

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
/**
 * Fix old log entries with log_type = 'abusefilter' where log_params are imploded with '\n'
 * instead of "\n" (using single quotes), which causes a broken display.
 */
class FixOldLogEntries extends LoggedUpdateMaintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Fix old rows in logging which hold broken log_params';

		$this->addOption( 'verbose', 'Print some more debug info' );
		$this->addOption( 'dry-run', 'Perform a dry run' );
		$this->requireExtension( 'Abuse Filter' );
	}

	/**
	 * @inheritDoc
	 */
	public function getUpdateKey() {
		return __CLASS__;
	}

	/**
	 * @inheritDoc
	 */
	public function doDBUpdates() {
		$dbr = wfGetDB( DB_REPLICA, 'vslow' );
		$dbw = wfGetDB( DB_MASTER );
		$res = $dbr->select(
			'logging',
			[ 'log_id', 'log_params' ],
			[
				'log_type' => 'abusefilter',
				'log_params ' . $dbr->buildLike(
					$dbr->anyString(),
					'\n',
					$dbr->anyString()
				)
			],
			__METHOD__
		);

		$updated = [];
		foreach ( $res as $row ) {
			$par = explode( '\n', $row->log_params );
			if ( count( $par ) === 2 ) {
				// Keep the entries legacy
				$newVal = implode( "\n", $par );

				if ( !$this->hasOption( 'dry-run' ) ) {
					$dbw->update(
						'logging',
						[ 'log_params' => $newVal ],
						[ 'log_id' => $row->log_id ],
						__METHOD__
					);
				}
				$updated[] = $row->log_id;
			}
		}

		$verb = $this->hasOption( 'dry-run' ) ? 'would update' : 'updated';
		$numUpd = count( $updated );
		$this->output(
			__CLASS__ . ": $verb $numUpd rows out of " . $res->numRows() . " rows found.\n"
		);
		if ( $this->hasOption( 'verbose' ) ) {
			$this->output( 'The affected log IDs are: ' . implode( ', ', $updated ) . "\n" );
		}
		return !$this->hasOption( 'dry-run' );
	}
}

$maintClass = 'FixOldLogEntries';
require_once RUN_MAINTENANCE_IF_MAIN;
