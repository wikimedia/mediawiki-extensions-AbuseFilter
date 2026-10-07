<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWikiIntegrationTestCase;

/**
 * @group Test
 * @group AbuseFilter
 * @covers \MediaWiki\Extension\AbuseFilter\Variables\AbuseFilterProtectedVariablesLookup
 */
class AbuseFilterProtectedVariablesLookupTest extends MediaWikiIntegrationTestCase {
	public function testGetAllProtectedVariables() {
		// Set up the list of protected variables using both the config and hook.
		$this->overrideConfigValue( 'AbuseFilterProtectedVariables', [ 'user_unnamed_ip' ] );
		$this->setTemporaryHook( 'AbuseFilterCustomProtectedVariables', static function ( &$variables ) {
			$variables[] = 'custom_variable';
		} );
		// Call the service to get the protected variables
		$objectUnderTest = AbuseFilterServices::getProtectedVariablesLookup( $this->getServiceContainer() );
		$this->assertArrayEquals(
			[ 'user_unnamed_ip', 'custom_variable' ],
			$objectUnderTest->getAllProtectedVariables()
		);
	}

	public function testGetProtectedVariablesThatDeleteLogEntries(): void {
		$this->overrideConfigValue( 'AbuseFilterProtectedVariables', [ 'user_unnamed_ip' ] );
		$this->setTemporaryHook( 'AbuseFilterCustomProtectedVariables', static function ( &$variables ) {
			$variables[] = 'custom_variable';
		} );
		$this->setTemporaryHook(
			'AbuseFilterProtectedVariablesDeleteLogEntries',
			static function ( &$variables ) {
				$variables[] = 'custom_variable';
				$variables[] = 'custom_variable';
				$variables[] = 'not_protected_variable';
			}
		);

		$objectUnderTest = AbuseFilterServices::getProtectedVariablesLookup( $this->getServiceContainer() );
		$this->assertSame(
			[ 'custom_variable' ],
			$objectUnderTest->getProtectedVariablesThatDeleteLogEntries(),
			'Only unique protected variables should be returned'
		);
	}
}
