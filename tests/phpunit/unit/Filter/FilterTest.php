<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Filter;

use MediaWiki\Extension\AbuseFilter\Filter\Filter;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Filter\LastEditInfo;
use MediaWiki\Extension\AbuseFilter\Filter\Specs;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @group AbuseFilter
 * @covers \MediaWiki\Extension\AbuseFilter\Filter\Filter
 */
class FilterTest extends MediaWikiUnitTestCase {
	public function testValueGetters() {
		$userID = 42;
		$userName = 'Admin';
		$timestamp = '123';
		$id = 163;
		$hitCount = 1000;
		$throttled = false;
		$filter = new Filter(
			$this->createMock( Specs::class ),
			$this->createMock( Flags::class ),
			[],
			new LastEditInfo( $userID, $userName, $timestamp ),
			$id,
			$hitCount,
			$throttled
		);
		$userIdentity = $filter->getUserIdentity();

		$this->assertSame( $userID, $userIdentity->getId() );
		$this->assertSame( $userName, $userIdentity->getName() );
		$this->assertTrue( $userIdentity->isRegistered() );
		$this->assertSame( $userID, $filter->getUserID(), 'user ID' );
		$this->assertSame( $userName, $filter->getUserName(), 'username' );
		$this->assertSame( $timestamp, $filter->getTimestamp(), 'timestamp' );
		$this->assertSame( $id, $filter->getID(), 'ID' );
		$this->assertSame( $hitCount, $filter->getHitCount(), 'hit count' );
		$this->assertSame( $throttled, $filter->isThrottled(), 'throttled' );
	}

	public function getUserIdentityForAnon() {
		$userName = 'Anon user';

		$filter = new Filter(
			$this->createMock( Specs::class ),
			$this->createMock( Flags::class ),
			[],
			new LastEditInfo( 0, $userName, 123 ),
			164,
			1000,
			false
		);

		$identity = $filter->getUserIdentity();
		$this->assertSame( $userName, $identity->getId() );
		$this->assertSame( 0, $identity->getId() );
		$this->assertNotTrue( $identity->isRegistered() );
	}

	public function testGetObjects() {
		$specs = $this->createMock( Specs::class );
		$flags = $this->createMock( Flags::class );
		$lastEditInfo = $this->createMock( LastEditInfo::class );
		$filter = new Filter( $specs, $flags, [], $lastEditInfo );

		$this->assertEquals( $lastEditInfo, $filter->getLastEditInfo(), 'equal' );
		$this->assertNotSame( $lastEditInfo, $filter->getLastEditInfo(), 'not identical' );
	}

	public function testNoWriteableReferences() {
		$oldUsername = 'User1';
		$lastEditInfo = new LastEditInfo( 1, $oldUsername, '123' );
		$filter = new Filter(
			$this->createMock( Specs::class ),
			$this->createMock( Flags::class ),
			[],
			$lastEditInfo
		);
		$copy = clone $filter;

		$lastEditInfo->setUserName( 'new username' );
		$this->assertSame( $oldUsername, $filter->getUserIdentity()->getName(), 'original' );
		$this->assertSame( $oldUsername, $copy->getUserIdentity()->getName(), 'copy' );
	}
}
