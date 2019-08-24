<?php

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
/**
 * Fix the first blockautopromote right entries, which had hardcoded duration
 * NOTE: Given that the bad change has only survived for a couple of weeks, this script should
 * only be executed in WMF production, and deleted afterwards.
 */
class FixFirstBlockautopromoteEntries extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Fix old blockautopromote log entries with hardcoded duration' );

		$this->addOption( 'verbose', 'Print some more debug info' );
		$this->addOption( 'dry-run', 'Perform a dry run' );
		$this->requireExtension( 'Abuse Filter' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$dbr = wfGetDB( DB_REPLICA, 'vslow' );
		$dbw = wfGetDB( DB_MASTER );
		$res = $dbr->select(
			'logging',
			[ 'log_id', 'log_params' ],
			[
				'log_type' => 'rights',
				'log_action' => 'blockautopromote'
			],
			__METHOD__
		);

		$updated = [];
		foreach ( $res as $row ) {
			$params = unserialize( $row->log_params );
			$dur = $params['7::duration'];
			if ( !is_int( $dur ) ) {
				// This is the value that AbuseFilter::BLOCKAUTOPROMOTE_DURATION has for broken entries
				$newVal = 5 * 86400;
				$params['7::duration'] = $newVal;

				if ( !$this->hasOption( 'dry-run' ) ) {
					$dbw->update(
						'logging',
						[ 'log_params' => serialize( $params ) ],
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
		if ( $updated && $this->hasOption( 'verbose' ) ) {
			$this->output( 'The affected log IDs are: ' . implode( ', ', $updated ) . "\n" );
		}
		return true;
	}
}

$maintClass = 'FixFirstBlockautopromoteEntries';
require_once RUN_MAINTENANCE_IF_MAIN;
