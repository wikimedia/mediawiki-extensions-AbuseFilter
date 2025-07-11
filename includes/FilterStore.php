<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Extension\AbuseFilter\ChangeTags\ChangeTagsManager;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesRegistry;
use MediaWiki\Extension\AbuseFilter\Filter\Filter;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseFilter;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Permissions\Authority;
use MediaWiki\Status\Status;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\LBFactory;

/**
 * @internal
 */
class FilterStore {
	public const SERVICE_NAME = 'AbuseFilterFilterStore';

	/** @var ConsequencesRegistry */
	private $consequencesRegistry;

	/** @var LBFactory */
	private $lbFactory;

	/** @var ActorNormalization */
	private $actorNormalization;

	/** @var FilterProfiler */
	private $filterProfiler;

	/** @var FilterLookup */
	private $filterLookup;

	/** @var ChangeTagsManager */
	private $tagsManager;

	/** @var FilterValidator */
	private $filterValidator;

	/** @var FilterCompare */
	private $filterCompare;

	/** @var EmergencyCache */
	private $emergencyCache;

	/**
	 * @param ConsequencesRegistry $consequencesRegistry
	 * @param LBFactory $lbFactory
	 * @param ActorNormalization $actorNormalization
	 * @param FilterProfiler $filterProfiler
	 * @param FilterLookup $filterLookup
	 * @param ChangeTagsManager $tagsManager
	 * @param FilterValidator $filterValidator
	 * @param FilterCompare $filterCompare
	 * @param EmergencyCache $emergencyCache
	 */
	public function __construct(
		ConsequencesRegistry $consequencesRegistry,
		LBFactory $lbFactory,
		ActorNormalization $actorNormalization,
		FilterProfiler $filterProfiler,
		FilterLookup $filterLookup,
		ChangeTagsManager $tagsManager,
		FilterValidator $filterValidator,
		FilterCompare $filterCompare,
		EmergencyCache $emergencyCache
	) {
		$this->consequencesRegistry = $consequencesRegistry;
		$this->lbFactory = $lbFactory;
		$this->actorNormalization = $actorNormalization;
		$this->filterProfiler = $filterProfiler;
		$this->filterLookup = $filterLookup;
		$this->tagsManager = $tagsManager;
		$this->filterValidator = $filterValidator;
		$this->filterCompare = $filterCompare;
		$this->emergencyCache = $emergencyCache;
	}

	/**
	 * Checks whether user input for the filter editing form is valid and if so saves the filter.
	 * Returns a Status object which can be:
	 *  - Good with [ new_filter_id, history_id ] as value if the filter was successfully saved
	 *  - Good with value = false if everything went fine but the filter is unchanged
	 *  - OK with errors if a validation error occurred
	 *  - Fatal in case of a permission-related error
	 *
	 * @param Authority $performer
	 * @param int|null $filterId
	 * @param Filter $newFilter
	 * @param Filter $originalFilter
	 * @return Status
	 */
	public function saveFilter(
		Authority $performer,
		?int $filterId,
		Filter $newFilter,
		Filter $originalFilter
	): Status {
		$validationStatus = $this->filterValidator->checkAll( $newFilter, $originalFilter, $performer );
		if ( !$validationStatus->isGood() ) {
			return $validationStatus;
		}

		// Check for non-changes
		$differences = $this->filterCompare->compareVersions( $newFilter, $originalFilter );
		if ( !$differences ) {
			return Status::newGood( false );
		}

		// Everything went fine, so let's save the filter
		$wasGlobal = $originalFilter->isGlobal();
		[ $newID, $historyID ] = $this->doSaveFilter(
			$performer->getUser(), $newFilter, $originalFilter, $differences, $filterId, $wasGlobal );
		return Status::newGood( [ $newID, $historyID ] );
	}

	/**
	 * Saves new filter's info to DB
	 *
	 * @param UserIdentity $userIdentity
	 * @param Filter $newFilter
	 * @param Filter $originalFilter
	 * @param array $differences
	 * @param int|null $filterId
	 * @param bool $wasGlobal
	 * @return int[] first element is new ID, second is history ID
	 */
	private function doSaveFilter(
		UserIdentity $userIdentity,
		Filter $newFilter,
		Filter $originalFilter,
		array $differences,
		?int $filterId,
		bool $wasGlobal
	): array {
		$dbw = $this->lbFactory->getPrimaryDatabase();
		$newRow = $this->filterToDatabaseRow( $newFilter, $originalFilter );

		// Set last modifier.
		$newRow['af_timestamp'] = $dbw->timestamp();
		$newRow['af_actor'] = $this->actorNormalization->acquireActorId( $userIdentity, $dbw );

		$isNew = $filterId === null;

		// Preserve the old throttled status (if any) only if disabling the filter.
		// TODO: It might make more sense to check what was actually changed
		$newRow['af_throttled'] = ( $newRow['af_throttled'] ?? false ) && !$newRow['af_enabled'];
		// This is null when creating a new filter, but the DB field is NOT NULL
		$newRow['af_hit_count'] ??= 0;
		$rowForInsert = array_diff_key( $newRow, [ 'af_id' => true ] );

		$dbw->startAtomic( __METHOD__ );
		if ( $filterId === null ) {
			$dbw->newInsertQueryBuilder()
				->insertInto( 'abuse_filter' )
				->row( $rowForInsert )
				->caller( __METHOD__ )
				->execute();
			$filterId = $dbw->insertId();
		} else {
			$dbw->newUpdateQueryBuilder()
				->update( 'abuse_filter' )
				->set( $rowForInsert )
				->where( [ 'af_id' => $filterId ] )
				->caller( __METHOD__ )
				->execute();
		}
		$newRow['af_id'] = $filterId;

		$actions = $newFilter->getActions();
		$actionsRows = [];
		foreach ( $this->consequencesRegistry->getAllEnabledActionNames() as $action ) {
			if ( !isset( $actions[$action] ) ) {
				continue;
			}

			$parameters = $actions[$action];
			if ( $action === 'throttle' && $parameters[0] === null ) {
				// FIXME: Do we really need to keep the filter ID inside throttle parameters?
				// We'd save space, keep things simpler and avoid this hack. Note: if removing
				// it, a maintenance script will be necessary to clean up the table.
				$parameters[0] = $filterId;
			}

			$actionsRows[] = [
				'afa_filter' => $filterId,
				'afa_consequence' => $action,
				'afa_parameters' => implode( "\n", $parameters ),
			];
		}

		// Create a history row
		$afhRow = [];

		foreach ( AbuseFilter::HISTORY_MAPPINGS as $afCol => $afhCol ) {
			// Some fields are expected to be missing during actor migration
			if ( isset( $newRow[$afCol] ) ) {
				$afhRow[$afhCol] = $newRow[$afCol];
			}
		}

		$afhRow['afh_actions'] = serialize( $actions );

		$afhRow['afh_changed_fields'] = implode( ',', $differences );

		$flags = [];
		if ( FilterUtils::isHidden( $newRow['af_hidden'] ) ) {
			$flags[] = 'hidden';
		}
		if ( FilterUtils::isProtected( $newRow['af_hidden'] ) ) {
			$flags[] = 'protected';
		}
		if ( $newRow['af_enabled'] ) {
			$flags[] = 'enabled';
		}
		if ( $newRow['af_deleted'] ) {
			$flags[] = 'deleted';
		}
		if ( $newRow['af_global'] ) {
			$flags[] = 'global';
		}

		$afhRow['afh_flags'] = implode( ',', $flags );

		$afhRow['afh_filter'] = $filterId;

		// Do the update
		$dbw->newInsertQueryBuilder()
			->insertInto( 'abuse_filter_history' )
			->row( $afhRow )
			->caller( __METHOD__ )
			->execute();
		$historyID = $dbw->insertId();
		if ( !$isNew ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'abuse_filter_action' )
				->where( [ 'afa_filter' => $filterId ] )
				->caller( __METHOD__ )
				->execute();
		}
		if ( $actionsRows ) {
			$dbw->newInsertQueryBuilder()
				->insertInto( 'abuse_filter_action' )
				->rows( $actionsRows )
				->caller( __METHOD__ )
				->execute();
		}

		$dbw->endAtomic( __METHOD__ );

		// Invalidate cache if this was a global rule
		if ( $wasGlobal || $newRow['af_global'] ) {
			$this->filterLookup->purgeGroupWANCache( $newRow['af_group'] );
		}

		// Logging
		$logEntry = new ManualLogEntry( 'abusefilter', $isNew ? 'create' : 'modify' );
		$logEntry->setPerformer( $userIdentity );
		$logEntry->setTarget( SpecialAbuseFilter::getTitleForSubpage( (string)$filterId ) );
		$logEntry->setParameters( [
			'historyId' => $historyID,
			'newId' => $filterId
		] );
		$logid = $logEntry->insert( $dbw );
		$logEntry->publish( $logid );

		// Purge the tag list cache so the fetchAllTags hook applies tag changes
		if ( isset( $actions['tag'] ) ) {
			$this->tagsManager->purgeTagCache();
		}

		$this->filterProfiler->resetFilterProfile( $filterId );
		if ( $newRow['af_enabled'] ) {
			$this->emergencyCache->setNewForFilter( $filterId, $newRow['af_group'] );
		}
		return [ $filterId, $historyID ];
	}

	/**
	 * @todo Perhaps add validation to ensure no null values remained.
	 * @note For simplicity, data about the last editor are omitted.
	 * @param Filter $filter
	 * @return array
	 */
	private function filterToDatabaseRow( Filter $filter, Filter $originalFilter ): array {
		// T67807: integer 1's & 0's might be better understood than booleans

		// If the filter is already protected, it must remain protected even if
		// the current filter doesn't use a protected variable anymore
		// FIXME: Resposibility for this is currently unclear. It should be
		// enforced prior to the FilterCompare::compareVersions call to avoid
		// dummy filter versions.
		$privacyLevel = $filter->getPrivacyLevel();
		if ( $originalFilter->isProtected() ) {
			$privacyLevel |= Flags::FILTER_USES_PROTECTED_VARS;
		}

		return [
			'af_id' => $filter->getID(),
			'af_pattern' => $filter->getRules(),
			'af_public_comments' => $filter->getName(),
			'af_comments' => $filter->getComments(),
			'af_group' => $filter->getGroup(),
			'af_actions' => implode( ',', $filter->getActionsNames() ),
			'af_enabled' => (int)$filter->isEnabled(),
			'af_deleted' => (int)$filter->isDeleted(),
			'af_hidden' => $privacyLevel,
			'af_global' => (int)$filter->isGlobal(),
			'af_timestamp' => $filter->getTimestamp(),
			'af_hit_count' => $filter->getHitCount(),
			'af_throttled' => (int)$filter->isThrottled(),
		];
	}
}
