<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use ExtensionRegistry;
use Generator;
use MediaWiki\CheckUser\Logging\TemporaryAccountLogger;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\ProtectedVarsAccessLogger;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\ProtectedVarsAccessLogger
 * @group Database
 */
class ProtectedVarsAccessLoggerTest extends MediaWikiIntegrationTestCase {
	public function provideProtectedVarsLogTypes(): Generator {
		yield 'enable access to protected vars values' => [
			[
				'logAction' => 'logAccessEnabled',
				'params' => [],
			],
			[
				'expectedCULogType' => 'af-change-access-enable',
				'expectedAFLogType' => 'change-access-enable',
			]
		];

		yield 'disable access to protected vars values' => [
			[
				'logAction' => 'logAccessDisabled',
				'params' => []
			],
			[
				'expectedCULogType' => 'af-change-access-disable',
				'expectedAFLogType' => 'change-access-disable'
			]
		];
	}

	/**
	 * @dataProvider provideProtectedVarsLogTypes
	 */
	public function testProtectedVarsLoggerNoCUEnabled() {
		$extensionRegistry = $this->createMock( ExtensionRegistry::class );
		$extensionRegistry->method( 'isLoaded' )->with( 'CheckUser' )->willReturn( false );
		$this->setService( 'ExtensionRegistry', $extensionRegistry );

		$performer = $this->getTestSysop();
		AbuseFilterServices::getAbuseLoggerFactory()
			->getProtectedVarsAccessLogger()
			->logAccessEnabled( $performer->getUserIdentity() );

		// Assert that the action wasn't inserted into CheckUsers' temp account logging table
		$this->assertSame(
			0,
			(int)$this->getDb()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'logging' )
				->where( [
					'log_action' => 'af-change-access-enable',
					'log_type' => TemporaryAccountLogger::LOG_TYPE,
					] )
				->fetchField()
		);
		// and also that it was inserted into abusefilter's protected vars logging table
		$this->assertSame(
			1,
			(int)$this->getDb()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'logging' )
				->where( [
					'log_action' => 'change-access-enable',
					'log_type' => ProtectedVarsAccessLogger::LOG_TYPE,
					] )
				->fetchField()
		);

		$this->resetServices();
	}

	/**
	 * @dataProvider provideProtectedVarsLogTypes
	 */
	public function testProtectedVarsLoggerSendingLogsToCUTable( $options, $expected ) {
		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );

		$performer = $this->getTestSysop();
		$logAction = $options['logAction'];
		AbuseFilterServices::getAbuseLoggerFactory()
			->getProtectedVarsAccessLogger()
			->$logAction( $performer->getUserIdentity(), ...$options['params'] );

		// Assert that the action was inserted into CheckUsers' temp account logging table
		$this->assertSame(
			1,
			(int)$this->getDb()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'logging' )
				->where( [
					'log_action' => $expected['expectedCULogType'],
					'log_type' => TemporaryAccountLogger::LOG_TYPE,
					] )
				->fetchField()
		);
		// and also that it wasn't inserted into abusefilter's protected vars logging table
		$this->assertSame(
			0,
			(int)$this->getDb()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'logging' )
				->where( [
					'log_action' => $expected['expectedAFLogType'],
					'log_type' => ProtectedVarsAccessLogger::LOG_TYPE,
					] )
				->fetchField()
		);
	}
}
