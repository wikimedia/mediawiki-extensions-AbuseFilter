<?php

use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use MediaWiki\Extension\AbuseFilter\CentralDBNotAvailableException;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\LBFactory;

/**
 * @group Test
 * @group AbuseFilter
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\CentralDBManager
 */
class AbuseFilterCentralDBManagerTest extends MediaWikiUnitTestCase {
	/**
	 * @covers ::__construct
	 */
	public function testConstruct() {
		$this->assertInstanceOf(
			CentralDBManager::class,
			new CentralDBManager(
				$this->createMock( LBFactory::class ),
				'foo'
			)
		);
	}

	/**
	 * @covers ::getConnection
	 */
	public function testGetConnection() {
		$expected = $this->createMock( IDatabase::class );
		$lb = $this->createMock( ILoadBalancer::class );
		$lb->method( 'getConnectionRef' )->willReturn( $expected );
		$lbFactory = $this->createMock( LBFactory::class );
		$lbFactory->method( 'getMainLB' )->willReturn( $lb );
		$dbManager = new CentralDBManager( $lbFactory, 'foo' );
		$this->assertSame( $expected, $dbManager->getConnection( DB_REPLICA ) );
	}

	/**
	 * @covers ::getConnection
	 */
	public function testGetConnection_invalid() {
		$lbFactory = $this->createMock( LBFactory::class );
		$dbManager = new CentralDBManager( $lbFactory, null );
		$this->expectException( CentralDBNotAvailableException::class );
		$dbManager->getConnection( DB_REPLICA );
	}
}
