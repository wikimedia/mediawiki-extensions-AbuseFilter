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
 * - If throttle groups are empty (or only contain unknown keywords), ask users to fix every case
 *   by hand. (T203584)
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

		$this->addDescription( 'Normalize AbuseFilter throttle parameters - T203587' );
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
		// array of groups to avoid silly changes like 'ip,user' => 'user,ip'. In this variable we also
		// store trimmed groups, so that 'ip, user' is considered to be the same as 'ip,user', just
		// as the actual code does. And again, we don't want to edit the filter just to strip spaces.
		$normalizedGroups = [];
		// Every group should be valid, and subgroups should have valid groups inside. Only keep
		// valid (sub)groups.
		foreach ( $rawGroups as $group ) {
			// Groups must be lowercase.
			$group = strtolower( $group );
			if ( strpos( $group, ',' ) !== false ) {
				// No duplicates in subgroups
				$subGroups = array_unique( explode( ',', $group ) );
				$uniqueGroup = implode( ',', $subGroups );
				$valid = true;
				foreach ( $subGroups as $subGroup ) {
					if ( !in_array( trim( $subGroup ), $validGroups ) ) {
						$valid = false;
						break;
					}
				}
				sort( $subGroups );
				if ( $valid && !in_array( $subGroups, $normalizedGroups ) ) {
					$newGroups[] = $uniqueGroup;
					$normalizedGroups[] = array_map( 'trim', $subGroups );
				}
			} elseif ( in_array( trim( $group ), $validGroups ) ) {
				$newGroups[] = $group;
				$normalizedGroups[] = trim( $group );
			}
		}

		// Remove duplicates
		$newGroups = array_unique( $newGroups );

		return [ $rawGroups, $newGroups ];
	}

	/**
	 * Check if throttle rate is malformed, i.e. if it has extra commas or a part of it is empty
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
		$user = AbuseFilter::getFilterUser();
		$this->dbw = wfGetDB( DB_MASTER );
		$dryRun = $this->hasOption( 'dry-run' );

		// IDs of filters with invalid rate (count or period)
		$invalidRate = [];
		// IDs of filters with invalid groups
		$invalidGroups = [];
		// IDs of filters where throttle parameters are completely empty, and even the filter ID is
		// missing. This happened for filters containing a throttle group with a comma inside which
		// were modified between the OOUI switch (gerrit/421487) and throttle repair (gerrit/459368):
		// a bug caused all existing throttle parameters to be wiped away, so that afa_consequence
		// holds an empty string and (unserialize(afh_actions))['throttle'] is null.
		$totallyEmpty = [];

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
		// Save new, sanitized throttle parameters to be copied in abuse_filter_history.
		// The structure is [ filterID => val ] where "val" is either an array with new params
		// or null if throttle must be removed.
		$historyThrottleParams = [];
		$deleteActionIDs = [];
		$changeActionIDs = [];
		foreach ( $actionRows as $actRow ) {
			$filter = $actRow->afa_filter;

			if ( $actRow->afa_parameters === '' ) {
				// All parameters are empty. See comment above the declaration of $totallyEmpty for
				// why this happens. Definitely to be fixed by hand, without further checks.
				$totallyEmpty[] = $filter;
				continue;
			}

			$params = explode( "\n", $actRow->afa_parameters );
			$rateCheck = $this->checkThrottleRate( $params[1] );
			list( $oldGroups, $newGroups ) = $this->getNewGroups( $params );

			// If the rate is invalid or the groups are empty (or only contain invalid identifiers),
			// it means that the throttle limit is never reached. Since we cannot guess what the
			// filter should do, nor we want to impose a default, we ask to manually fix the problem.
			if ( $rateCheck === 'hand' ) {
				$invalidRate[] = $filter;
			}
			if ( count( $newGroups ) === 0 ) {
				$invalidGroups[] = $filter;
			}
			if ( $rateCheck === 'hand' || count( $newGroups ) === 0 ) {
				continue;
			}

			if ( $rateCheck === 'disable' ) {
				// Invalid rate, disable throttle for the filter
				$deleteActionIDs[] = $actRow->afa_filter;
				$historyThrottleParams[ $actRow->afa_filter ] = null;
			} elseif ( $oldGroups !== $newGroups ) {
				$newParams = array_merge( array_slice( $params, 0, 2 ), $newGroups );
				$newActionRows[] = [
					'afa_filter' => $actRow->afa_filter,
					'afa_consequence' => 'throttle',
					'afa_parameters' => implode( "\n", $newParams )
				];
				$changeActionIDs[] = $actRow->afa_filter;
				$historyThrottleParams[ $actRow->afa_filter ] = $newParams;
			} else {
				// The filter is not broken!
				continue;
			}
		}

		if ( $invalidRate || $invalidGroups || $totallyEmpty ) {
			$invalidMsg = '';
			if ( $invalidRate ) {
				$invalidMsg .= 'Throttle count and period are malformed or empty for the following filters: ' .
					implode( ', ', $invalidRate ) . '. ' .
					"Please fix them by hand in the way they're meant to be, then launch the script again.\n";
			}
			if ( $invalidGroups ) {
				$invalidMsg .= 'Throttle groups are empty for the following filters: ' .
					implode( ', ', $invalidGroups ) . '. ' .
					"Please add some groups or disable throttling, then launch the script again.\n";
			}
			if ( $totallyEmpty ) {
				$invalidMsg .= 'Throttle parameters are empty for the following filters: ' .
					implode( ', ', $totallyEmpty ) . '. ' .
					'This was probably caused by a temporary bug and you should be able to find valid ' .
					"parameters in each filter's history. Please restore them, then launch the script again.\n";
			}

			$this->fail( $invalidMsg );
		}

		// Use the same timestamps in abuse_filter and abuse_filter_history, since this is
		// what we do in the actual code.
		$timestamps = [];
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
				// Touch the abuse_filter table to update the "filter last modified" field
				foreach ( $changeActionIDs as $id ) {
					$timestamps[ $id ] = $this->dbw->timestamp();

					$this->dbw->update(
						'abuse_filter',
						[
							'af_user' => $user->getId(),
							'af_user_text' => $user->getName(),
							'af_timestamp' => $timestamps[ $id ]
						],
						[ 'af_id' => $id ],
						__METHOD__
					);
				}
			}
		}

		$deleteActionCount = count( $deleteActionIDs );
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
				foreach ( $deleteActionIDs as $id ) {
					$timestamps[ $id ] = $this->dbw->timestamp();

					$this->dbw->update(
						'abuse_filter',
						[
							'af_user' => $user->getId(),
							'af_user_text' => $user->getName(),
							'af_timestamp' => $timestamps[ $id ],
							// Use string replacement so that we can avoid an extra query to retrieve the
							// value and then explode, remove throttle and implode again.
							'af_actions = ' . $this->dbw->strreplace(
								$this->dbw->strreplace( 'af_actions', "',throttle'", "''" ),
								"'throttle'",
								"''"
							)
						],
						[ 'af_id' => $id ],
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

		$newHistoryRows = [];
		$changeHistoryFilters = [];
		foreach ( $touchedIDs as $filter ) {
			$histRow = $this->dbw->selectRow(
				'abuse_filter_history',
				[
					'afh_filter',
					'afh_pattern',
					'afh_comments',
					'afh_flags',
					'afh_public_comments',
					'afh_deleted',
					'afh_group'
				],
				[ 'afh_filter' => $filter ],
				__METHOD__,
				[ 'ORDER BY' => 'afh_id DESC', 'LOCK IN SHARE MODE' ]
			);

			if ( !isset( $historyThrottleParams[ $filter ] ) ) {
				// Sanity
				$this->fail( "Throttle parameters weren't saved for filter $filter" );
			}

			$timestamp = $timestamps[ $filter ] ?? null;
			if ( !$timestamp && !$dryRun ) {
				// Sanity check
				$this->fail( "The timestamp wasn't saved for filter $filter" );
			}

			$actions = unserialize( $histRow->afh_actions );
			if ( $historyThrottleParams[ $filter ] === null ) {
				// Invalid rate, disable throttle for the filter
				unset( $actions['throttle'] );
			} else {
				$actions['throttle'] = $historyThrottleParams[ $filter ];
			}

			$newHistoryRows[] = [
				'afh_user' => $user->getId(),
				'afh_user_text' => $user->getName(),
				'afh_timestamp' => $timestamp,
				'afh_changed_fields' => 'actions',
				'afh_actions' => serialize( $actions )
			] + get_object_vars( $histRow );
			$changeHistoryFilters[] = $filter;
		}

		$historyCount = count( $changeHistoryFilters );
		if ( $historyCount !== $affectedActionRows ) {
			// Sanity: prevent unexpected errors.
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

$maintClass = NormalizeThrottleParameters::class;
require_once RUN_MAINTENANCE_IF_MAIN;
