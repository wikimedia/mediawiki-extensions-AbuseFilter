<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\RawSQLExpression;

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
		private readonly CentralDBManager $centralDBManager,
		private readonly AbuseFilterPermissionManager $afPermissionManager,
		private readonly AbuseLogConditionFactory $afConditionFactory,
	) {
	}

	/**
	 * Fetches the number of abuse log entries triggered by the users and viewable by the specified authority.
	 * Keep the visibility rules in sync with {@see SpecialAbuseLog::getEntryVisibilityForUser()}.
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
		$suppressedFilterConds = $this->afPermissionManager->canViewSuppressed( $authority )
			? [] : $this->getSuppressedFilterConds( $dbr );
		$revisionCond = $this->getRevisionVisibilityCond( $dbr, $authority );

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

			if ( $suppressedFilterConds ) {
				$queryBuilder->andWhere( $suppressedFilterConds );
			}

			if ( $revisionCond ) {
				$queryBuilder->andWhere( $revisionCond );
				$queryBuilder->leftJoin( 'revision', null, [
					'rev_id = afl_rev_id',
					$dbr->expr( 'afl_wiki', '=', null ),
				] );
			}

			foreach ( $queryBuilder->fetchResultSet() as $row ) {
				$counts[$row->afl_user_text] = (int)$row->count;
			}
		}

		return $counts;
	}

	/**
	 * Get the conditions that remove entries of suppressed local and global filters.
	 *
	 * @return IExpression[]
	 */
	private function getSuppressedFilterConds( IReadableDatabase $dbr ): array {
		$conds = [];

		// Usually, there are not so many suppressed filters, so we can just list all of them
		// and use that as a condition
		$localIds = $this->getSuppressedFilterIds( $dbr );
		if ( $localIds ) {
			$conds[] = $dbr->expr( 'afl_global', '!=', 0 )
				->or( 'afl_filter_id', '!=', $localIds );
		}

		try {
			$globalIds = $this->getSuppressedFilterIds( $this->centralDBManager->getReplicaDatabase() );
			if ( $globalIds ) {
				$conds[] = $dbr->expr( 'afl_global', '!=', 1 )
					->or( 'afl_filter_id', '!=', $globalIds );
			}
		} catch ( CentralDBNotAvailableException ) {
			// We do not know which global filters are suppressed. Thus, remove all entries of global filters.
			$conds[] = $dbr->expr( 'afl_global', '=', 0 );
		}

		return $conds;
	}

	private function getSuppressedFilterIds( IReadableDatabase $dbr ): array {
		return $dbr->newSelectQueryBuilder()
			->select( 'af_id' )
			->from( 'abuse_filter' )
			->where( [ $dbr->bitAnd( 'af_hidden', Flags::FILTER_SUPPRESSED ) . ' != 0' ] )
			->caller( __METHOD__ )
			->fetchFieldValues();
	}

	/**
	 * Get the condition that removes entries for deleted revisions which the authority cannot see.
	 * This must agree with {@see AbuseFilterPermissionManager::hasRevisionAccess()}.
	 *
	 * @return IExpression|null Null if the authority can see all revisions
	 */
	private function getRevisionVisibilityCond( IReadableDatabase $dbr, Authority $authority ): ?IExpression {
		// Users with suppression access can see everything, no need to check the revision flags
		$canSeeSuppressed = $authority->isAllowedAny( 'suppressrevision', 'viewsuppressed' );
		if ( $canSeeSuppressed ) {
			return null;
		}

		$canSeeDeletedText = $authority->isAllowed( 'deletedtext' );
		$canSeeDeletedHistory = $authority->isAllowed( 'deletedhistory' );

		if ( !$canSeeDeletedHistory && !$canSeeDeletedText ) {
			// Typical case, optimized to have no bitwise AND
			$visibilityCond = $dbr->expr( 'rev_deleted', '=', 0 );
		} else {
			// Bits representing properties that the user cannot see
			$mask = RevisionRecord::DELETED_RESTRICTED;
			if ( !$canSeeDeletedText ) {
				$mask |= RevisionRecord::DELETED_TEXT;
			}
			if ( !$canSeeDeletedHistory ) {
				$mask |= RevisionRecord::DELETED_COMMENT | RevisionRecord::DELETED_USER;
			}
			$visibilityCond = new RawSQLExpression( $dbr->bitAnd( 'rev_deleted', $mask ) . ' = 0' );
		}

		$alternatives = [
			$dbr->expr( 'rev_id', '=', null ),
			$visibilityCond,
		];
		return $dbr->orExpr( $alternatives );
	}
}
