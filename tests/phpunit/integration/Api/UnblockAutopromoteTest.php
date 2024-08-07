<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Api;

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\User\UserIdentityValue;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Api\UnblockAutopromote
 * @group medium
 * @group Database
 */
class UnblockAutopromoteTest extends ApiTestCase {
	use MockAuthorityTrait;

	public function testExecute_noPermissions() {
		$this->expectApiErrorCode( 'permissiondenied' );

		$store = $this->createMock( BlockAutopromoteStore::class );
		$store->expects( $this->never() )->method( 'unblockAutopromote' );
		$this->setService( BlockAutopromoteStore::SERVICE_NAME, $store );

		$this->doApiRequestWithToken( [
			'action' => 'abusefilterunblockautopromote',
			'user' => 'User'
		], null, $this->mockRegisteredAuthorityWithoutPermissions( [ 'abusefilter-modify' ] ), 'csrf' );
	}

	public function testExecute_invalidUser() {
		$invalid = 'invalid#username';
		$this->expectApiErrorCode( 'baduser' );

		$store = $this->createMock( BlockAutopromoteStore::class );
		$store->expects( $this->never() )->method( 'unblockAutopromote' );
		$this->setService( BlockAutopromoteStore::SERVICE_NAME, $store );

		$this->doApiRequestWithToken( [
			'action' => 'abusefilterunblockautopromote',
			'user' => $invalid
		], null, $this->mockRegisteredNullAuthority(), 'csrf' );
	}

	public function testExecute_blocked() {
		$this->expectApiErrorCode( 'blocked' );

		$block = $this->createMock( DatabaseBlock::class );
		$block->method( 'getExpiry' )->willReturn( wfTimestamp( TS_MW, time() + 100000 ) );
		$block->method( 'isSitewide' )->willReturn( true );
		$block->method( 'getReasonComment' )->willReturn( CommentStoreComment::newUnsavedComment( 'test' ) );
		$blockedUser = $this->mockUserAuthorityWithBlock(
			new UserIdentityValue( 42, 'Blocked user' ),
			$block,
			[ 'abusefilter-modify' ]
		);

		$store = $this->createMock( BlockAutopromoteStore::class );
		$store->expects( $this->never() )->method( 'unblockAutopromote' );
		$this->setService( BlockAutopromoteStore::SERVICE_NAME, $store );

		$this->doApiRequestWithToken( [
			'action' => 'abusefilterunblockautopromote',
			'user' => 'User'
		], null, $blockedUser, 'csrf' );
	}

	public function testExecute_nothingToDo() {
		$target = 'User';
		$user = $this->mockRegisteredUltimateAuthority();
		$this->expectApiErrorCode( 'notsuspended' );

		$store = $this->createMock( BlockAutopromoteStore::class );
		$store->expects( $this->once() )
			->method( 'unblockAutopromote' )
			->willReturn( false );
		$this->setService( BlockAutopromoteStore::SERVICE_NAME, $store );

		$this->doApiRequestWithToken( [
			'action' => 'abusefilterunblockautopromote',
			'user' => $target
		], null, $user, 'csrf' );
	}

	public function testExecute_success() {
		$target = 'User';
		$user = $this->mockRegisteredUltimateAuthority();

		$store = $this->createMock( BlockAutopromoteStore::class );
		$store->expects( $this->once() )
			->method( 'unblockAutopromote' )
			->willReturn( true );
		$this->setService( BlockAutopromoteStore::SERVICE_NAME, $store );

		$result = $this->doApiRequestWithToken( [
			'action' => 'abusefilterunblockautopromote',
			'user' => $target
		], null, $user, 'csrf' );

		$this->assertArrayEquals(
			[ 'abusefilterunblockautopromote' => [ 'user' => $target ] ],
			$result[0],
			false,
			true
		);
	}
}
