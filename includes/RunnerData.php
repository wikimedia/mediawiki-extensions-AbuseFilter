<?php

namespace MediaWiki\Extension\AbuseFilter;

use LogicException;
use MediaWiki\Extension\AbuseFilter\Parser\RuleCheckerStatus;

/**
 * Mutable value class storing and accumulating information about filter matches and runtime
 */
class RunnerData {

	/**
	 * @var array[]
	 * @phan-var array<string,array{time:float,conds:int,result:bool}>
	 */
	private $profilingData;

	public function __construct(
		private array $matchedFilters = [],
		array $profilingData = [],
		private float $totalRuntime = 0.0,
		private int $totalConditions = 0
	) {
		$this->profilingData = $profilingData;
	}

	/**
	 * Record (memorize) data from a filter run
	 *
	 * @param int $filterID
	 * @param bool $global
	 * @param RuleCheckerStatus $status
	 * @param float $timeTaken
	 */
	public function record( int $filterID, bool $global, RuleCheckerStatus $status, float $timeTaken ): void {
		$key = GlobalNameUtils::buildGlobalName( $filterID, $global );
		if ( array_key_exists( $key, $this->matchedFilters ) ) {
			throw new LogicException( "Filter '$key' has already been recorded" );
		}
		$this->matchedFilters[$key] = $status;
		$this->profilingData[$key] = [
			'time' => $timeTaken,
			'conds' => $status->getCondsUsed(),
			'result' => $status->getResult()
		];
		$this->totalRuntime += $timeTaken;
		$this->totalConditions += $status->getCondsUsed();
	}

	/**
	 * Get information about filter matches in backwards compatible format
	 * @return bool[]
	 * @phan-return array<string,bool>
	 */
	public function getMatchesMap(): array {
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
	public function getAllFilters(): array {
		return array_keys( $this->matchedFilters );
	}

	/**
	 * @return string[]
	 */
	public function getMatchedFilters(): array {
		return array_keys( array_filter( $this->getMatchesMap() ) );
	}

	/**
	 * @return array[]
	 */
	public function getProfilingData(): array {
		return $this->profilingData;
	}

	public function getTotalRuntime(): float {
		return $this->totalRuntime;
	}

	public function getTotalConditions(): int {
		return $this->totalConditions;
	}

	/**
	 * Serialize data for edit stash
	 * @return array
	 * @phan-return array{matches:array<string,array>,runtime:float,condCount:int,profiling:array}
	 */
	public function toArray(): array {
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
	public static function fromArray( array $value ): self {
		return new self(
			array_map( RuleCheckerStatus::fromArray( ... ), $value['matches'] ),
			$value['profiling'],
			$value['runtime'],
			$value['condCount']
		);
	}

}
