<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\AbuseLogger;
use MediaWiki\Extension\AbuseFilter\AbuseLoggerFactory;
use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\VariablesBlobStore;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\AbuseLoggerFactory
 */
class AbuseLoggerFactoryTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::__construct
	 * @covers ::newLogger
	 * @covers \MediaWiki\Extension\AbuseFilter\AbuseLogger::__construct
	 */
	public function testNewLogger() {
		$factory = new AbuseLoggerFactory(
			$this->createMock( CentralDBManager::class ),
			$this->createMock( FilterLookup::class ),
			$this->createMock( VariablesBlobStore::class ),
			$this->createMock( ILoadBalancer::class ),
			new ServiceOptions(
				AbuseLogger::CONSTRUCTOR_OPTIONS,
				[
					'AbuseFilterLogIP' => false,
					'AbuseFilterNotifications' => 'rc',
					'AbuseFilterNotificationsPrivate' => true,
				]
			),
			'wikiID',
			'1.2.3.4'
		);
		$logger = $factory->newLogger(
			$this->createMock( Title::class ),
			$this->createMock( User::class ),
			AbuseFilterVariableHolder::newFromArray(
				[ 'action' => 'edit' ],
				$this->createMock( KeywordsManager::class )
			)
		);
		$this->assertInstanceOf( AbuseLogger::class, $logger );
	}

}
