<?php

namespace MediaWiki\Extension\AbuseFilter\Maintenance;

use LoggedUpdateMaintenance;
use MediaWiki\Extension\AbuseFilter\GlobalNameUtils;
use MediaWiki\MediaWikiServices;

// @codeCoverageIgnoreStart
if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Split afl_filter in afl_filter_id and afl_global per T42757
 * @codeCoverageIgnore Single-use script
 */
class MigrateAflFilter extends LoggedUpdateMaintenance {
	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct();

		$this->addOption( 'dry-run', 'Perform a dry run' );
		$this->requireExtension( 'Abuse Filter' );
		$this->setBatchSize( 500 );
	}

	/**
	 * @inheritDoc
	 */
	public function getUpdateKey() {
		return 'MigrateAflFilter';
	}

	/**
	 * @inheritDoc
	 */
	public function doDBUpdates() {
		$aflFilterMigrationStage = $this->getConfig()->get( 'AbuseFilterAflFilterMigrationStage' );

		// Keep this check in place in case the script is executed manually
		if ( !( $aflFilterMigrationStage & SCHEMA_COMPAT_WRITE_NEW ) ) {
			$this->output(
				"...cannot update while \$wgAbuseFilterAflFilterMigrationStage lacks SCHEMA_COMPAT_WRITE_NEW\n"
			);
			return false;
		}

		$dryRun = $this->hasOption( 'dry-run' );
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$dbw = $lbFactory->getMainLB()->getConnection( DB_PRIMARY );
		$globalPrefix = GlobalNameUtils::GLOBAL_FILTER_PREFIX;

		$batchSize = $this->getBatchSize();
		$updated = 0;

		$prevID = 1;
		$curID = $batchSize;

		// Save the row count, and stop once it's reached. This is so that we can tolerate rows with
		// low IDs that were already updated in a previous execution.
		$allRowsCount = (int)$dbw->selectField(
			'abuse_filter_log',
			'MAX(afl_id)',
			[],
			__METHOD__
		);

		do {
			$this->output( "... processing afl_id's from $prevID to $curID\n" );
			$updateIDs = $dbw->selectFieldValues(
				'abuse_filter_log',
				'afl_id',
				[
					// Use the primary key to avoid slow queries (and enforce batch size)
					"afl_id >= $prevID",
					"afl_id <= $curID",
					// Skip updated rows straight away
					'afl_filter_id' => 0
				],
				__METHOD__
			);

			$count = count( $updateIDs );

			$prevID = $curID + 1;
			$curID += $batchSize;
			$updated += $count;

			if ( $count === 0 ) {
				// Can mostly happen if we're on low IDs but they were already updated
				continue;
			}

			if ( !$dryRun ) {
				// Use native SQL functions instead of GlobalNameUtils::splitGlobalName so that we can update
				// all rows at the same time.
				$newIdSQL = $dbw->buildIntegerCast( $dbw->strreplace(
					'afl_filter',
					$dbw->addQuotes( $globalPrefix ),
					$dbw->addQuotes( '' )
				) );
				$globalSQL = $dbw->buildIntegerCast(
					'(' . $dbw->buildSubstring( 'afl_filter', 1, strlen( $globalPrefix ) ) . ' =  ' .
					$dbw->addQuotes( $globalPrefix ) . ' )'
				);

				$dbw->update(
					'abuse_filter_log',
					[
						"afl_filter_id = $newIdSQL",
						"afl_global = $globalSQL"
					],
					[ 'afl_id' => $updateIDs ],
					__METHOD__
				);
				$lbFactory->waitForReplication();
			}
		} while ( $prevID <= $allRowsCount );

		if ( $updated === 0 ) {
			$this->output( "No rows to change\n" );
			return !$dryRun;
		}

		if ( $dryRun ) {
			$this->output( "Found $updated rows to migrate in abuse_filter_log\n" );
			return false;
		}

		$this->output( "Migrated $updated rows.\n" );
		return true;
	}
}

$maintClass = MigrateAflFilter::class;
require_once RUN_MAINTENANCE_IF_MAIN;
