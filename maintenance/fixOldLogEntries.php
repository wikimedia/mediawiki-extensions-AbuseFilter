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
 * This was caused by the addMissingLoggingEntries script creating broken entries, see T208931
 * and T228655.
 * It also fixes a problem which caused addMissingLoggingEntries to insert duplicate rows foreach
 * non-legacy entries
 */
class FixOldLogEntries extends LoggedUpdateMaintenance {
	/** @var bool */
	private $dryRun;

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Fix old rows in logging which hold broken log_params' );

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
	 * This method will delete duplicated logging rows created by addMissingLoggingEntries. This
	 * happened because the script couldn't recognize non-legacy entries, and considered them to be
	 * absent if the script was ran after the format update. See T228655#5360754 and T228655#5408193
	 *
	 * @return int[] The IDs of the affected rows
	 */
	private function deleteDuplicatedRows() {
		$dbr = wfGetDB( DB_REPLICA, 'vslow' );
		$newFormatLike = $dbr->buildLike( $dbr->anyString(), 'historyId', $dbr->anyString() );
		// Select all non-legacy entries
		$res = $dbr->selectFieldValues(
			'logging',
			'log_params',
			[
				'log_type' => 'abusefilter',
				"log_params $newFormatLike"
			],
			__METHOD__
		);

		if ( !$res ) {
			return [];
		}

		$legacyParams = [];
		foreach ( $res as $logParams ) {
			$params = unserialize( $logParams );
			// The script always inserted duplicates with the wrong '\n'
			$legacyParams[] = $params['historyId'] . '\n' . $params['newId'];
		}

		// Don't do a delete already, as it would have poor performance and could kill the DB
		$deleteIDs = $dbr->selectFieldValues(
			'logging',
			'log_id',
			[
				'log_type' => 'abusefilter',
				'log_params' => $legacyParams
			],
			__METHOD__
		);

		if ( !$this->dryRun && $deleteIDs ) {
			// Note that we delete entries with legacy format, which are the ones erroneously inserted
			// by the script.
			wfGetDB( DB_MASTER )->delete(
				'logging',
				[ 'log_id' => $deleteIDs ],
				__METHOD__
			);
		}
		return $deleteIDs;
	}

	/**
	 * Change single-quote newlines to double-quotes newlines
	 *
	 * @return int[] Affected log_id's
	 */
	private function changeNewlineType() {
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

				if ( !$this->dryRun ) {
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
		return $updated;
	}

	/**
	 * @inheritDoc
	 */
	public function doDBUpdates() {
		$this->dryRun = $this->hasOption( 'dry-run' );

		$deleted = $this->deleteDuplicatedRows();

		$deleteVerb = $this->dryRun ? 'would delete' : 'deleted';
		$numDel = count( $deleted );
		$this->output(
			__CLASS__ . " $deleteVerb $numDel rows.\n"
		);
		if ( $deleted && $this->hasOption( 'verbose' ) ) {
			$this->output( 'The affected log_id\'s are: ' . implode( ', ', $deleted ) . "\n" );
		}

		$updated = $this->changeNewlineType();

		$updateVerb = $this->dryRun ? 'would update' : 'updated';
		$numUpd = count( $updated );
		$this->output(
			__CLASS__ . " $updateVerb $numUpd rows.\n"
		);
		if ( $updated && $this->hasOption( 'verbose' ) ) {
			$this->output( 'The affected log_id\'s are: ' . implode( ', ', $updated ) . "\n" );
		}
		return !$this->dryRun;
	}
}

$maintClass = 'FixOldLogEntries';
require_once RUN_MAINTENANCE_IF_MAIN;
