<?php

namespace MediaWiki\Extension\AbuseFilter;

use AbuseFilter;
use DBAccessObjectUtils;
use IDBAccessObject;
use MediaWiki\Extension\AbuseFilter\Filter\Filter;
use MediaWiki\Extension\AbuseFilter\Filter\FilterNotFoundException;
use MediaWiki\Extension\AbuseFilter\Filter\FilterVersionNotFoundException;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Filter\LastEditInfo;
use MediaWiki\Extension\AbuseFilter\Filter\Specs;
use stdClass;
use WANObjectCache;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * This class provides read access to the filters stored in the database.
 */
class FilterLookup implements IDBAccessObject {
	public const SERVICE_NAME = 'AbuseFilterFilterLookup';

	/**
	 * @var Filter[] Individual filters cache. Keys can be integer IDs, or global names
	 */
	private $cache = [];

	/**
	 * @var Filter[][][] Cache of all active filters in each group. This is not related to
	 * the individual cache, and is replicated in WAN cache. The structure is
	 * [ local|global => [ group => [ ID => filter ] ] ]
	 * where the cache for each group has the same format as $this->cache
	 * Note that the keys are also in the form 'global-ID' for filters in 'global', although redundant.
	 */
	private $groupCache = [ 'local' => [], 'global' => [] ];

	/** @var Filter[] */
	private $historyCache = [];

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var WANObjectCache */
	private $wanCache;

	/** @var CentralDBManager */
	private $centralDBManager;

	/**
	 * @param ILoadBalancer $loadBalancer
	 * @param WANObjectCache $cache
	 * @param CentralDBManager $centralDBManager
	 */
	public function __construct(
		ILoadBalancer $loadBalancer,
		WANObjectCache $cache,
		CentralDBManager $centralDBManager
	) {
		$this->loadBalancer = $loadBalancer;
		$this->wanCache = $cache;
		$this->centralDBManager = $centralDBManager;
	}

	/**
	 * @param int $filterID
	 * @param bool $global
	 * @param int $flags One of the self::READ_* constants
	 * @return Filter
	 * @throws FilterNotFoundException if the filter doesn't exist
	 * @throws CentralDBNotAvailableException
	 */
	public function getFilter( int $filterID, bool $global, int $flags = self::READ_NORMAL ) : Filter {
		$cacheKey = $this->getCacheKey( $filterID, $global );
		if ( $flags !== self::READ_NORMAL || !isset( $this->cache[$cacheKey] ) ) {
			[ $dbIndex, $dbOptions ] = DBAccessObjectUtils::getDBOptions( $flags );
			$dbr = $this->getDBConnection( $dbIndex, $global );

			$row = $dbr->selectRow(
				'abuse_filter',
				AbuseFilter::ALL_ABUSE_FILTER_FIELDS,
				[ 'af_id' => $filterID ],
				__METHOD__,
				$dbOptions
			);
			if ( !$row ) {
				throw new FilterNotFoundException( $filterID, $global );
			}
			$fname = __METHOD__;
			$getActionsCB = function () use ( $dbr, $fname, $row ) : array {
				return $this->getActionsFromDB( $dbr, $fname, $row->af_id );
			};
			$this->cache[$cacheKey] = $this->filterFromRow( $row, $getActionsCB );
		}

		return $this->cache[$cacheKey];
	}

	/**
	 * Get all filters that are active (and not deleted) and in the given group
	 * @param string $group
	 * @param bool $global
	 * @param int $flags
	 * @return Filter[]
	 * @throws CentralDBNotAvailableException
	 */
	public function getAllActiveFiltersInGroup( string $group, bool $global, int $flags = self::READ_NORMAL ) : array {
		$domainKey = $global ? 'global' : 'local';
		if ( $flags !== self::READ_NORMAL || !isset( $this->groupCache[$domainKey][$group] ) ) {
			if ( $global ) {
				$globalRulesKey = $this->getGlobalRulesKey( $group );
				$ret = $this->wanCache->getWithSetCallback(
					$globalRulesKey,
					WANObjectCache::TTL_WEEK,
					function () use ( $group, $global, $flags ) {
						return $this->getAllActiveFiltersInGroupFromDB( $group, $global, $flags );
					},
					[
						'checkKeys' => [ $globalRulesKey ],
						'lockTSE' => 300,
						'version' => 2
					]
				);
			} else {
				$ret = $this->getAllActiveFiltersInGroupFromDB( $group, $global, $flags );
			}

			$this->groupCache[$domainKey][$group] = [];
			foreach ( $ret as $key => $filter ) {
				$this->groupCache[$domainKey][$group][$key] = $filter;
				$this->cache[$key] = $filter;
			}
		}
		return $this->groupCache[$domainKey][$group];
	}

	/**
	 * @param string $group
	 * @param bool $global
	 * @param int $flags
	 * @return array
	 */
	private function getAllActiveFiltersInGroupFromDB( string $group, bool $global, int $flags ) : array {
		[ $dbIndex, $dbOptions ] = DBAccessObjectUtils::getDBOptions( $flags );
		$dbr = $this->getDBConnection( $dbIndex, $global );

		$where = [
			'af_enabled' => 1,
			'af_deleted' => 0,
			'af_group' => $group,
		];
		if ( $global ) {
			$where['af_global'] = 1;
		}

		// Note, excluding individually cached filter now wouldn't help much, so take it as
		// an occasion to refresh the cache later
		$rows = $dbr->select(
			'abuse_filter',
			AbuseFilter::ALL_ABUSE_FILTER_FIELDS,
			$where,
			__METHOD__,
			$dbOptions
		);

		$fname = __METHOD__;
		$ret = [];
		foreach ( $rows as $row ) {
			$filterKey = $this->getCacheKey( $row->af_id, $global );
			$getActionsCB = function () use ( $dbr, $fname, $row ) : array {
				return $this->getActionsFromDB( $dbr, $fname, $row->af_id );
			};
			$ret[$filterKey] = $this->filterFromRow(
				$row,
				// Don't pass a closure if global, as this is going to be serialized when caching
				$global ? $getActionsCB() : $getActionsCB
			);
		}
		return $ret;
	}

	/**
	 * @param int $dbIndex
	 * @param bool $global
	 * @return IDatabase
	 * @throws CentralDBNotAvailableException
	 */
	private function getDBConnection( int $dbIndex, bool $global ) : IDatabase {
		if ( $global ) {
			return $this->centralDBManager->getConnection( $dbIndex );
		} else {
			return $this->loadBalancer->getConnectionRef( $dbIndex );
		}
	}

	/**
	 * @param IDatabase $db
	 * @param string $fname
	 * @param int $id
	 * @return array
	 */
	private function getActionsFromDB( IDatabase $db, string $fname, int $id ) : array {
		$res = $db->select(
			'abuse_filter_action',
			[ 'afa_consequence', 'afa_parameters' ],
			[ 'afa_filter' => $id ],
			$fname
		);

		$actions = [];
		foreach ( $res as $actionRow ) {
			$actions[$actionRow->afa_consequence] =
				array_filter( explode( "\n", $actionRow->afa_parameters ) );
		}
		return $actions;
	}

	/**
	 * Get an old version of the given (local) filter, with its actions
	 *
	 * @param int $version Unique identifier of the version
	 * @param int $flags
	 * @return Filter
	 * @throws FilterVersionNotFoundException if the version doesn't exist
	 */
	public function getFilterVersion(
		int $version,
		int $flags = self::READ_NORMAL
	) : Filter {
		if ( $flags !== self::READ_NORMAL || !isset( $this->historyCache[$version] ) ) {
			[ $dbIndex, $dbOptions ] = DBAccessObjectUtils::getDBOptions( $flags );
			$dbr = $this->loadBalancer->getConnectionRef( $dbIndex );

			$row = $dbr->selectRow(
				'abuse_filter_history',
				'*',
				[ 'afh_id' => $version ],
				__METHOD__,
				$dbOptions
			);
			if ( !$row ) {
				throw new FilterVersionNotFoundException( $version );
			}
			$this->historyCache[$version] = $this->getFilterFromHistory( $row );
		}

		return $this->historyCache[$version];
	}

	/**
	 * Resets the internal cache of Filter objects
	 */
	public function clearLocalCache() : void {
		$this->cache = [];
		$this->groupCache = [ 'local' => [], 'global' => [] ];
		$this->historyCache = [];
	}

	/**
	 * Purge the shared cache of global filters in the given group.
	 * @note This doesn't purge the local cache
	 * @param string $group
	 */
	public function purgeGroupWANCache( string $group ) : void {
		$this->wanCache->touchCheckKey( $this->getGlobalRulesKey( $group ) );
	}

	/**
	 * @param string $group The filter's group (as defined in $wgAbuseFilterValidGroups)
	 * @return string
	 */
	private function getGlobalRulesKey( string $group ) : string {
		if ( !$this->centralDBManager->filterIsCentral() ) {
			return $this->wanCache->makeGlobalKey(
				'abusefilter',
				'rules',
				$this->centralDBManager->getCentralDBName(),
				$group
			);
		}

		return $this->wanCache->makeKey( 'abusefilter', 'rules', $group );
	}

	/**
	 * Translate an abuse_filter_history row into an abuse_filter row and a list of actions
	 * @param stdClass $row
	 * @return Filter
	 */
	private function getFilterFromHistory( stdClass $row ) : Filter {
		$af_row = new stdClass;

		foreach ( AbuseFilter::HISTORY_MAPPINGS as $af_col => $afh_col ) {
			$af_row->$af_col = $row->$afh_col;
		}

		// Process flags
		$flags = $row->afh_flags ? explode( ',', $row->afh_flags ) : [];
		foreach ( [ 'enabled', 'hidden', 'deleted', 'global' ] as $flag ) {
			$af_row->{"af_$flag"} = (int)in_array( $flag, $flags, true );
		}

		$actionsRaw = unserialize( $row->afh_actions );
		$actionsOutput = is_array( $actionsRaw ) ? $actionsRaw : [];
		$af_row->af_actions = implode( ',', array_keys( $actionsOutput ) );

		return $this->filterFromRow( $af_row, $actionsOutput );
	}

	/**
	 * Note: this is private because no external caller should access DB rows directly.
	 * @param stdClass $row
	 * @param array[]|callable $actions
	 * @return Filter
	 */
	private function filterFromRow( stdClass $row, $actions ) : Filter {
		return new Filter(
			new Specs(
				trim( $row->af_pattern ),
				// FIXME: Make the DB fields for these NOT NULL (T263324)
				(string)$row->af_comments,
				(string)$row->af_public_comments,
				$row->af_actions !== '' ? explode( ',', $row->af_actions ) : [],
				$row->af_group
			),
			new Flags(
				(bool)$row->af_enabled,
				(bool)$row->af_deleted,
				(bool)$row->af_hidden,
				(bool)$row->af_global
			),
			$actions,
			new LastEditInfo(
				(int)$row->af_user,
				$row->af_user_text,
				$row->af_timestamp
			),
			(int)$row->af_id,
			isset( $row->af_hit_count ) ? (int)$row->af_hit_count : null,
			isset( $row->af_throttled ) ? (bool)$row->af_throttled : null
		);
	}

	/**
	 * @param int $filterID
	 * @param bool $global
	 * @return string
	 */
	private function getCacheKey( int $filterID, bool $global ) : string {
		return AbuseFilter::buildGlobalName( $filterID, $global );
	}
}
