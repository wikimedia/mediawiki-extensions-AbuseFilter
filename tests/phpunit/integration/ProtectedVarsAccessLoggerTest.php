<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use Generator;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\ProtectedVarsAccessLogger;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\ProtectedVarsAccessLogger
 * @group Database
 */
class ProtectedVarsAccessLoggerTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		// Stop external extensions (CU) from affecting the behaviour of the logger, such as changing where the
		// logs are sent.
		$this->clearHook( 'AbuseFilterLogProtectedVariableValueAccess' );
	}

	public function provideProtectedVarsLogTypes(): Generator {
		yield 'enable access to protected vars values' => [
			[
				'logAction' => 'logAccessEnabled',
				'params' => [],
			],
			[
				'expectedAFLogType' => 'change-access-enable',
			]
		];

		yield 'disable access to protected vars values' => [
			[
				'logAction' => 'logAccessDisabled',
				'params' => []
			],
			[
				'expectedAFLogType' => 'change-access-disable'
			]
		];
	}

	/**
	 * @dataProvider provideProtectedVarsLogTypes
	 */
	public function testLogs_NoHookModifications( $options, $expected ) {
		// Stop external extensions (CU) from affecting the logger by overriding any modifications
		$this->clearHook( 'AbuseFilterLogProtectedVariableValueAccess' );

		$performer = $this->getTestSysop();
		$logAction = $options['logAction'];
		AbuseFilterServices::getAbuseLoggerFactory()
			->getProtectedVarsAccessLogger()
			->$logAction( $performer->getUserIdentity(), ...$options['params'] );

		// Assert that the log was inserted into abusefilter's protected vars logging table
		$this->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'logging' )
			->where( [
				'log_action' => $expected['expectedAFLogType'],
				'log_type' => ProtectedVarsAccessLogger::LOG_TYPE,
			] )
			->assertFieldValue( 1 );
	}

	public function testDebouncedLogs_NoHookModifications() {
		// Stop external extensions (CU) from affecting the logger by overriding any modifications
		$this->clearHook( 'AbuseFilterLogProtectedVariableValueAccess' );

		// Run the same action twice
		$performer = $this->getTestSysop();
		AbuseFilterServices::getAbuseLoggerFactory()
			->getProtectedVarsAccessLogger()
			->logViewProtectedVariableValue( $performer->getUserIdentity(), '~2024-01', (int)wfTimestamp() );
		AbuseFilterServices::getAbuseLoggerFactory()
			->getProtectedVarsAccessLogger()
			->logViewProtectedVariableValue( $performer->getUserIdentity(), '~2024-01', (int)wfTimestamp() );
		DeferredUpdates::doUpdates();

		// Assert that the log is only inserted once into abusefilter's protected vars logging table
		$this->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'logging' )
			->where( [
				'log_action' => 'view-protected-var-value',
				'log_type' => ProtectedVarsAccessLogger::LOG_TYPE,
			] )
			->assertFieldValue( 1 );
	}

	public function testDebouncedLogs_HandlesSpacesInTargetUsername() {
		// Attempt to create two protected var access logs where the target is a username with spaces.
		$performer = $this->getTestSysop();
		$protectedVarsAccessLogger = AbuseFilterServices::getAbuseLoggerFactory()->getProtectedVarsAccessLogger();
		$protectedVarsAccessLogger->logViewProtectedVariableValue(
			$performer->getUserIdentity(), 'Username with spaces', (int)wfTimestamp()
		);
		$protectedVarsAccessLogger->logViewProtectedVariableValue(
			$performer->getUserIdentity(), 'Username with spaces', (int)wfTimestamp()
		);
		DeferredUpdates::doUpdates();

		// Assert that only one log is created, as it should have been debounced (was not before T389854)
		$this->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'logging' )
			->where( [
				'log_action' => 'view-protected-var-value',
				'log_type' => ProtectedVarsAccessLogger::LOG_TYPE,
			] )
			->assertFieldValue( 1 );
	}

	public function testProtectedVarsAccessLogger_HookModification() {
		$this->setTemporaryHook( 'AbuseFilterLogProtectedVariableValueAccess', static function (
			UserIdentity $performer,
			string $target,
			string $action,
			bool $shouldDebounce,
			int $timestamp,
			array $params
		) {
			return false;
		} );

		// Run a loggable action
		$performer = $this->getTestSysop();
		AbuseFilterServices::getAbuseLoggerFactory()
			->getProtectedVarsAccessLogger()
			->logViewProtectedVariableValue( $performer->getUserIdentity(), '~2024-01', (int)wfTimestamp() );

		// Assert that the hook abort also aborted AbuseFilter's logging
		$this->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'logging' )
			->where( [
				'log_action' => 'view-protected-var-value',
				'log_type' => ProtectedVarsAccessLogger::LOG_TYPE,
			] )
			->assertFieldValue( 0 );
	}
}
