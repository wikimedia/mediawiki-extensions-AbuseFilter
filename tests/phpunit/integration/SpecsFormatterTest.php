<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Message\Message;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\SpecsFormatter
 */
class SpecsFormatterTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'AbuseFilterActions' => [
				'throttle' => true,
				'warn' => true,
				'disallow' => true,
				'blockautopromote' => true,
				'block' => true,
				'rangeblock' => true,
				'degroup' => true,
				'tag' => true,
			],
		] );
	}

	public function testAllEnabledActionsHaveMessages(): void {
		$customActionsHookCalled = false;
		$this->setTemporaryHook(
			'AbuseFilterCustomActions',
			static function ( array &$actions ) use ( &$customActionsHookCalled ) {
				$customActionsHookCalled = true;
			},
			false
		);

		$services = $this->getServiceContainer();
		$allEnabledActions = AbuseFilterServices::getConsequencesRegistry( $services )->getAllActionNames();

		$this->assertTrue(
			$customActionsHookCalled,
			'The AbuseFilterCustomActions hook is not called'
		);

		$missingMessages = [];
		$msgMap = [];
		foreach ( $allEnabledActions as $action ) {
			$msgKey = "abusefilter-action-$action";
			$msg = Message::newFromKey( $msgKey );
			if ( !$msg->exists() ) {
				$missingMessages[] = $msgKey;
			}
			// Count each message existence check as an assertion
			$this->addToAssertionCount( 1 );
			$msgMap[$action] = $msg;
		}

		if ( $missingMessages !== [] ) {
			$this->fail( 'Messages not found: ' . implode( ', ', $missingMessages ) );
		}

		$specsFormatter = AbuseFilterServices::getSpecsFormatter( $services );
		foreach ( $msgMap as $action => $msg ) {
			$this->assertSame( $msg->getKey(), $specsFormatter->getActionMessage( $action )->getKey() );
		}
	}
}
