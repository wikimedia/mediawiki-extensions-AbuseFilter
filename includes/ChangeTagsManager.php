<?php

namespace MediaWiki\Extension\AbuseFilter;

use ChangeTags;
use IDatabase;
use MediaWiki\Config\ServiceOptions;
use WANObjectCache;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\LBFactory;

/**
 * Database wrapper class which aids registering and reserving change tags
 * used by relevant abuse filters
 * @todo Consider verbose constants instead of boolean?
 */
class ChangeTagsManager {

	public const SERVICE_NAME = 'AbuseFilterChangeTagsManager';
	private const CONDS_LIMIT_TAG = 'abusefilter-condition-limit';

	public const CONSTRUCTOR_OPTIONS = [
		'AbuseFilterCentralDB',
		'AbuseFilterIsCentral',
	];

	/** @var LBFactory */
	private $lbFactory;

	/** @var WANObjectCache */
	private $cache;

	/** @var string|false */
	private $centralDB;

	/** @var bool */
	private $isCentral;

	/**
	 * @param LBFactory $lbFactory
	 * @param WANObjectCache $cache
	 * @param ServiceOptions $options
	 */
	public function __construct(
		LBFactory $lbFactory,
		WANObjectCache $cache,
		ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->lbFactory = $lbFactory;
		$this->cache = $cache;
		$this->centralDB = $options->get( 'AbuseFilterCentralDB' );
		$this->isCentral = $options->get( 'AbuseFilterIsCentral' );
	}

	/**
	 * Purge all cache related to tags, both within AbuseFilter and in core
	 */
	public function purgeTagCache() : void {
		// xxx: this doesn't purge all existing caches, see T266105
		ChangeTags::purgeTagCacheAll();
		$this->cache->delete( $this->getCacheKeyForStatus( true ) );
		$this->cache->delete( $this->getCacheKeyForStatus( false ) );
	}

	/**
	 * Return tags used by any active (enabled) filter, both local and global.
	 * @return string[]
	 */
	public function getTagsDefinedByActiveFilters() : array {
		return $this->loadTags( true );
	}

	/**
	 * Return tags used by any filter that is not deleted, both local and global.
	 * @return string[]
	 */
	public function getTagsDefinedByFilters() : array {
		return $this->loadTags( false );
	}

	/**
	 * @param IDatabase $dbr
	 * @param bool $enabled
	 * @param bool $global
	 * @return string[]
	 */
	private function loadTagsFromDb( IDatabase $dbr, bool $enabled, bool $global = false ) : array {
		// This is a pretty awful hack.
		$where = [
			'afa_consequence' => 'tag',
			'af_deleted' => 0
		];
		if ( $enabled ) {
			$where['af_enabled'] = 1;
		}
		if ( $global ) {
			$where['af_global'] = 1;
		}

		$res = $dbr->select(
			[ 'abuse_filter_action', 'abuse_filter' ],
			'afa_parameters',
			$where,
			__METHOD__,
			[],
			[ 'abuse_filter' => [ 'INNER JOIN', 'afa_filter=af_id' ] ]
		);

		$tags = [];
		foreach ( $res as $row ) {
			$tags = array_merge(
				$row->afa_parameters ? explode( "\n", $row->afa_parameters ) : [],
				$tags
			);
		}
		return $tags;
	}

	/**
	 * @param bool $enabled
	 * @return string[]
	 */
	private function loadTags( bool $enabled ) : array {
		return $this->cache->getWithSetCallback(
			$this->getCacheKeyForStatus( $enabled ),
			WANObjectCache::TTL_MINUTE,
			function ( $oldValue, &$ttl, array &$setOpts ) use ( $enabled ) {
				$dbr = $this->lbFactory->getMainLB()->getConnectionRef( DB_REPLICA );
				$globalDbr = $this->getGlobalDbConnection();

				if ( $globalDbr !== null ) {
					// Account for any snapshot/replica DB lag
					$setOpts += Database::getCacheSetOptions( $dbr, $globalDbr );
					$tags = array_merge(
						$this->loadTagsFromDb( $dbr, $enabled ),
						$this->loadTagsFromDb( $globalDbr, $enabled, true )
					);
				} else {
					$setOpts += Database::getCacheSetOptions( $dbr );
					$tags = $this->loadTagsFromDb( $dbr, $enabled );
				}

				return array_unique( $tags );
			}
		);
	}

	/**
	 * @return IDatabase|null
	 */
	private function getGlobalDbConnection() : ?IDatabase {
		if ( $this->centralDB && !$this->isCentral ) {
			return $this->lbFactory->getMainLB( $this->centralDB )
				->getConnectionRef( DB_REPLICA, [], $this->centralDB );
		} else {
			return null;
		}
	}

	/**
	 * @param bool $enabled
	 * @return string
	 */
	private function getCacheKeyForStatus( bool $enabled ) : string {
		return $this->cache->makeKey( 'abusefilter-fetch-all-tags', (int)$enabled );
	}

	/**
	 * Get the tag identifier used to indicate a change has exceeded the condition limit
	 * @return string
	 */
	public function getCondsLimitTag() : string {
		return self::CONDS_LIMIT_TAG;
	}
}
