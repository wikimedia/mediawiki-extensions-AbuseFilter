<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiIntegrationTestCase;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager
 */
class AbuseFilterPermissionManagerTest extends MediaWikiIntegrationTestCase {
	use MockAuthorityTrait;

	public function testCanViewProtectedVariablesInFilterWhenHookDisallows() {
		// Define the AbuseFilterCanViewProtectedVariables hook to make the status a fatal status
		$this->setTemporaryHook(
			'AbuseFilterCanViewProtectedVariables',
			static function ( $performer, $variables, StatusValue $status ) {
				$status->fatal( 'test-error' );
			}
		);
		$this->setTemporaryHook(
			'AbuseFilterCanViewProtectedVariableValues',
			function () {
				$this->fail( 'The AbuseFilterCanViewProtectedVariableValues hook was not expected to be called.' );
			}
		);

		$filter = MutableFilter::newDefault();
		$filter->setProtected( true );
		$actualStatus = $this->getServiceContainer()->get( AbuseFilterPermissionManager::SERVICE_NAME )
			->canViewProtectedVariablesInFilter(
				$this->mockRegisteredUltimateAuthority(),
				$filter
			);
		$this->assertStatusError( 'test-error', $actualStatus );
	}

	public function testCanViewProtectedVariableValuesWhenHookDisallows() {
		// Define the AbuseFilterCanViewProtectedVariableValues hook to make the status a fatal status
		$this->setTemporaryHook(
			'AbuseFilterCanViewProtectedVariableValues',
			static function ( $performer, $variables, StatusValue $status ) {
				$status->fatal( 'test-error' );
			}
		);
		$this->setTemporaryHook(
			'AbuseFilterCanViewProtectedVariables',
			function () {
				$this->fail( 'The AbuseFilterCanViewProtectedVariables hook was not expected to be called.' );
			}
		);

		$actualStatus = $this->getServiceContainer()->get( AbuseFilterPermissionManager::SERVICE_NAME )
			->canViewProtectedVariableValues( $this->mockRegisteredUltimateAuthority(), [] );
		$this->assertStatusError( 'test-error', $actualStatus );
	}
}
