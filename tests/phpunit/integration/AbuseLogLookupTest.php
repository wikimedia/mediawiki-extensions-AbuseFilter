<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\AbuseLogLookup;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AbuseFilter\AbuseLogLookup
 */
class AbuseLogLookupTest extends MediaWikiIntegrationTestCase {

	use MockAuthorityTrait;
	use FilterFromSpecsTestTrait;

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

	/**
	 * Creates the AbuseFilter hit fixture used by the count tests: one user with three hits
	 * (one of which is suppressed), one user with a single hit, and one user with no hits.
	 *
	 * @return UserIdentity[] The three users, in that order.
	 */
	private function createAbuseFilterHitData(): array {
		$userWithHits = $this->getMutableTestUser()->getUser();
		$userWithOneHit = $this->getMutableTestUser()->getUser();
		$userWithoutHits = $this->getMutableTestUser()->getUser();

		$status = AbuseFilterServices::getFilterStore()->saveFilter(
			$this->getTestSysop()->getUser(),
			null,
			$this->getFilterFromSpecs( [
				'id' => '1',
				'name' => 'Test filter',
				'rules' => 'old_wikitext = "abc"',
			] ),
			MutableFilter::newDefault()
		);
		$this->assertStatusGood( $status );

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
	 * Records $count AbuseFilter hits against $user and returns the created afl_id values.
	 *
	 * @param User $user User to record the hits against.
	 * @param Title $title Page the hits are recorded on.
	 * @param int $count Number of hits to record.
	 * @return int[]
	 */
	private function addAbuseFilterHits( User $user, Title $title, int $count ): array {
		$logger = AbuseFilterServices::getAbuseLoggerFactory()->newLogger(
			$title,
			$user,
			VariableHolder::newFromArray( [
				'action' => 'edit',
				'user_name' => $user->getName(),
				'old_wikitext' => 'abc',
			] )
		);

		$ids = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$result = $logger->addLogEntries( [ 1 => [ 'warn' ] ] );
			$ids[] = $result['local'][0];
		}
		return $ids;
	}
}
