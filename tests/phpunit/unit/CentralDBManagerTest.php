<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use MediaWiki\Extension\AbuseFilter\CentralDBNotAvailableException;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * @group Test
 * @group AbuseFilter
 * @covers \MediaWiki\Extension\AbuseFilter\CentralDBManager
 */
class CentralDBManagerTest extends MediaWikiUnitTestCase {
	public function testConstruct() {
		$this->assertInstanceOf(
			CentralDBManager::class,
			new CentralDBManager(
				$this->createMock( IConnectionProvider::class ),
				'foo',
				true
			)
		);
	}

	public function testGetPrimaryDatabase() {
		$expected = $this->createMock( IDatabase::class );
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$connectionProvider->expects( $this->once() )
			->method( 'getPrimaryDatabase' )
			->with( 'foo' )
			->willReturn( $expected );
		$dbManager = new CentralDBManager( $connectionProvider, 'foo', true );
		$this->assertSame( $expected, $dbManager->getPrimaryDatabase() );
	}

	public function testGetPrimaryDatabase_invalid() {
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$dbManager = new CentralDBManager( $connectionProvider, null, true );
		$this->expectException( CentralDBNotAvailableException::class );
		$dbManager->getPrimaryDatabase();
	}

	public function testGetReplicaDatabase() {
		$expected = $this->createMock( IReadableDatabase::class );
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$connectionProvider->expects( $this->once() )
			->method( 'getReplicaDatabase' )
			->with( 'foo' )
			->willReturn( $expected );
		$dbManager = new CentralDBManager( $connectionProvider, 'foo', true );
		$this->assertSame( $expected, $dbManager->getReplicaDatabase() );
	}

	public function testGetReplicaDatabase_invalid() {
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$dbManager = new CentralDBManager( $connectionProvider, null, true );
		$this->expectException( CentralDBNotAvailableException::class );
		$dbManager->getReplicaDatabase();
	}

	public function testGetCentralDBName() {
		$expected = 'foobar';
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$dbManager = new CentralDBManager( $connectionProvider, $expected, true );
		$this->assertSame( $expected, $dbManager->getCentralDBName() );
	}

	public function testGetCentralDBName_invalid() {
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$dbManager = new CentralDBManager( $connectionProvider, null, true );
		$this->expectException( CentralDBNotAvailableException::class );
		$dbManager->getCentralDBName();
	}

	/**
	 * @param bool $value
	 * @dataProvider provideIsCentral
	 */
	public function testFilterIsCentral( bool $value ) {
		$connectionProvider = $this->createMock( IConnectionProvider::class );
		$dbManager = new CentralDBManager( $connectionProvider, 'foo', $value );
		$this->assertSame( $value, $dbManager->filterIsCentral() );
	}

	public static function provideIsCentral() {
		return [
			'central' => [ true ],
			'not central' => [ false ]
		];
	}
}
