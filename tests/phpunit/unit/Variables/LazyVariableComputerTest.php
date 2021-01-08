<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use Generator;
use Language;
use LogicException;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\Parser\AFPData;
use MediaWiki\Extension\AbuseFilter\Parser\AFPException;
use MediaWiki\Extension\AbuseFilter\TextExtractor;
use MediaWiki\Extension\AbuseFilter\Variables\LazyLoadedVariable;
use MediaWiki\Extension\AbuseFilter\Variables\LazyVariableComputer;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWikiUnitTestCase;
use MWTimestamp;
use Parser;
use Psr\Log\NullLogger;
use Title;
use User;
use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Variables\LazyVariableComputer
 * @covers ::__construct
 */
class LazyVariableComputerTest extends MediaWikiUnitTestCase {

	/**
	 * @inheritDoc
	 */
	protected function tearDown() : void {
		MWTimestamp::setFakeTime( false );
		parent::tearDown();
	}

	private function getComputer(
		Language $contentLanguage = null,
		array $hookHandlers = [],
		RevisionLookup $revisionLookup = null,
		string $wikiID = ''
	) : LazyVariableComputer {
		return new LazyVariableComputer(
			$this->createMock( TextExtractor::class ),
			new AbuseFilterHookRunner( $this->createHookContainer( $hookHandlers ) ),
			new NullLogger(),
			$this->createMock( ILoadBalancer::class ),
			$this->createMock( WANObjectCache::class ),
			$revisionLookup ?? $this->createMock( RevisionLookup::class ),
			$this->createMock( RevisionStore::class ),
			$contentLanguage ?? $this->createMock( Language::class ),
			$this->createMock( Parser::class ),
			$wikiID
		);
	}

	private function getForbidComputeCB() : callable {
		return function () {
			throw new LogicException( 'Not expected to be called' );
		};
	}

	/**
	 * @covers ::compute
	 */
	public function testWikiNameVar() {
		$fakeID = 'some-wiki-ID';
		$var = new LazyLoadedVariable( 'get-wiki-name', [] );
		$computer = $this->getComputer( null, [], null, $fakeID );
		$this->assertSame(
			$fakeID,
			$computer->compute( $var, new VariableHolder(), $this->getForbidComputeCB() )->toNative()
		);
	}

	/**
	 * @covers ::compute
	 */
	public function testWikiLanguageVar() {
		$fakeCode = 'foobar';
		$fakeLang = $this->createMock( Language::class );
		$fakeLang->method( 'getCode' )->willReturn( $fakeCode );
		$computer = $this->getComputer( $fakeLang );
		$var = new LazyLoadedVariable( 'get-wiki-language', [] );
		$this->assertSame(
			$fakeCode,
			$computer->compute( $var, new VariableHolder(), $this->getForbidComputeCB() )->toNative()
		);
	}

	/**
	 * @covers ::compute
	 */
	public function testCompute_invalidName() {
		$computer = $this->getComputer();
		$this->expectException( AFPException::class );
		$computer->compute(
			new LazyLoadedVariable( 'method-does-not-exist', [] ),
			new VariableHolder(),
			$this->getForbidComputeCB()
		);
	}

	/**
	 * @covers ::compute
	 */
	public function testInterceptVariableHook() {
		$expected = new AFPData( AFPData::DSTRING, 'foobar' );
		$handler = function ( $method, $vars, $params, &$result ) use ( $expected ) {
			$result = $expected;
			return false;
		};
		$computer = $this->getComputer( null, [ 'AbuseFilter-interceptVariable' => $handler ] );
		$actual = $computer->compute(
			new LazyLoadedVariable( 'get-wiki-name', [] ),
			new VariableHolder(),
			$this->getForbidComputeCB()
		);
		$this->assertSame( $expected, $actual );
	}

	/**
	 * @covers ::compute
	 */
	public function testComputeVariableHook() {
		$expected = new AFPData( AFPData::DSTRING, 'foobar' );
		$handler = function ( $method, $vars, $params, &$result ) use ( $expected ) {
			$result = $expected;
			return false;
		};
		$computer = $this->getComputer( null, [ 'AbuseFilter-computeVariable' => $handler ] );
		$actual = $computer->compute(
			new LazyLoadedVariable( 'method-does-not-exist', [] ),
			new VariableHolder(),
			$this->getForbidComputeCB()
		);
		$this->assertSame( $expected, $actual );
	}

	/**
	 * @param LazyLoadedVariable $var
	 * @param mixed $expected
	 * @covers ::compute
	 * @dataProvider provideUserRelatedVars
	 */
	public function testUserRelatedVars( LazyLoadedVariable $var, $expected ) {
		$computer = $this->getComputer();
		$this->assertSame(
			$expected,
			$computer->compute( $var, new VariableHolder(), $this->getForbidComputeCB() )->toNative()
		);
	}

	public function provideUserRelatedVars() : Generator {
		$user = $this->createMock( User::class );
		$getAccessorVar = function ( $user, $method ) : LazyLoadedVariable {
			return new LazyLoadedVariable(
				'simple-user-accessor',
				[ 'user' => $user, 'method' => $method ]
			);
		};

		$editCount = 7;
		$user->method( 'getEditCount' )->willReturn( $editCount );
		$var = $getAccessorVar( $user, 'getEditCount' );
		yield 'user_editcount' => [ $var, $editCount ];

		$emailConfirm = '20000101000000';
		$user->method( 'getEmailAuthenticationTimestamp' )->willReturn( $emailConfirm );
		$var = $getAccessorVar( $user, 'getEmailAuthenticationTimestamp' );
		yield 'user_emailconfirm' => [ $var, $emailConfirm ];

		$groups = [ '*', 'group1', 'group2' ];
		$user->method( 'getEffectiveGroups' )->willReturn( $groups );
		$var = $getAccessorVar( $user, 'getEffectiveGroups' );
		yield 'user_groups' => [ $var, $groups ];

		$rights = [ 'abusefilter-foo', 'abusefilter-bar' ];
		$user->method( 'getRights' )->willReturn( $rights );
		$var = $getAccessorVar( $user, 'getRights' );
		yield 'user_rights' => [ $var, $rights ];

		$blocked = true;
		$user->method( 'getBlock' )->willReturn( $blocked );
		$var = new LazyLoadedVariable(
			'user-block',
			[ 'user' => $user ]
		);
		yield 'user_blocked' => [ $var, $blocked ];

		$fakeTime = 1514700000;

		$anonUser = $this->createMock( User::class );
		$anonymousAge = 0;
		$var = new LazyLoadedVariable(
			'user-age',
			[ 'user' => $anonUser, 'asof' => $fakeTime ]
		);
		yield 'user_age, anonymous' => [ $var, $anonymousAge ];

		$user->method( 'isRegistered' )->willReturn( true );

		$missingRegistrationUser = clone $user;
		$var = new LazyLoadedVariable(
			'user-age',
			[ 'user' => $missingRegistrationUser, 'asof' => $fakeTime ]
		);
		$expected = (int)wfTimestamp( TS_UNIX, $fakeTime ) - (int)wfTimestamp( TS_UNIX, '20080115000000' );
		yield 'user_age, registered but not available' => [ $var, $expected ];

		$age = 163;
		$user->method( 'getRegistration' )->willReturn( $fakeTime - $age );
		$var = new LazyLoadedVariable(
			'user-age',
			[ 'user' => $user, 'asof' => $fakeTime ]
		);
		yield 'user_age, registered' => [ $var, $age ];
	}

	/**
	 * @param LazyLoadedVariable $var
	 * @param mixed $expected
	 * @param RevisionLookup|null $revisionLookup
	 * @covers ::compute
	 * @dataProvider provideTitleRelatedVars
	 */
	public function testTitleRelatedVars(
		LazyLoadedVariable $var,
		$expected,
		RevisionLookup $revisionLookup = null
	) {
		$computer = $this->getComputer( null, [], $revisionLookup );
		$this->assertSame(
			$expected,
			$computer->compute( $var, new VariableHolder(), $this->getForbidComputeCB() )->toNative()
		);
	}

	public function provideTitleRelatedVars() : Generator {
		$restrictions = [ 'create', 'edit', 'move', 'upload' ];
		foreach ( $restrictions as $restriction ) {
			$appliedRestrictions = [ 'sysop' ];
			$restrictedTitle = $this->createMock( Title::class );
			$restrictedTitle->method( 'getRestrictions' )->willReturn( $appliedRestrictions );
			$var = new LazyLoadedVariable(
				'get-page-restrictions',
				[ 'title' => $restrictedTitle, 'action' => $restriction ]
			);
			yield "*_restrictions_{$restriction}, restricted" => [ $var, $appliedRestrictions ];
			$unrestrictedTitle = $this->createMock( Title::class );
			$unrestrictedTitle->method( 'getRestrictions' )->willReturn( [] );
			$var = new LazyLoadedVariable(
				'get-page-restrictions',
				[ 'title' => $unrestrictedTitle, 'action' => $restriction ]
			);
			yield "*_restrictions_{$restriction}, unrestricted" => [ $var, [] ];
		}

		$fakeTime = 1514700000;

		$age = 163;
		$title = $this->createMock( Title::class );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getTimestamp' )->willReturn( $fakeTime - $age );
		$revLookup = $this->createMock( RevisionLookup::class );
		$revLookup->method( 'getFirstRevision' )->with( $title )->willReturn( $revision );
		$var = new LazyLoadedVariable(
			'page-age',
			[ 'title' => $title, 'asof' => $fakeTime ]
		);
		yield "*_age" => [ $var, $age, $revLookup ];

		// TODO _first_contributor and _recent_contributors are tested in LazyVariableComputerDBTest
	}
}
