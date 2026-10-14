<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\AbuseLogLookup;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AbuseFilter\AbuseLogLookup
 */
class AbuseLogLookupTest extends MediaWikiIntegrationTestCase {

	use MockAuthorityTrait;
	use FilterFromSpecsTestTrait;

	private int $publicFilterId;

	private function getSubjectUnderTest(): AbuseLogLookup {
		return $this->getServiceContainer()->getService( AbuseLogLookup::SERVICE_NAME );
	}

	public function testGetHitCountsExcludesSuppressedEntriesWithoutPermission(): void {
		[ $userWithHits, $userWithOneHit, $userWithoutHits ] = $this->createAbuseFilterHitData();

		$authority = $this->mockRegisteredAuthorityWithPermissions( [ 'abusefilter-log' ] );
		$counts = $this->getSubjectUnderTest()->getHitCountsForUsers(
			$authority,
			[ $userWithHits, $userWithOneHit, $userWithoutHits ]
		);

		$this->assertSame(
			[
				$userWithHits->getName() => 2,
				$userWithOneHit->getName() => 1,
				$userWithoutHits->getName() => 0,
			],
			$counts
		);
	}

	public function testGetHitCountsIncludesSuppressedEntriesWithPermission(): void {
		[ $userWithHits ] = $this->createAbuseFilterHitData();

		$authority = $this->mockRegisteredAuthorityWithPermissions(
			[ 'abusefilter-log', 'abusefilter-hidden-log' ]
		);
		$counts = $this->getSubjectUnderTest()->getHitCountsForUsers( $authority, [ $userWithHits ] );

		$this->assertSame( [ $userWithHits->getName() => 3 ], $counts );
	}

	public function testGetHitCountForUserWithoutHits(): void {
		$authority = $this->mockRegisteredAuthorityWithPermissions( [ 'abusefilter-log' ] );
		$user = $this->getMutableTestUser()->getUserIdentity();
		$this->assertSame(
			[ $user->getName() => 0 ],
			$this->getSubjectUnderTest()->getHitCountsForUsers( $authority, [ $user ] )
		);
	}

	public function testReturnsZeroCountsWithoutViewAbuseLogPermission(): void {
		$user = $this->getMutableTestUser()->getUserIdentity();
		$authority = $this->mockRegisteredAuthorityWithoutPermissions( [ 'abusefilter-log' ] );
		$sut = $this->getSubjectUnderTest();
		$this->assertSame( [], $sut->getHitCountsForUsers( $authority, [ $user ] ) );
	}

	public function testGetHitCountsExcludesSuppressedFiltersWithoutPermission(): void {
		[ $userWithHits ] = $this->createAbuseFilterHitData();
		$title = $this->getExistingTestPage( 'AbuseFilterHitsCounterTest' )->getTitle();
		$suppressedFilterId = $this->createFilter( 'Suppressed filter', Flags::FILTER_SUPPRESSED );
		$this->addAbuseFilterHits( $userWithHits, $title, 2, $suppressedFilterId );

		$sut = $this->getSubjectUnderTest();
		$this->assertSame(
			[ $userWithHits->getName() => 2 ],
			$sut->getHitCountsForUsers(
				$this->mockRegisteredAuthorityWithPermissions( [ 'abusefilter-log' ] ),
				[ $userWithHits ]
			)
		);
		$this->assertSame(
			[ $userWithHits->getName() => 4 ],
			$sut->getHitCountsForUsers(
				$this->mockRegisteredAuthorityWithPermissions( [ 'abusefilter-log', 'viewsuppressed' ] ),
				[ $userWithHits ]
			)
		);
	}

	public function testGetHitCountsExcludesSuppressedGlobalFilters(): void {
		$this->overrideConfigValue( 'AbuseFilterCentralDB', $this->getDb()->getDomainID() );
		[ $userWithHits ] = $this->createAbuseFilterHitData();
		$title = $this->getExistingTestPage( 'AbuseFilterHitsCounterTest' )->getTitle();
		$suppressedFilterId = $this->createFilter( 'Suppressed filter', Flags::FILTER_SUPPRESSED );
		$publicGlobalHits = $this->addAbuseFilterHits( $userWithHits, $title, 1 );
		$suppressedGlobalHits = $this->addAbuseFilterHits( $userWithHits, $title, 1, $suppressedFilterId );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_filter_log' )
			->set( [ 'afl_global' => 1 ] )
			->where( [ 'afl_id' => array_merge( $publicGlobalHits, $suppressedGlobalHits ) ] )
			->caller( __METHOD__ )
			->execute();

		$authority = $this->mockRegisteredAuthorityWithPermissions( [ 'abusefilter-log' ] );
		$this->assertSame(
			[ $userWithHits->getName() => 3 ],
			$this->getSubjectUnderTest()->getHitCountsForUsers( $authority, [ $userWithHits ] )
		);
	}

	public function testGetHitCountsExcludesGlobalFiltersWithoutCentralDB(): void {
		$this->overrideConfigValue( 'AbuseFilterCentralDB', null );
		[ $userWithHits ] = $this->createAbuseFilterHitData();
		$title = $this->getExistingTestPage( 'AbuseFilterHitsCounterTest' )->getTitle();
		$globalHits = $this->addAbuseFilterHits( $userWithHits, $title, 1 );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_filter_log' )
			->set( [ 'afl_global' => 1 ] )
			->where( [ 'afl_id' => $globalHits ] )
			->caller( __METHOD__ )
			->execute();

		$authority = $this->mockRegisteredAuthorityWithPermissions( [ 'abusefilter-log' ] );
		$this->assertSame(
			[ $userWithHits->getName() => 2 ],
			$this->getSubjectUnderTest()->getHitCountsForUsers( $authority, [ $userWithHits ] )
		);
	}

	/**
	 * @dataProvider provideRevisionVisibility
	 */
	public function testGetHitCountsForDeletedRevisions(
		int $revDeleted, array $permissions, int $expectedCount
	): void {
		[ $userWithHits ] = $this->createAbuseFilterHitData();
		$title = $this->getExistingTestPage( 'AbuseFilterHitsCounterTest' )->getTitle();
		$revId = $this->editPage( $title, 'Test content', '', NS_MAIN, $userWithHits )
			->getNewRevision()->getId();
		$hitIds = $this->addAbuseFilterHits( $userWithHits, $title, 1 );

		$dbw = $this->getDb();
		$dbw->newUpdateQueryBuilder()
			->update( 'abuse_filter_log' )
			->set( [ 'afl_rev_id' => $revId ] )
			->where( [ 'afl_id' => $hitIds ] )
			->caller( __METHOD__ )
			->execute();
		$dbw->newUpdateQueryBuilder()
			->update( 'revision' )
			->set( [ 'rev_deleted' => $revDeleted ] )
			->where( [ 'rev_id' => $revId ] )
			->caller( __METHOD__ )
			->execute();

		$authority = $this->mockRegisteredAuthorityWithPermissions(
			array_merge( [ 'abusefilter-log' ], $permissions )
		);
		$this->assertSame(
			[ $userWithHits->getName() => $expectedCount ],
			$this->getSubjectUnderTest()->getHitCountsForUsers( $authority, [ $userWithHits ] )
		);
	}

	public static function provideRevisionVisibility(): array {
		$suppressedText = RevisionRecord::DELETED_TEXT | RevisionRecord::DELETED_RESTRICTED;
		return [
			'Visible revision' => [ 0, [], 3 ],
			'Deleted text, no rights' => [ RevisionRecord::DELETED_TEXT, [], 2 ],
			'Deleted text, deletedtext' => [ RevisionRecord::DELETED_TEXT, [ 'deletedtext' ], 3 ],
			'Deleted text, deletedhistory' => [ RevisionRecord::DELETED_TEXT, [ 'deletedhistory' ], 2 ],
			'Deleted comment, no rights' => [ RevisionRecord::DELETED_COMMENT, [], 2 ],
			'Deleted user, deletedtext' => [ RevisionRecord::DELETED_USER, [ 'deletedtext' ], 2 ],
			'Deleted comment, deletedhistory' => [ RevisionRecord::DELETED_COMMENT, [ 'deletedhistory' ], 3 ],
			'Suppressed text, deletedtext' => [ $suppressedText, [ 'deletedtext' ], 2 ],
			'Suppressed text, viewsuppressed' => [ $suppressedText, [ 'viewsuppressed' ], 3 ],
			'Suppressed text, suppressrevision' => [ $suppressedText, [ 'suppressrevision' ], 3 ],
		];
	}

	/**
	 * Creates the AbuseFilter hit fixture used by the count tests: one user with three hits
	 * (one of which is hidden/afl_deleted), one user with a single hit, and one user with no hits.
	 *
	 * @return User[] The three users, in that order.
	 */
	private function createAbuseFilterHitData(): array {
		$userWithHits = $this->getMutableTestUser()->getUser();
		$userWithOneHit = $this->getMutableTestUser()->getUser();
		$userWithoutHits = $this->getMutableTestUser()->getUser();

		$this->publicFilterId = $this->createFilter( 'Test filter', Flags::FILTER_PUBLIC );

		$title = $this->getExistingTestPage( 'AbuseFilterHitsCounterTest' )->getTitle();

		$hitIds = $this->addAbuseFilterHits( $userWithHits, $title, 3 );
		$this->addAbuseFilterHits( $userWithOneHit, $title, 1 );

		// Mark one of the first user's entries as suppressed (afl_deleted).
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_filter_log' )
			->set( [ 'afl_deleted' => 1 ] )
			->where( [ 'afl_id' => $hitIds[0] ] )
			->caller( __METHOD__ )
			->execute();

		return [ $userWithHits, $userWithOneHit, $userWithoutHits ];
	}

	/**
	 * Creates a local filter and returns its ID.
	 *
	 * @param string $name
	 * @param int $privacy One of the Flags::FILTER_* constants
	 * @return int
	 */
	private function createFilter( string $name, int $privacy ): int {
		$status = AbuseFilterServices::getFilterStore()->saveFilter(
			$this->getTestUser( [ 'sysop', 'suppress' ] )->getUser(),
			null,
			$this->getFilterFromSpecs( [
				'id' => null,
				'name' => $name,
				'rules' => 'old_wikitext = "abc"',
				'privacy' => $privacy,
			] ),
			MutableFilter::newDefault()
		);
		$this->assertStatusGood( $status );
		return $status->getValue()[0];
	}

	/**
	 * Records $count AbuseFilter hits against $user and returns the created afl_id values.
	 *
	 * @param User $user User to record the hits against.
	 * @param Title $title Page the hits are recorded on.
	 * @param int $count Number of hits to record.
	 * @param int|null $filterId Filter the hits are recorded for. Null for the public test filter.
	 * @return int[]
	 */
	private function addAbuseFilterHits( User $user, Title $title, int $count, ?int $filterId = null ): array {
		$filterId ??= $this->publicFilterId;
		$logger = AbuseFilterServices::getAbuseLoggerFactory()->newLogger(
			$title,
			$user,
			VariableHolder::newFromArray( [
				'action' => 'edit',
				'user_name' => $user->getName(),
				'old_wikitext' => 'abc',
			] )
		);

		$lastId = (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'MAX(afl_id)' )
			->from( 'abuse_filter_log' )
			->caller( __METHOD__ )
			->fetchField();
		for ( $i = 0; $i < $count; $i++ ) {
			$logger->addLogEntries( [ $filterId => [ 'warn' ] ] );
		}
		return array_map( 'intval', $this->getDb()->newSelectQueryBuilder()
			->select( 'afl_id' )
			->from( 'abuse_filter_log' )
			->where( $this->getDb()->expr( 'afl_id', '>', $lastId ) )
			->caller( __METHOD__ )
			->fetchFieldValues() );
	}
}
