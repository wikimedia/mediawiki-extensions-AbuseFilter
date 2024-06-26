<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use Generator;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\Filter\AbstractFilter;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager
 */
class AbuseFilterPermissionManagerTest extends MediaWikiUnitTestCase {
	use MockAuthorityTrait;

	private function getPermMan(): AbuseFilterPermissionManager {
		return new AbuseFilterPermissionManager(
			new ServiceOptions(
				AbuseFilterPermissionManager::CONSTRUCTOR_OPTIONS,
				[
					'AbuseFilterProtectedVariables' => [ 'user_unnamed_ip' ]
				]
			)
		);
	}

	public function provideCanEdit(): Generator {
		$sitewideBlock = $this->createMock( DatabaseBlock::class );
		$sitewideBlock->method( 'isSiteWide' )->willReturn( true );
		yield 'blocked sitewide' => [ $sitewideBlock, [], false ];

		$partialBlock = $this->createMock( DatabaseBlock::class );
		$partialBlock->method( 'isSiteWide' )->willReturn( false );
		yield 'partially blocked' => [ $partialBlock, [], false ];

		yield 'unblocked, no right' => [ null, [], false ];

		yield 'success' => [ null, [ 'abusefilter-modify' ], true ];
	}

	/**
	 * @param ?DatabaseBlock $block
	 * @param array $rights
	 * @param bool $expected
	 * @dataProvider provideCanEdit
	 */
	public function testCanEdit( ?DatabaseBlock $block, array $rights, bool $expected ) {
		if ( $block !== null ) {
			$performer = $this->mockUserAuthorityWithBlock(
				$this->mockRegisteredUltimateAuthority()->getUser(),
				$block,
				$rights
			);
		} else {
			$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		}
		$this->assertSame(
			$expected,
			$this->getPermMan()->canEdit( $performer )
		);
	}

	public static function provideCanEditGlobal(): Generator {
		yield 'not allowed' => [ [], false ];
		yield 'allowed' => [ [ 'abusefilter-modify-global' ], true ];
	}

	/**
	 * @dataProvider provideCanEditGlobal
	 */
	public function testCanEditGlobal( array $rights, bool $expected ) {
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$expected,
			$this->getPermMan()->canEditGlobal( $performer )
		);
	}

	public function provideCanEditFilter(): Generator {
		$localFilter = MutableFilter::newDefault();
		$localFilter->setGlobal( false );
		$globalFilter = MutableFilter::newDefault();
		$globalFilter->setGlobal( true );
		foreach ( $this->provideCanEdit() as $name => $editArgs ) {
			foreach ( self::provideCanEditGlobal() as $allowed => $globalArgs ) {
				yield "can edit: $name; can edit global: $allowed; local filter" => [
					$localFilter,
					$editArgs[0],
					array_merge( $editArgs[1], $globalArgs[0] ),
					$editArgs[2]
				];
				yield "can edit: $name; can edit global: $allowed; global filter" => [
					$globalFilter,
					$editArgs[0],
					array_merge( $editArgs[1], $globalArgs[0] ),
					$editArgs[2] && $globalArgs[1]
				];
			}
		}
	}

	/**
	 * @param AbstractFilter $filter
	 * @param ?DatabaseBlock $block
	 * @param array $rights
	 * @param bool $expected
	 * @dataProvider provideCanEditFilter
	 */
	public function testCanEditFilter(
		AbstractFilter $filter,
		?DatabaseBlock $block,
		array $rights,
		bool $expected
	) {
		if ( $block !== null ) {
			$performer = $this->mockUserAuthorityWithBlock(
				$this->mockRegisteredUltimateAuthority()->getUser(),
				$block,
				$rights
			);
		} else {
			$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		}
		$this->assertSame(
			$expected,
			$this->getPermMan()->canEditFilter( $performer, $filter )
		);
	}

	public static function provideCanViewPrivateFilters(): Generator {
		yield 'not privileged' => [ [], false ];
		yield 'modify' => [ [ 'abusefilter-modify' ], true ];
		yield 'private' => [ [ 'abusefilter-view-private' ], true ];
		yield 'both' => [ [ 'abusefilter-modify', 'abusefilter-view-private' ], true ];
	}

	/**
	 * @dataProvider provideCanViewPrivateFilters
	 */
	public function testCanViewPrivateFilters( array $rights, bool $expected ) {
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$expected,
			$this->getPermMan()->canViewPrivateFilters( $performer )
		);
	}

	public static function provideCanViewPrivateFiltersLogs(): Generator {
		yield 'not privileged' => [ [], false ];
		yield 'can view private' => [ [ 'abusefilter-view-private' ], true ];
		yield 'can view logs' => [ [ 'abusefilter-log-private' ], true ];
		yield 'both' => [ [ 'abusefilter-view-private', 'abusefilter-log-private' ], true ];
	}

	/**
	 * @param array $rights
	 * @param bool $expected
	 * @dataProvider provideCanViewPrivateFiltersLogs
	 */
	public function testCanViewPrivateFiltersLogs( array $rights, bool $expected ) {
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$expected,
			$this->getPermMan()->canViewPrivateFiltersLogs( $performer )
		);
	}

	public static function provideCanSeeLogDetailsForFilter(): Generator {
		$details = [ 0 => 'abusefilter-log-detail' ];
		$private = [ 1 => 'abusefilter-log-private' ];
		$protected = [ 2 => 'abusefilter-access-protected-vars' ];
		yield 'filter hidden, not privileged' => [ Flags::FILTER_HIDDEN, [], false ];
		yield 'filter hidden, details only' => [ Flags::FILTER_HIDDEN, $details, false ];
		yield 'filter hidden, private logs only' => [ Flags::FILTER_HIDDEN, $private, false ];
		yield 'filter hidden, details and private logs' => [ Flags::FILTER_HIDDEN, $details + $private, true ];
		yield 'filter protected, not privileged' => [ Flags::FILTER_USES_PROTECTED_VARS, [], false ];
		yield 'filter protected, privileged' => [ Flags::FILTER_USES_PROTECTED_VARS, $protected, true ];
		yield 'filter hidden and protected, details and private only' => [
			Flags::FILTER_HIDDEN | Flags::FILTER_USES_PROTECTED_VARS, $details + $private, false
		];
		yield 'filter hidden and protected, protected only' => [
			Flags::FILTER_HIDDEN | Flags::FILTER_USES_PROTECTED_VARS, $protected, false
		];
		yield 'filter visible, not privileged' => [ Flags::FILTER_PUBLIC, [], false ];
		yield 'filter visible, privileged' => [ Flags::FILTER_PUBLIC, $details, true ];
	}

	public function provideCanViewProtectedVariables(): Generator {
		$block = $this->createMock( DatabaseBlock::class );
		$block->method( 'isSiteWide' )->willReturn( true );
		yield 'not privileged, blocked' => [ $block, [], false ];
		yield 'not privileged, not blocked' => [ null, [], false ];
		yield 'has right, blocked' => [ $block, [ 'abusefilter-access-protected-vars' ], false ];
		yield 'has right, not blocked' => [ null, [ 'abusefilter-access-protected-vars' ], true ];
	}

	/**
	 * @dataProvider provideCanViewProtectedVariables
	 */
	public function testCanViewProtectedVariables( ?DatabaseBlock $block, array $rights, bool $expected ) {
		if ( $block !== null ) {
			$performer = $this->mockUserAuthorityWithBlock(
				$this->mockRegisteredUltimateAuthority()->getUser(),
				$block,
				$rights
			);
		} else {
			$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		}

		$this->assertSame(
			$expected,
			$this->getPermMan()->canViewProtectedVariables( $performer )
		);
	}

	public static function provideShouldProtectFilter(): Generator {
		yield 'cannot view, protected vars' => [
			[
				'rights' => [],
				'usedVars' => [ 'user_unnamed_ip' ]
			],
			[ 'user_unnamed_ip' ]
		];
		yield 'cannot view, no protected vars' => [
			[
				'rights' => [],
				'usedVars' => []
			],
			false
		];
		yield 'can view, protected vars' => [
			[
				'rights' => [ 'abusefilter-access-protected-vars' ],
				'usedVars' => [ 'user_unnamed_ip' ]
			],
			true
		];
		yield 'can view, no protected vars' => [
			[
				'rights' => [ 'abusefilter-access-protected-vars' ],
				'usedVars' => []
			],
			false
		];
	}

	/**
	 * @dataProvider provideShouldProtectFilter
	 */
	public function testShouldProtectFilter( array $data, $expected ) {
		$performer = $this->mockRegisteredAuthorityWithPermissions( $data[ 'rights' ] );
		$this->assertSame(
			$expected,
			$this->getPermMan()->shouldProtectFilter( $performer, $data[ 'usedVars' ] )
		);
	}

	/**
	 * @param int $privacyLevel
	 * @param array $rights
	 * @param bool $expected
	 * @dataProvider provideCanSeeLogDetailsForFilter
	 */
	public function testCanSeeLogDetailsForFilter( int $privacyLevel, array $rights, bool $expected ) {
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$expected,
			$this->getPermMan()->canSeeLogDetailsForFilter( $performer, $privacyLevel )
		);
	}

	public static function provideSimpleCases(): array {
		return [
			'not allowed' => [ false ],
			'allowed' => [ true ],
		];
	}

	/**
	 * @dataProvider provideSimpleCases
	 */
	public function testCanEditFilterWithRestrictedActions( bool $allowed ) {
		$rights = $allowed ? [ 'abusefilter-modify-restricted' ] : [];
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$allowed,
			$this->getPermMan()->canEditFilterWithRestrictedActions( $performer )
		);
	}

	/**
	 * @dataProvider provideSimpleCases
	 */
	public function testCanViewAbuseLog( bool $allowed ) {
		$rights = $allowed ? [ 'abusefilter-log' ] : [];
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$allowed,
			$this->getPermMan()->canViewAbuseLog( $performer )
		);
	}

	/**
	 * @dataProvider provideSimpleCases
	 */
	public function testCanHideAbuseLog( bool $allowed ) {
		$rights = $allowed ? [ 'abusefilter-hide-log' ] : [];
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$allowed,
			$this->getPermMan()->canHideAbuseLog( $performer )
		);
	}

	/**
	 * @dataProvider provideSimpleCases
	 */
	public function testCanRevertFilterActions( bool $allowed ) {
		$rights = $allowed ? [ 'abusefilter-revert' ] : [];
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$allowed,
			$this->getPermMan()->canRevertFilterActions( $performer )
		);
	}

	/**
	 * @dataProvider provideSimpleCases
	 */
	public function testCanSeeLogDetails( bool $allowed ) {
		$rights = $allowed ? [ 'abusefilter-log-detail' ] : [];
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$allowed,
			$this->getPermMan()->canSeeLogDetails( $performer )
		);
	}

	/**
	 * @dataProvider provideSimpleCases
	 */
	public function testCanSeePrivateDetails( bool $allowed ) {
		$rights = $allowed ? [ 'abusefilter-privatedetails' ] : [];
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$allowed,
			$this->getPermMan()->canSeePrivateDetails( $performer )
		);
	}

	/**
	 * @dataProvider provideSimpleCases
	 */
	public function testCanSeeHiddenLogEntries( bool $allowed ) {
		$rights = $allowed ? [ 'abusefilter-hidden-log' ] : [];
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$allowed,
			$this->getPermMan()->canSeeHiddenLogEntries( $performer )
		);
	}

	/**
	 * @dataProvider provideSimpleCases
	 */
	public function testCanUseTestTools( bool $allowed ) {
		$rights = $allowed ? [ 'abusefilter-modify' ] : [];
		$performer = $this->mockRegisteredAuthorityWithPermissions( $rights );
		$this->assertSame(
			$allowed,
			$this->getPermMan()->canUseTestTools( $performer )
		);
	}

}
