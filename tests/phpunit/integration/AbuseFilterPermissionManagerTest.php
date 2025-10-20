<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWikiIntegrationTestCase;
use StatusValue;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager
 */
class AbuseFilterPermissionManagerTest extends MediaWikiIntegrationTestCase {
	use MockAuthorityTrait;
	use TempUserTestTrait;

	private function getPermissionManager() {
		return $this->getServiceContainer()->get( AbuseFilterPermissionManager::SERVICE_NAME );
	}

	public function testCanViewProtectedVariablesInFilterWhenHookDisallows() {
		// Define the AbuseFilterCanViewProtectedVariables hook to make the status a fatal status
		$this->setTemporaryHook(
			'AbuseFilterCanViewProtectedVariables',
			static function ( $performer, $variables, StatusValue $status ) {
				$status->fatal( 'test-error' );
			}
		);

		$filter = MutableFilter::newDefault();
		$filter->setProtected( true );
		/** @var AbuseFilterPermissionManager $permissionManager */
		$permissionManager = $this->getPermissionManager();
		$actualStatus = $permissionManager->canViewProtectedVariablesInFilter(
			$this->mockRegisteredUltimateAuthority(),
			$filter
		);

		$this->assertStatusError( 'test-error', $actualStatus );
	}

	/** @dataProvider provideCanSeeIPForFilterLog */
	public function testCanSeeIPForFilterLog(
		bool $checkUserResult,
		bool $canSeeLogDetails,
		bool $isTemp,
		bool $expected,
		bool $withCheckUser = false,
	) {
		if ( $withCheckUser ) {
			$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
		}

		$this->enableAutoCreateTempUser();

		$permissions = [];
		if ( $checkUserResult ) {
			$permissions[] = 'checkuser-temporary-account-no-preference';
		}
		if ( $canSeeLogDetails ) {
			$permissions[] = 'abusefilter-log-detail';
		}
		$performer = $this->mockRegisteredAuthorityWithPermissions( $permissions );
		$filter = MutableFilter::newDefault();
		$userName = $isTemp ? '~12345' : 'Test';

		/** @var AbuseFilterPermissionManager $permissionManager */
		$permissionManager = $this->getPermissionManager();

		// When CheckUser is loaded, simulate it is not loaded
		$extensionRegistry = $this->createMock( ExtensionRegistry::class );
		$extensionRegistry->method( 'isLoaded' )
				->willReturn( $withCheckUser );
		$wrapPermissionManager = TestingAccessWrapper::newFromObject( $permissionManager )
			->extensionRegistry = $extensionRegistry;

		$this->assertSame(
			$expected,
			$permissionManager->CanSeeIPForFilterLog( $performer, $filter, $userName )
		);
	}

	public static function provideCanSeeIPForFilterLog() {
		return [
			'Has all permissions' => [
				'canViewTempIPs' => true,
				'canSeeLogDetails' => true,
				'logUserIsTemp' => true,
				'expected' => true,
			],
			'Only has IP viewer permissions, temp account log (with CheckUser)' => [
				'canViewTempIPs' => true,
				'canSeeLogDetails' => false,
				'logUserIsTemp' => true,
				'expected' => true,
				'withCheckUser' => true,
			],
			'Only has IP viewer permissions, temp account log (without CheckUser)' => [
				'canViewTempIPs' => true,
				'canSeeLogDetails' => false,
				'logUserIsTemp' => true,
				'expected' => false,
				'withCheckUser' => false,
			],
			'Only has IP viewer permissions, non-temp account log' => [
				'canViewTempIPs' => true,
				'canSeeLogDetails' => false,
				'logUserIsTemp' => false,
				'expected' => false,
			],
			'Has no permissions, temp account log' => [
				'canViewTempIPs' => false,
				'canSeeLogDetails' => false,
				'logUserIsTemp' => true,
				'expected' => false,
			],
		];
	}
}
