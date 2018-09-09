<?php
/**
 * Normalizes throttle parameters as part of the overhaul described in T203587
 *
 * Tasks performed by this script:
 * - Remove duplicated throttle groups (T203584)
 * - Remove unrecognized stuff from throttle groups (T203584)
 * - Checks if throttle count or period have extra commas inside. If this leads to the filter acting
 *   like it would with throttle disabled, we just disable it. Otherwise, since we don't know what
 *   the filter is meant to do, we just ask users to evaluate and fix every case by hand. This is
 *   highly unlikely to happen anyway. (T203585)
 * - Disables the throttle action for filters where throttle groups are empty (or only contain
 *   unknown keywords). Per T203336#4568819, in those case throttle already doesn't work. (T203584)
 *
 * @ingroup Maintenance
 */
if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Normalizes throttle parameters, see T203587
 */
class NormalizeThrottleParameters extends LoggedUpdateMaintenance {
	public function __construct() {
		parent::__construct();

		$this->mDescription = 'Normalize AbuseFilter throttle parameters - T203587';
		$this->addOption( 'dry-run', 'Perform a dry run' );
		$this->requireExtension( 'Abuse Filter' );
	}

	/**
	 * @see Maintenance::getUpdateKey()
	 * @return string
	 */
	public function getUpdateKey() {
		return __CLASS__;
	}

	/** @var \Wikimedia\Rdbms\IDatabase $db The master database */
	private $dbw;

	/**
	 * Rollback the current transaction and emit a fatal error
	 *
	 * @param string $msg The message of the error
	 */
	protected function fail( $msg ) {
		$this->rollbackTransaction( $this->dbw, __METHOD__ );
		$this->fatalError( $msg );
	}

	/**
	 * Get normalized throttle groups
	 *
	 * @param array $params Throttle parameters
	 * @return array[] The first element is the array of old throttle groups, the second
	 * is an array of formatted throttle groups
	 */
	private function getNewGroups( $params ) {
		$validGroups = [
			'ip',
			'user',
			'range',
			'creationdate',
			'editcount',
			'site',
			'page'
		];
		$rawGroups = array_slice( $params, 2 );
		$newGroups = [];
		// We use a standard order to check for duplicates. This variable is not used as the actual
		// array of groups to avoid silly changes like 'ip,user' => 'user,ip'
		$normalizedGroups = [];
		// Every group should be valid, and subgroups should have valid groups inside. Only keep
		// valid (sub)groups.
		foreach ( $rawGroups as $group ) {
			if ( strpos( $group, ',' ) !== false ) {
				// No duplicates in subgroups
				$subGroups = array_unique( explode( ',', $group ) );
				$uniqueGroup = implode( ',', $subGroups );
				$valid = true;
				foreach ( $subGroups as $subGroup ) {
					if ( !in_array( $subGroup, $validGroups ) ) {
						$valid = false;
						break;
					}
				}
				sort( $subGroups );
				if ( $valid && !in_array( $subGroups, $normalizedGroups ) ) {
					$newGroups[] = $uniqueGroup;
					$normalizedGroups[] = $subGroups;
				}
			} elseif ( in_array( $group, $validGroups ) ) {
				$newGroups[] = $group;
				$normalizedGroups[] = $group;
			}
		}

		// Remove duplicates
		$newGroups = array_unique( $newGroups );

		return [ $rawGroups, $newGroups ];
	}

	/**
	 * Check if throttle rate is malformed, i.e. if it has extra commas
	 *
	 * @param string $rate The throttle rate as saved in the DB ("count,period")
	 * @return string|null String with error type or null if the rate is valid
	 */
	private function checkThrottleRate( $rate ) {
		if ( preg_match( '/^,/', $rate ) === 1 ) {
			// The comma was inserted at least in throttle count. This behaves like if
			// throttling isn't enabled, so just disable it
			return 'disable';
		} elseif ( preg_match( '/^\d+,$/', $rate ) === 1 || preg_match( '/^\d+,\d+$/', $rate ) === 0 ) {
			// First condition is for comma only inside throttle period. The behaviour in this case
			// is unclear, ask users to fix this by hand. Second condition is for every other case;
			// since it's unpredictable what the problem is, we just ask to fix it by hand.
			return 'hand';
		} else {
			return null;
		}
	}

	/**
	 * @see Maintenance::doDBUpdates
	 * @return bool
	 */
	public function doDBUpdates() {
		$user = User::newSystemUser( 'AbuseFilter updater', [ 'steal' => true ] );
		$this->dbw = wfGetDB( DB_MASTER );
		$dryRun = $this->hasOption( 'dry-run' );

		$this->beginTransaction( $this->dbw, __METHOD__ );

		// Only select throttle actions
		$actionRows = $this->dbw->select(
			'abuse_filter_action',
			[ 'afa_filter', 'afa_parameters' ],
			[ 'afa_consequence' => 'throttle' ],
			__METHOD__,
			[ 'LOCK IN SHARE MODE' ]
		);

		$newActionRows = [];
		$deleteActionIDs = [];
		$changeActionIDs = [];
		foreach ( $actionRows as $actRow ) {
			$filter = $actRow->afa_filter;
			$params = explode( "\n", $actRow->afa_parameters );
			$rateCheck = $this->checkThrottleRate( $params[1] );
			if ( $rateCheck === 'hand' ) {
				$this->fail(
					"Throttle count and period for filter $filter are malformed, probably due to extra commas. " .
					"Please fix them by hand in the way they're meant to be, then launch the script again."
				);
			}
			list( $oldGroups, $newGroups ) = $this->getNewGroups( $params );

			if ( $rateCheck === 'disable' || count( $newGroups ) === 0 ) {
				// Empty throttle groups, disable throttle for the filter
				$deleteActionIDs[] = $actRow->afa_filter;
			} elseif ( $oldGroups !== $newGroups ) {
				$newParams = array_merge( array_slice( $params, 0, 2 ), $newGroups );
				$newActionRows[] = [
					'afa_filter' => $actRow->afa_filter,
					'afa_consequence' => 'throttle',
					'afa_parameters' => implode( "\n", $newParams )
				];
				$changeActionIDs[] = $actRow->afa_filter;
			}
		}

		$changeActionCount = count( $changeActionIDs );
		if ( $changeActionCount ) {
			if ( $dryRun ) {
				$this->output(
					"normalizeThrottleParameter has found $changeActionCount rows to change in " .
					"abuse_filter_action for the following IDs: " . implode( ', ', $changeActionIDs ) . "\n"
				);
			} else {
				$this->dbw->replace(
					'abuse_filter_action',
					[ [ 'afa_filter', 'afa_consequence' ] ],
					$newActionRows,
					__METHOD__
				);
			}
		}

		$deleteActionCount = count( $deleteActionIDs );
		// Use the same timestamps in abuse_filter and abuse_filter_history, since this is
		// what we do in the actual code.
		$timestamps = [];
		if ( $deleteActionCount ) {
			if ( $dryRun ) {
				$this->output(
					"normalizeThrottleParameter has found $deleteActionCount rows to delete in " .
					"abuse_filter_action and update in abuse_filter for the following IDs: " .
					implode( ', ', $deleteActionIDs ) . "\n"
				);
			} else {
				// Delete rows in abuse_filter_action
				$this->dbw->delete(
					'abuse_filter_action',
					[
						'afa_consequence' => 'throttle',
						'afa_filter' => $deleteActionIDs
					],
					__METHOD__
				);
				// Update abuse_filter. abuse_filter_history done later
				$rows = $this->dbw->select(
					'abuse_filter',
					'*',
					[ 'af_id' => $deleteActionIDs ],
					__METHOD__
				);
				foreach ( $rows as $row ) {
					$oldActions = explode( ',', $row->af_actions );
					$actions = array_diff( $oldActions, [ 'throttle' ] );
					$timestamps[ $row->af_id ] = $this->dbw->timestamp( wfTimestampNow() );
					$newRow = [
						'af_user' => $user->getId(),
						'af_user_text' => $user->getName(),
						'af_timestamp' => $timestamps[ $row->af_id ],
						'af_actions' => implode( ',', $actions ),
					] + $row;

					$this->dbw->replace(
						'abuse_filter',
						[ 'af_id' ],
						$newRow,
						__METHOD__
					);
				}
			}
		}
		$affectedActionRows = $changeActionCount + $deleteActionCount;

		$touchedIDs = array_merge( $changeActionIDs, $deleteActionIDs );
		if ( count( $touchedIDs ) === 0 ) {
			$this->output( "No throttle parameters to normalize.\n" );
			$this->commitTransaction( $this->dbw, __METHOD__ );
			return !$dryRun;
		}
		// Create new history rows for every changed filter
		$historyIDs = $this->dbw->selectFieldValues(
			'abuse_filter_history',
			'MAX(afh_id)',
			[ 'afh_filter' => $touchedIDs ],
			__METHOD__,
			[ 'GROUP BY' => 'afh_filter', 'LOCK IN SHARE MODE' ]
		);

		$lastHistoryRows = $this->dbw->select(
			'abuse_filter_history',
			[
				'afh_filter',
				'afh_user',
				'afh_user_text',
				'afh_timestamp',
				'afh_pattern',
				'afh_comments',
				'afh_flags',
				'afh_public_comments',
				'afh_actions',
				'afh_deleted',
				'afh_changed_fields',
				'afh_group'
			],
			[ 'afh_id' => $historyIDs ],
			__METHOD__,
			[ 'LOCK IN SHARE MODE' ]
		);

		$newHistoryRows = [];
		$changeHistoryFilters = [];
		foreach ( $lastHistoryRows as $histRow ) {
			$actions = unserialize( $histRow->afh_actions );
			$filter = $histRow->afh_filter;
			$rateCheck = $this->checkThrottleRate( $actions['throttle'][1] );
			if ( $rateCheck === 'hand' ) {
				$this->fail(
					"Throttle count and period for filter $filter are malformed, probably due to extra commas. " .
					"Please fix them by hand in the way they're meant to be, then launch the script again."
				);
			}
			list( $oldGroups, $newGroups ) = $this->getNewGroups( $actions['throttle'] );

			$fixedRowSection = [
				'afh_id' => $this->dbw->nextSequenceValue( 'abuse_filter_af_id_seq' ),
				'afh_user' => $user->getId(),
				'afh_user_text' => $user->getName(),
				'afh_timestamp' => $timestamps[ $filter ] ?? $this->dbw->timestamp( wfTimestampNow() ),
				'afh_changed_fields' => 'actions',
			] + get_object_vars( $histRow );

			if ( $rateCheck === 'disable' || count( $newGroups ) === 0 ) {
				// Empty throttle groups, disable throttle for the filter
				unset( $actions['throttle'] );
				$newHistoryRows[] = [
					'afh_actions' => serialize( $actions )
				] + $fixedRowSection;
				$changeHistoryFilters[] = $filter;
			} elseif ( $oldGroups !== $newGroups ) {
				$actions['throttle'] = array_merge( array_slice( $actions['throttle'], 0, 2 ), $newGroups );
				$newHistoryRows[] = [
					'afh_actions' => serialize( $actions )
				] + $fixedRowSection;
				$changeHistoryFilters[] = $filter;
			}
		}

		$historyCount = count( $changeHistoryFilters );
		$sanityCheck = $historyCount === $affectedActionRows;
		if ( !$sanityCheck ) {
			// Something went wrong.
			$this->fail(
				"The amount of affected rows isn't equal for abuse_filter_action and abuse_filter history. " .
				"Found $affectedActionRows for the former and $historyCount for the latter."
			);
		}
		if ( count( $newHistoryRows ) ) {
			if ( $dryRun ) {
				$this->output(
					"normalizeThrottleParameter would insert $historyCount rows in abuse_filter_history" .
					" for the following filters: " . implode( ', ', $changeHistoryFilters ) . "\n"
				);
			} else {
				$this->dbw->insert(
					'abuse_filter_history',
					$newHistoryRows,
					__METHOD__
				);
			}
		}

		$this->commitTransaction( $this->dbw, __METHOD__ );
		$changed = $affectedActionRows + $historyCount;
		$resultMsg = $dryRun ?
			"Throttle parameter normalization would change a total of $changed rows.\n" :
			"Throttle parameters successfully normalized. Changed $changed rows.\n";
		$this->output( $resultMsg );
		return !$dryRun;
	}
}

$maintClass = 'NormalizeThrottleParameters';
require_once RUN_MAINTENANCE_IF_MAIN;
