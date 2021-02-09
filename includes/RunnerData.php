<?php

namespace MediaWiki\Extension\AbuseFilter;

use LogicException;
use MediaWiki\Extension\AbuseFilter\Parser\ParserStatus;

/**
 * Mutable value class storing and accumulating information about filter matches and runtime
 */
class RunnerData {

	/**
	 * @var ParserStatus[]
	 * @phan-var array<string,ParserStatus>
	 */
	private $matchedFilters;

	/**
	 * @var array[]
	 * @phan-var array<string,array{time:float,conds:int,result:bool}>
	 */
	private $profilingData;

	/** @var float */
	private $totalRuntime;

	/** @var int */
	private $totalConditions;

	/**
	 * @param ParserStatus[] $matchedFilters
	 * @param array[] $profilingData
	 * @param float $totalRuntime
	 * @param int $totalConditions
	 */
	public function __construct(
		array $matchedFilters = [],
		array $profilingData = [],
		float $totalRuntime = 0.0,
		int $totalConditions = 0
	) {
		$this->matchedFilters = $matchedFilters;
		$this->profilingData = $profilingData;
		$this->totalRuntime = $totalRuntime;
		$this->totalConditions = $totalConditions;
	}

	/**
	 * Record (memorize) data from a filter run
	 *
	 * @param int $filterID
	 * @param bool $global
	 * @param ParserStatus $status
	 * @param array $profilingData
	 * @phan-param array{time:float,conds:int} $profilingData
	 */
	public function record( int $filterID, bool $global, ParserStatus $status, array $profilingData ) : void {
		$key = GlobalNameUtils::buildGlobalName( $filterID, $global );
		if ( array_key_exists( $key, $this->matchedFilters ) ) {
			throw new LogicException( "Filter '$key' has already been recorded" );
		}
		$this->matchedFilters[$key] = $status;
		$this->profilingData[$key] = $profilingData + [ 'result' => $status->getResult() ];
		$this->totalRuntime += $profilingData['time'];
		$this->totalConditions += $profilingData['conds'];
	}

	/**
	 * Get information about filter matches in backwards compatible format
	 * @return bool[]
	 * @phan-return array<string,bool>
	 */
	public function getMatchesMap() : array {
		return array_map(
			static function ( $status ) {
				return $status->getResult();
			},
			$this->matchedFilters
		);
	}

	/**
	 * @return string[]
	 */
	public function getAllFilters() : array {
		return array_keys( $this->matchedFilters );
	}

	/**
	 * @return string[]
	 */
	public function getMatchedFilters() : array {
		return array_keys( array_filter( $this->getMatchesMap() ) );
	}

	/**
	 * @return array[]
	 */
	public function getProfilingData() : array {
		return $this->profilingData;
	}

	/**
	 * @return float
	 */
	public function getTotalRuntime() : float {
		return $this->totalRuntime;
	}

	/**
	 * @return int
	 */
	public function getTotalConditions() : int {
		return $this->totalConditions;
	}

	/**
	 * Serialize data for edit stash
	 * @return array
	 * @phan-return array{matches:array<string,array>,runtime:float,condCount:int,profiling:array}
	 */
	public function toArray() : array {
		return [
			'matches' => array_map(
				static function ( $status ) {
					return $status->toArray();
				},
				$this->matchedFilters
			),
			'profiling' => $this->profilingData,
			'condCount' => $this->totalConditions,
			'runtime' => $this->totalRuntime,
		];
	}

	/**
	 * Deserialize data from edit stash
	 * @param array $value
	 * @return self
	 */
	public static function fromArray( array $value ) : self {
		return new self(
			array_map( [ ParserStatus::class, 'fromArray' ], $value['matches'] ),
			$value['profiling'],
			$value['runtime'],
			$value['condCount']
		);
	}

}
