<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Special;

use Generator;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseLog;
use MediaWiki\Extension\AbuseFilter\Tests\Integration\FilterFromSpecsTestTrait;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Permissions\SimpleAuthority;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;
use stdClass;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseLog
 * @group Database
 */
class SpecialAbuseLogTest extends MediaWikiIntegrationTestCase {
	use FilterFromSpecsTestTrait;
	use MockAuthorityTrait;

	private static UserIdentity $logPerformer;

	/**
	 * @param stdClass $row
	 * @param RevisionRecord $revRec
	 * @param bool $canSeeHidden
	 * @param bool $canSeeSuppressed
	 * @param string $expected
	 * @dataProvider provideEntryAndVisibility
	 */
	public function testGetEntryVisibilityForUser(
		stdClass $row,
		RevisionRecord $revRec,
		bool $canSeeHidden,
		bool $canSeeSuppressed,
		string $expected
	) {
		$user = $this->createMock( UserIdentity::class );
		$authority = new SimpleAuthority( $user, $canSeeSuppressed ? [ 'viewsuppressed' ] : [] );
		$afPermManager = $this->createMock( AbuseFilterPermissionManager::class );
		$afPermManager->method( 'canSeeHiddenLogEntries' )->with( $authority )->willReturn( $canSeeHidden );
		$revLookup = $this->createMock( RevisionLookup::class );
		$revLookup->method( 'getRevisionById' )->willReturn( $revRec );
		$this->setService( 'RevisionLookup', $revLookup );
		$this->assertSame(
			$expected,
			SpecialAbuseLog::getEntryVisibilityForUser( $row, $authority, $afPermManager )
		);
	}

	public static function provideEntryAndVisibility(): Generator {
		$visibleRow = (object)[ 'afl_rev_id' => 1, 'afl_deleted' => 0 ];
		$hiddenRow = (object)[ 'afl_rev_id' => 1, 'afl_deleted' => 1 ];
		$page = new PageIdentityValue( 1, NS_MAIN, 'Foo', PageIdentityValue::LOCAL );
		$visibleRev = new MutableRevisionRecord( $page );

		yield 'Visible entry and rev, cannot see hidden, cannot see suppressed' =>
			[ $visibleRow, $visibleRev, false, false, SpecialAbuseLog::VISIBILITY_VISIBLE ];
		yield 'Visible entry and rev, can see hidden, cannot see suppressed' =>
			[ $visibleRow, $visibleRev, true, false, SpecialAbuseLog::VISIBILITY_VISIBLE ];
		yield 'Visible entry and rev, cannot see hidden, can see suppressed' =>
			[ $visibleRow, $visibleRev, false, false, SpecialAbuseLog::VISIBILITY_VISIBLE ];
		yield 'Visible entry and rev, can see hidden, can see suppressed' =>
			[ $visibleRow, $visibleRev, true, false, SpecialAbuseLog::VISIBILITY_VISIBLE ];

		yield 'Hidden entry, visible rev, can see hidden, cannot see suppressed' =>
			[ $hiddenRow, $visibleRev, true, false, SpecialAbuseLog::VISIBILITY_VISIBLE ];
		yield 'Hidden entry, visible rev, cannot see hidden, cannot see suppressed' =>
			[ $hiddenRow, $visibleRev, false, false, SpecialAbuseLog::VISIBILITY_HIDDEN ];
		yield 'Hidden entry, visible rev, can see hidden, can see suppressed' =>
			[ $hiddenRow, $visibleRev, true, true, SpecialAbuseLog::VISIBILITY_VISIBLE ];
		yield 'Hidden entry, visible rev, cannot see hidden, can see suppressed' =>
			[ $hiddenRow, $visibleRev, false, true, SpecialAbuseLog::VISIBILITY_HIDDEN ];

		$userSupRev = new MutableRevisionRecord( $page );
		$userSupRev->setVisibility( RevisionRecord::SUPPRESSED_USER );
		yield 'Hidden entry, user suppressed rev, can see hidden, cannot see suppressed' =>
			[ $hiddenRow, $userSupRev, true, false, SpecialAbuseLog::VISIBILITY_HIDDEN_IMPLICIT ];
		yield 'Hidden entry, user suppressed rev, cannot see hidden, cannot see suppressed' =>
			[ $hiddenRow, $userSupRev, false, false, SpecialAbuseLog::VISIBILITY_HIDDEN ];
		yield 'Hidden entry, user suppressed rev, can see hidden, can see suppressed' =>
			[ $hiddenRow, $userSupRev, true, true, SpecialAbuseLog::VISIBILITY_VISIBLE ];
		yield 'Hidden entry, user suppressed rev, cannot see hidden, can see suppressed' =>
			[ $hiddenRow, $userSupRev, false, true, SpecialAbuseLog::VISIBILITY_HIDDEN ];

		$allSuppRev = new MutableRevisionRecord( $page );
		$allSuppRev->setVisibility( RevisionRecord::SUPPRESSED_ALL );
		yield 'Hidden entry, all suppressed rev, can see hidden, cannot see suppressed' =>
			[ $hiddenRow, $allSuppRev, true, false, SpecialAbuseLog::VISIBILITY_HIDDEN_IMPLICIT ];
		yield 'Hidden entry, all suppressed rev, cannot see hidden, cannot see suppressed' =>
			[ $hiddenRow, $allSuppRev, false, false, SpecialAbuseLog::VISIBILITY_HIDDEN ];
		yield 'Hidden entry, all suppressed rev, can see hidden, can see suppressed' =>
			[ $hiddenRow, $allSuppRev, true, true, SpecialAbuseLog::VISIBILITY_VISIBLE ];
		yield 'Hidden entry, all suppressed rev, cannot see hidden, can see suppressed' =>
			[ $hiddenRow, $allSuppRev, false, true, SpecialAbuseLog::VISIBILITY_HIDDEN ];
	}

	/** @dataProvider provideGetPrivateDetailsRowForFatalStatus */
	public function testGetPrivateDetailsRowForFatalStatus( $id, $authorityHasRights, $expectedErrorMessage ) {
		if ( $authorityHasRights ) {
			$authority = $this->mockRegisteredUltimateAuthority();
		} else {
			$authority = $this->mockRegisteredNullAuthority();
		}
		$this->assertStatusError(
			$expectedErrorMessage,
			SpecialAbuseLog::getPrivateDetailsRow( $authority, $id )
		);
	}

	public static function provideGetPrivateDetailsRowForFatalStatus() {
		return [
			'Filter ID does not exist' => [ 1234, true, 'abusefilter-log-nonexistent' ],
			'Authority lacks rights' => [ 1, false, 'abusefilter-log-cannot-see-details' ],
		];
	}

	public function testGetPrivateDetailsRow() {
		$actualStatus = SpecialAbuseLog::getPrivateDetailsRow( $this->mockRegisteredUltimateAuthority(), 1 );
		$this->assertStatusGood( $actualStatus );
		$this->assertStatusValue(
			(object)[
				'afl_id' => '1',
				'afl_user_text' => self::$logPerformer->getName(),
				'afl_filter_id' => '1',
				'afl_global' => '0',
				'afl_timestamp' => '20240506070809',
				'afl_ip' => '1.2.3.4',
				'af_id' => '1',
				'af_public_comments' => 'Filter with protected variables',
				'af_hidden' => Flags::FILTER_USES_PROTECTED_VARS,
			],
			$actualStatus
		);
	}

	public function addDBDataOnce() {
		ConvertibleTimestamp::setFakeTime( '20240506070809' );
		// Get a testing filter
		$performer = $this->getTestSysop()->getUser();
		$this->assertStatusGood( AbuseFilterServices::getFilterStore()->saveFilter(
			$performer, null,
			$this->getFilterFromSpecs( [
				'id' => '1',
				'name' => 'Filter with protected variables',
				'privacy' => Flags::FILTER_USES_PROTECTED_VARS,
			] ),
			MutableFilter::newDefault()
		) );

		// Insert a hit on the filter
		RequestContext::getMain()->getRequest()->setIP( '1.2.3.4' );
		$logPerformer = $this->getTestUser();
		self::$logPerformer = $logPerformer->getUserIdentity();
		$abuseFilterLoggerFactory = AbuseFilterServices::getAbuseLoggerFactory();
		$abuseFilterLoggerFactory->newLogger(
			$this->getExistingTestPage()->getTitle(),
			$logPerformer->getUser(),
			VariableHolder::newFromArray( [
				'action' => 'edit',
				'user_name' => 'User1',
			] )
		)->addLogEntries( [ 1 => [ 'warn' ] ] );
	}
}
