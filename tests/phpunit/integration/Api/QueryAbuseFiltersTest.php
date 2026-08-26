<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Api;

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Extension\AbuseFilter\Tests\Integration\ProtectedVarsTestTrait;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Api\QueryAbuseFilters
 * @group API
 * @group Database
 */
class QueryAbuseFiltersTest extends ApiTestCase {
	use MockAuthorityTrait;
	use ProtectedVarsTestTrait;

	private static Authority $authorityCanViewPublic;
	private static Authority $authorityCanViewPrivate;
	private static Authority $authorityCanViewProtected;
	private static Authority $authorityCanViewSuppressed;
	private static Authority $authorityCanViewAll;

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clearProtectedVarRelatedHooks();
	}

	/**
	 * @inheritDoc
	 */
	public function addDBDataOnce(): void {
		$this->createFiltersWithProtectedVariables();

		$filterStore = AbuseFilterServices::getFilterStore();
		$performer = $this->getTestSysop()->getUserIdentity();
		$authority = new UltimateAuthority( $performer );

		// Create a third filter which is private and deleted
		$this->assertStatusGood( $filterStore->saveFilter(
			$authority,
			null,
			$this->getFilterFromSpecs( [
				'id' => '3',
				'rules' => 'action = "edit"',
				'name' => 'Private filter',
				'privacy' => Flags::FILTER_HIDDEN,
				'hitCount' => 42,
				'enabled' => 0,
				'deleted' => 1,
			] ),
			MutableFilter::newDefault()
		) );

		// Create a fourth filter which is suppressed
		$this->assertStatusGood( $filterStore->saveFilter(
			$authority,
			null,
			$this->getFilterFromSpecs( [
				'id' => '4',
				'rules' => 'action = "edit"',
				'name' => 'Suppressed filter',
				'privacy' => Flags::FILTER_SUPPRESSED,
				'hitCount' => 9,
			] ),
			MutableFilter::newDefault()
		) );

		$basicPerms = [
			'abusefilter-view',
			'abusefilter-log',
			'abusefilter-log-detail',
		];

		self::$authorityCanViewPublic = $this->mockUserAuthorityWithPermissions(
			$this->getTestUser()->getUserIdentity(),
			[ 'abusefilter-view' ]
		);
		self::$authorityCanViewPrivate = $this->mockUserAuthorityWithPermissions(
			$this->getTestUser()->getUserIdentity(),
			[ ...$basicPerms, 'abusefilter-view-private' ]
		);
		self::$authorityCanViewProtected = $this->mockUserAuthorityWithPermissions(
			$this->getTestUser()->getUserIdentity(),
			[ ...$basicPerms, 'abusefilter-access-protected-vars' ]
		);
		self::$authorityCanViewSuppressed = $this->mockUserAuthorityWithPermissions(
			$this->getTestUser()->getUserIdentity(),
			[ ...$basicPerms, 'viewsuppressed' ]
		);
		self::$authorityCanViewAll = $this->mockUserAuthorityWithPermissions(
			$this->getTestUser()->getUserIdentity(),
			[
				...$basicPerms,
				'abusefilter-view-private',
				'abusefilter-access-protected-vars',
				'viewsuppressed',
			]
		);
	}

	/**
	 * @return array{0:array,1:array} `[ $filters, $response ]`
	 */
	private function doQuery( Authority $performer, array $params = [] ): array {
		$params += [
			'action' => 'query',
			'list' => 'abusefilters',
			'abfprop' => 'id|description|pattern|actions|hits|comments|' .
				'lasteditor|lastedittime|status|suppressed|private|protected',
		];

		[ $result ] = $this->doApiRequest( $params, performer: $performer );

		$this->assertArrayContains( [ 'query' => [ 'abusefilters' => [] ] ], $result );

		return [ $result['query']['abusefilters'], $result ];
	}

	public function testExecuteAsUnauthorizedUser(): void {
		$this->expectApiErrorCode( 'permissiondenied' );
		$this->doQuery( $this->mockRegisteredNullAuthority() );
	}

	/**
	 * @dataProvider provideExecuteWithRangeSpecifications
	 */
	public function testExecuteWithRangeSpecifications(
		string $dir,
		?int $startId,
		?int $endId,
		array $expectedIds
	): void {
		[ $filters ] = $this->doQuery(
			self::$authorityCanViewAll,
			[ 'abfprop' => 'id' ] + array_filter( [
				'abfdir' => $dir,
				'abfstartid' => $startId,
				'abfendid' => $endId,
			], static fn ( $value ) => $value !== null )
		);

		$this->assertSame( $expectedIds, array_column( $filters, 'id' ) );
	}

	public static function provideExecuteWithRangeSpecifications(): array {
		return [
			'newer, no range' => [
				'dir' => 'newer',
				'startId' => null,
				'endId' => null,
				'expectedIds' => [ 1, 2, 3, 4 ],
			],
			'newer, startid only' => [
				'dir' => 'newer',
				'startId' => 2,
				'endId' => null,
				'expectedIds' => [ 2, 3, 4 ],
			],
			'newer, endid only' => [
				'dir' => 'newer',
				'startId' => null,
				'endId' => 2,
				'expectedIds' => [ 1, 2 ],
			],
			'newer, startid and endid' => [
				'dir' => 'newer',
				'startId' => 1,
				'endId' => 3,
				'expectedIds' => [ 1, 2, 3 ],
			],
			// TODO: This should not return an empty array (T435833)
			'newer, startid greater than endid' => [
				'dir' => 'newer',
				'startId' => 4,
				'endId' => 1,
				'expectedIds' => [],
			],
			'older, no range' => [
				'dir' => 'older',
				'startId' => null,
				'endId' => null,
				'expectedIds' => [ 4, 3, 2, 1 ],
			],
			'older, startid only' => [
				'dir' => 'older',
				'startId' => 2,
				'endId' => null,
				'expectedIds' => [ 2, 1 ],
			],
			'older, endid only' => [
				'dir' => 'older',
				'startId' => null,
				'endId' => 2,
				'expectedIds' => [ 4, 3, 2 ],
			],
			'older, startid and endid' => [
				'dir' => 'older',
				'startId' => 3,
				'endId' => 1,
				'expectedIds' => [ 3, 2, 1 ],
			],
			// TODO: This should not return an empty array (T435833)
			'older, startid less than endid' => [
				'dir' => 'older',
				'startId' => 1,
				'endId' => 4,
				'expectedIds' => [],
			],
		];
	}

	/**
	 * @dataProvider provideExecuteWithConflictingShowParameters
	 */
	public function testExecuteWithConflictingShowParameters( string $show ): void {
		$this->expectApiErrorCode( 'show' );
		$this->doQuery( self::$authorityCanViewAll, [
			'abfprop' => '',
			'abfshow' => $show,
		] );
	}

	public static function provideExecuteWithConflictingShowParameters(): array {
		return [
			'enabled and !enabled' => [ 'enabled|!enabled' ],
			'deleted and !deleted' => [ 'deleted|!deleted' ],
			'private and !private' => [ 'private|!private' ],
			'protected and !protected' => [ 'protected|!protected' ],
			'suppressed and !suppressed' => [ 'suppressed|!suppressed' ],
		];
	}

	/**
	 * @dataProvider provideExecuteWithShowParameters
	 */
	public function testExecuteWithShowParameters( string $show, array $expectedIds ): void {
		[ $filters ] = $this->doQuery( self::$authorityCanViewAll, [
			'abfprop' => 'id',
			'abfshow' => $show,
		] );

		$this->assertSame( $expectedIds, array_column( $filters, 'id' ) );
	}

	public static function provideExecuteWithShowParameters(): array {
		return [
			'request enabled filters' => [
				'show' => 'enabled',
				'expectedIds' => [ 1, 2, 4 ],
			],
			'request non-enabled filters' => [
				'show' => '!enabled',
				'expectedIds' => [ 3 ],
			],
			'request deleted filters' => [
				'show' => 'deleted',
				'expectedIds' => [ 3 ],
			],
			'request non-deleted filters' => [
				'show' => '!deleted',
				'expectedIds' => [ 1, 2, 4 ],
			],
			'request private filters' => [
				'show' => 'private',
				'expectedIds' => [ 3 ],
			],
			'request non-private filters' => [
				'show' => '!private',
				'expectedIds' => [ 1, 2, 4 ],
			],
			'request protected filters' => [
				'show' => 'protected',
				'expectedIds' => [ 1 ],
			],
			'request non-protected filters' => [
				'show' => '!protected',
				'expectedIds' => [ 2, 3, 4 ],
			],
			'request suppressed filters' => [
				'show' => 'suppressed',
				'expectedIds' => [ 4 ],
			],
			'request non-suppressed filters' => [
				'show' => '!suppressed',
				'expectedIds' => [ 1, 2, 3 ],
			],
		];
	}

	public function testExecuteWithLimit(): void {
		[ $filters, $result ] = $this->doQuery( self::$authorityCanViewAll, [
			'abfprop' => 'id',
			'abflimit' => 1,
		] );

		$this->assertArrayContains(
			[ 'continue' => [ 'abfstartid' => 2 ] ],
			$result
		);
		$this->assertCount( 1, $filters );
	}

	public function testExecuteReturnsBasicProperties(): void {
		[ $filters ] = $this->doQuery( self::$authorityCanViewAll );

		$propMap = [
			1 => [
				'suppressed' => false,
				'private' => false,
				'protected' => true,
				'enabled' => true,
				'deleted' => false,
			],
			2 => [
				'suppressed' => false,
				'private' => false,
				'protected' => false,
				'enabled' => true,
				'deleted' => false,
			],
			3 => [
				'suppressed' => false,
				'private' => true,
				'protected' => false,
				'enabled' => false,
				'deleted' => true,
			],
			4 => [
				'suppressed' => true,
				'private' => false,
				'protected' => false,
				'enabled' => true,
				'deleted' => false,
			],
		];

		foreach ( $filters as $index => $filter ) {
			$id = $index + 1;

			$this->assertSame( $id, $filter['id'] );
			$this->assertIsString( $filter['description'] );
			$this->assertIsString( $filter['actions'] );
			$this->assertIsString( $filter['lasteditor'] );
			$this->assertIsString( $filter['lastedittime'] );

			foreach ( [ 'suppressed', 'private', 'protected', 'enabled', 'deleted' ] as $prop ) {
				$expected = $propMap[$id][$prop];
				$this->assertSame(
					$expected,
					array_key_exists( $prop, $filter ),
					$expected
						? "Filter $id is $prop, but the object does not contain the key"
						: "Filter $id is not $prop, but the object contains the key"
				);
			}
		}
	}

	/**
	 * @dataProvider provideExecuteRedactsPatternBasedOnAuthority
	 */
	public function testExecuteRedactsPatternBasedOnAuthority(
		callable $getAuthority,
		array $expectedViewableIds
	): void {
		[ $filters ] = $this->doQuery( $getAuthority(), [ 'abfprop' => 'id|pattern' ] );

		foreach ( $filters as $index => $filter ) {
			$id = $index + 1;

			$this->assertSame( $id, $filter['id'] );

			if ( in_array( $id, $expectedViewableIds, true ) ) {
				$this->assertArrayHasKey( 'pattern', $filter );
				$this->assertArrayNotHasKey( 'patternredacted', $filter );
			} else {
				$this->assertArrayNotHasKey( 'pattern', $filter );
				$this->assertArrayHasKey( 'patternredacted', $filter );
			}
		}
	}

	public static function provideExecuteRedactsPatternBasedOnAuthority(): array {
		return [
			'performer can view public filters' => [
				'getAuthority' => static fn () => self::$authorityCanViewPublic,
				'expectedViewableIds' => [ 2 ],
			],
			'performer can view private filters' => [
				'getAuthority' => static fn () => self::$authorityCanViewPrivate,
				'expectedViewableIds' => [ 2, 3 ],
			],
			'performer can view protected filters' => [
				'getAuthority' => static fn () => self::$authorityCanViewProtected,
				'expectedViewableIds' => [ 1, 2 ],
			],
			'performer can view suppressed filters' => [
				'getAuthority' => static fn () => self::$authorityCanViewSuppressed,
				'expectedViewableIds' => [ 2, 4 ],
			],
		];
	}

	/**
	 * @dataProvider provideExecuteRedactsPatternBasedOnAuthority
	 */
	public function testExecuteRedactsCommentsBasedOnAuthority(
		callable $getAuthority,
		array $expectedViewableIds
	): void {
		[ $filters ] = $this->doQuery( $getAuthority(), [ 'abfprop' => 'id|comments' ] );

		foreach ( $filters as $index => $filter ) {
			$id = $index + 1;

			$this->assertSame( $id, $filter['id'] );

			if ( in_array( $id, $expectedViewableIds, true ) ) {
				$this->assertArrayHasKey( 'comments', $filter );
				$this->assertArrayNotHasKey( 'commentsredacted', $filter );
			} else {
				$this->assertArrayNotHasKey( 'comments', $filter );
				$this->assertArrayHasKey( 'commentsredacted', $filter );
			}
		}
	}

	/**
	 * @dataProvider provideExecuteRedactsHitsBasedOnAuthority
	 */
	public function testExecuteRedactsHitsBasedOnAuthority(
		callable $getAuthority,
		array $expectedHitCounts
	): void {
		[ $filters ] = $this->doQuery( $getAuthority(), [ 'abfprop' => 'id|hits' ] );

		foreach ( $filters as $index => $filter ) {
			$id = $index + 1;

			$this->assertSame( $id, $filter['id'] );

			if ( array_key_exists( $id, $expectedHitCounts ) ) {
				$this->assertSame( $expectedHitCounts[$id], $filter['hits'] );
				$this->assertArrayNotHasKey( 'hitsredacted', $filter );
			} else {
				$this->assertArrayNotHasKey( 'hits', $filter );
				$this->assertArrayHasKey( 'hitsredacted', $filter );
			}
		}
	}

	public static function provideExecuteRedactsHitsBasedOnAuthority(): array {
		return [
			// Hit counts cannot be viewed without abusefilter-log-detail
			'performer can view public filters' => [
				'getAuthority' => static fn () => self::$authorityCanViewPublic,
				'expectedHitCounts' => [],
			],
			'performer can view private filters' => [
				'getAuthority' => static fn () => self::$authorityCanViewPrivate,
				'expectedHitCounts' => [
					2 => 0,
					3 => 42,
				],
			],
			'performer can view protected filters' => [
				'getAuthority' => static fn () => self::$authorityCanViewProtected,
				'expectedHitCounts' => [
					1 => 1,
					2 => 0,
				],
			],
			'performer can view suppressed filters' => [
				'getAuthority' => static fn () => self::$authorityCanViewSuppressed,
				'expectedHitCounts' => [
					2 => 0,
					4 => 9,
				],
			],
		];
	}
}
