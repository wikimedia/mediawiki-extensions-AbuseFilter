<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Api;

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Extension\AbuseFilter\Tests\Integration\FilterFromSpecsTestTrait;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\ActorStore;
use MediaWiki\User\Options\StaticUserOptionsLookup;
use MediaWiki\User\UserIdentityValue;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Api\QueryAbuseLog
 * @group medium
 * @group Database
 * @todo Extend this
 */
class QueryAbuseLogTest extends ApiTestCase {
	use MockAuthorityTrait;
	use FilterFromSpecsTestTrait;

	public function testConstruct() {
		$this->doApiRequest( [
			'action' => 'query',
			'list' => 'abuselog',
		] );
		$this->addToAssertionCount( 1 );
	}

	public function testFilteringForProtectedFilterWhenUserLacksAccess() {
		$this->expectApiErrorCode( 'permissiondenied' );
		$this->doApiRequest( [
			'action' => 'query',
			'list' => 'abuselog',
			'aflprop' => 'details',
			'afldir' => 'older',
			'aflfilter' => 1,
		] );
	}

	public function testFilteringForProtectedFilterWhenUserLacksAccessToValues() {
		$this->setService(
			'UserOptionsLookup',
			new StaticUserOptionsLookup( [], [ 'abusefilter-protected-vars-view-agreement' => 0 ] )
		);
		$this->expectApiErrorCode( 'permissiondenied' );
		$this->doApiRequest(
			[
				'action' => 'query',
				'list' => 'abuselog',
				'aflprop' => 'details',
				'afldir' => 'older',
				'aflfilter' => 1,
			],
			null, false,
			$this->mockRegisteredAuthorityWithPermissions( [
				'abusefilter-log-detail',
				'abusefilter-view',
				'abusefilter-log',
				'abusefilter-privatedetails',
				'abusefilter-privatedetails-log',
				'abusefilter-view-private',
				'abusefilter-log-private',
				'abusefilter-hidden-log',
				'abusefilter-hide-log',
				'abusefilter-access-protected-vars'
			] )
		);
	}

	public function testProtectedVariableValueAccess() {
		// Create the user to query for filters
		$user = new UserIdentityValue( 1, 'User1' );

		// Create an authority who can see protected variables but hasn't checked the preference
		$authorityCanViewProtectedVar = $this->mockUserAuthorityWithPermissions(
			$user,
			[
				'abusefilter-log-detail',
				'abusefilter-view',
				'abusefilter-log',
				'abusefilter-privatedetails',
				'abusefilter-privatedetails-log',
				'abusefilter-view-private',
				'abusefilter-log-private',
				'abusefilter-hidden-log',
				'abusefilter-hide-log',
				'abusefilter-access-protected-vars'
			]
		);

		// Assert that the ip isn't visible in the result
		$result = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'abuselog',
			'aflprop' => 'details',
			'afldir' => 'older',
		], null, null, $authorityCanViewProtectedVar );
		$result = $result[0]['query']['abuselog'];
		$this->assertNotCount( 0, $result, 'abuselog API response should not be empty' );
		foreach ( $result as $row ) {
			$this->assertSame( '', $row['details']['user_unnamed_ip'], 'IP is redacted' );
		}

		// Enable the preference for the user
		$userOptions = new StaticUserOptionsLookup(
			[
				'User1' => [
					'abusefilter-protected-vars-view-agreement' => 1
				]
			]
		);
		$this->setService( 'UserOptionsLookup', $userOptions );

		// Actor store needs to return a valid actor_id for the logs querying generates
		$actorStore = $this->createMock( ActorStore::class );
		$actorStore->method( 'acquireActorId' )->willReturn( 1 );
		$this->setService( 'ActorStore', $actorStore );

		// Assert that the ip is now visible
		$result = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'abuselog',
			'aflprop' => 'details',
			'afldir' => 'older',
		], null, null, $authorityCanViewProtectedVar );
		$result = $result[0]['query']['abuselog'];
		foreach ( $result as $row ) {
			$this->assertSame( '1.2.3.4', $row['details']['user_unnamed_ip'] );
			if ( isset( $row['details']['accountname'] ) ) {
				$this->assertSame( 'User1', $row['details']['accountname'] );
				$this->assertArrayNotHasKey( 'user_name', $row['details'] );
			} else {
				$this->assertSame( 'User1', $row['details']['user_name'] );
				$this->assertArrayNotHasKey( 'accountname', $row['details'] );
			}
		}
	}

	public function addDBDataOnce() {
		// Add filter to query for
		$performer = $this->getTestSysop()->getUser();
		$this->assertStatusGood( AbuseFilterServices::getFilterStore()->saveFilter(
			$performer, null,
			$this->getFilterFromSpecs( [
				'id' => '1',
				'rules' => 'user_unnamed_ip = "1.2.3.4"',
				'name' => 'Filter with protected variables',
				'privacy' => Flags::FILTER_USES_PROTECTED_VARS,
				'lastEditor' => $performer,
				'lastEditTimestamp' => $this->getDb()->timestamp( '20190826000000' ),
			] ),
			MutableFilter::newDefault()
		) );

		// Insert a hit on the filter
		$abuseFilterLoggerFactory = AbuseFilterServices::getAbuseLoggerFactory();
		$abuseFilterLoggerFactory->newLogger(
			$this->getExistingTestPage()->getTitle(),
			$this->getTestUser()->getUser(),
			VariableHolder::newFromArray( [
				'action' => 'edit',
				'user_unnamed_ip' => '1.2.3.4',
				'user_name' => 'User1',
			] )
		)->addLogEntries( [ 1 => [ 'warn' ] ] );
		$abuseFilterLoggerFactory->newLogger(
			$this->getExistingTestPage()->getTitle(),
			$this->getTestUser()->getUser(),
			VariableHolder::newFromArray( [
				'action' => 'autocreateaccount',
				'user_unnamed_ip' => '1.2.3.4',
				'accountname' => 'User1',
			] )
		)->addLogEntries( [ 1 => [ 'warn' ] ] );

		// Update afl_ip to a known value that can be used when it's reconstructed in the variable holder
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'abuse_filter_log' )
			->set( [ 'afl_ip' => '1.2.3.4' ] )
			->where( [ 'afl_filter_id' => 1 ] )
			->caller( __METHOD__ )->execute();
	}
}
