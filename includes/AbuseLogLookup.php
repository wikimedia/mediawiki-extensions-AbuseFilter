<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Permissions\Authority;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * A service to help with lookups to the abuse log. Currently, it only allows the caller to ask
 * for the total number of log entries triggered by a specific user.
 *
 * @since 1.47
 */
class AbuseLogLookup {

	public const string SERVICE_NAME = ServiceNames::AbuseLogLookup;

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly AbuseFilterPermissionManager $afPermissionManager,
	) {
	}

	/**
	 * Fetches the number of abuse log entries triggered by the users and viewable by the specified authority.
	 *
	 * @param Authority $authority
	 * @param int[] $userIds
	 * @return array<int,int> Map of user ID => hit count. All requested keys will be present in this array
	 *     (i.e. user with no hits will have explicit zero), provided that the authority has permissions to
	 *     view the abuse log.
	 */
	public function getHitCountsForUsers( Authority $authority, array $userIds ): array {
		if ( !$this->afPermissionManager->canViewAbuseLog( $authority ) ) {
			return [];
		}

		$canSeeHidden = $this->afPermissionManager->canSeeHiddenLogEntries( $authority );
		$dbr = $this->dbProvider->getReplicaDatabase();

		$counts = array_fill_keys( $userIds, 0 );
		foreach ( array_chunk( $userIds, 100 ) as $userIdBatch ) {
			$queryBuilder = $dbr->newSelectQueryBuilder()
				->select( [ 'afl_user', 'count' => 'COUNT(*)' ] )
				->from( 'abuse_filter_log' )
				->where( [
					'afl_user' => $userIdBatch
				] )
				->groupBy( 'afl_user' )
				->caller( __METHOD__ );

			// Suppressed (hidden) entries are only counted for viewers allowed to see them
			if ( !$canSeeHidden ) {
				$queryBuilder->andWhere( [ 'afl_deleted' => 0 ] );
			}

			foreach ( $queryBuilder->fetchResultSet() as $row ) {
				$counts[(int)$row->afl_user] = (int)$row->count;
			}
		}

		return $counts;
	}
}
