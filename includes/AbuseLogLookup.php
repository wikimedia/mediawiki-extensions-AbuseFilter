<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserIdentity;
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
		private readonly AbuseLogConditionFactory $afConditionFactory,
	) {
	}

	/**
	 * Fetches the number of abuse log entries triggered by the users and viewable by the specified authority.
	 *
	 * @param Authority $authority
	 * @param UserIdentity[] $userIdentities
	 * @return array<string,int> Map of username => hit count. All requested keys will be present in this array
	 *     (i.e. user with no hits will have explicit zero), provided that the authority has permissions to
	 *     view the abuse log.
	 */
	public function getHitCountsForUsers( Authority $authority, array $userIdentities ): array {
		if ( !$this->afPermissionManager->canViewAbuseLog( $authority ) ) {
			return [];
		}

		$userNames = array_map( static fn ( UserIdentity $user ) => $user->getName(), $userIdentities );

		$canSeeHidden = $this->afPermissionManager->canSeeHiddenLogEntries( $authority );
		$dbr = $this->dbProvider->getReplicaDatabase();

		$counts = array_fill_keys( $userNames, 0 );
		foreach ( array_chunk( $userIdentities, 100 ) as $userBatch ) {
			$queryBuilder = $dbr->newSelectQueryBuilder()
				->select( [ 'afl_user_text', 'count' => 'COUNT(*)' ] )
				->from( 'abuse_filter_log' )
				->groupBy( 'afl_user_text' )
				->caller( __METHOD__ );

			$alternatives = [];
			foreach ( $userBatch as $user ) {
				$cond = $this->afConditionFactory->getUserFilterByUserIdentity( $user );
				// We have to explicitly call andExpr, because it'll be a part of the big OR condition
				$alternatives[] = $dbr->andExpr( $cond );
			}
			$queryBuilder->where( $dbr->orExpr( $alternatives ) );

			// Suppressed (hidden) entries are only counted for viewers allowed to see them
			if ( !$canSeeHidden ) {
				$queryBuilder->andWhere( [ 'afl_deleted' => 0 ] );
			}

			foreach ( $queryBuilder->fetchResultSet() as $row ) {
				$counts[$row->afl_user_text] = (int)$row->count;
			}
		}

		return $counts;
	}
}
