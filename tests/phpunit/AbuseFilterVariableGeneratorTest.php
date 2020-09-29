<?php

use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\LazyVariableComputer;
use MediaWiki\Extension\AbuseFilter\TextExtractor;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionStore;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterGeneric
 *
 * @covers \MediaWiki\Extension\AbuseFilter\LazyVariableComputer::compute
 * @fixme Make this a unit test once the class stops using MediaWikiServices
 */
class AbuseFilterVariableGeneratorTest extends MediaWikiIntegrationTestCase {
	/** A fake timestamp to use in several time-related tests. */
	private const FAKE_TIME = 1514700000;

	/**
	 * @inheritDoc
	 */
	protected function tearDown() : void {
		MWTimestamp::setFakeTime( false );
		parent::tearDown();
	}

	/**
	 * @param string $method
	 * @param mixed $result
	 * @return MockObject|User User type is here for IDE-friendliness
	 */
	private function getUserWithMockedMethod( $method, $result ) {
		$user = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();

		$user->expects( $this->atLeastOnce() )
			->method( $method )
			->willReturn( $result );

		return $user;
	}

	/**
	 * Given the name of a variable, create a User mock with that value
	 *
	 * @param string $var The variable name
	 * @return array the first position is the User mock, the second is the expected value
	 *   for the given variable
	 */
	private function getUserAndExpectedVariable( $var ) {
		switch ( $var ) {
			case 'user_editcount':
				$result = 7;
				$user = $this->getUserWithMockedMethod( 'getEditCount', $result );
				break;
			case 'user_name':
				$result = 'UniqueUserName';
				$user = $this->getUserWithMockedMethod( 'getName', $result );
				break;
			case 'user_emailconfirm':
				$result = wfTimestampNow();
				$user = $this->getUserWithMockedMethod( 'getEmailAuthenticationTimestamp', $result );
				break;
			case 'user_groups':
				$result = [ '*', 'group1', 'group2' ];
				$user = $this->getUserWithMockedMethod( 'getEffectiveGroups', $result );
				break;
			case 'user_rights':
				$result = [ 'abusefilter-foo', 'abusefilter-bar' ];
				$user = $this->getUserWithMockedMethod( 'getRights', $result );
				break;
			case 'user_blocked':
				$result = true;
				$user = $this->getUserWithMockedMethod( 'getBlock', $result );
				break;
			case 'user_age':
				MWTimestamp::setFakeTime( self::FAKE_TIME );
				$result = 163;
				$user = $this->getUserWithMockedMethod( 'getRegistration', self::FAKE_TIME - $result );
				break;
			default:
				throw new LogicException( "Given unknown user-related variable $var." );
		}

		return [ $user, $result ];
	}

	/**
	 * Check that the generated user-related variables are correct
	 *
	 * @param string $varName The name of the variable we're currently testing
	 * @covers \MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator::addUserVars
	 * @dataProvider provideUserVars
	 */
	public function testAddUserVars( $varName ) {
		// Mocking the HookRunner would result in methods returning null, which would be interpreted
		// as if the handler handled the event, so use the actual runner.
		$hookRunner = AbuseFilterHookRunner::getRunner();
		$computer = new LazyVariableComputer(
			$this->createMock( TextExtractor::class ),
			$hookRunner,
			$this->createMock( TitleFactory::class ),
			new NullLogger(),
			$this->createMock( ILoadBalancer::class ),
			$this->createMock( WANObjectCache::class ),
			$this->createMock( RevisionLookup::class ),
			$this->createMock( RevisionStore::class ),
			$this->createMock( Language::class ),
			$this->createMock( Parser::class ),
			''
		);
		list( $user, $computed ) = $this->getUserAndExpectedVariable( $varName );

		$keywordsManager = new KeywordsManager( $this->createMock( AbuseFilterHookRunner::class ) );
		$variableHolder = new AbuseFilterVariableHolder( $keywordsManager );
		$variableHolder->setLazyComputer( $computer );
		$generator = new VariableGenerator( $variableHolder );
		$variableHolder = $generator->addUserVars( $user )->getVariableHolder();
		$actual = $variableHolder->getVar( $varName )->toNative();
		$this->assertSame( $computed, $actual );
	}

	/**
	 * Data provider for testAddUserVars
	 * @return Generator|array
	 */
	public function provideUserVars() {
		$vars = [
			'user_editcount',
			'user_name',
			'user_emailconfirm',
			'user_groups',
			'user_rights',
			'user_blocked',
			'user_age'
		];
		foreach ( $vars as $var ) {
			yield $var => [ $var ];
		}
	}

	/**
	 * @param string $method
	 * @param mixed $result
	 * @return MockObject|Title Title type is here for IDE-friendliness
	 */
	private function getTitleWithMockedMethod( $method, $result ) {
		$title = $this->getMockBuilder( Title::class )
			->disableOriginalConstructor()
			->getMock();

		$title->expects( $this->atLeastOnce() )
			->method( $method )
			->willReturn( $result );

		return $title;
	}

	/**
	 * Given the name of a variable, create a Title mock with that value
	 *
	 * @param string $prefix The prefix of the variable
	 * @param string $suffix The suffix of the variable
	 * @param bool $restricted Whether the title should be restricted
	 * @return array the first position is the mocked Title, the second the expected value of the var
	 */
	private function getTitleAndExpectedVariable( $prefix, $suffix, $restricted = false ) {
		switch ( $suffix ) {
			case '_id':
				$result = 1234;
				$title = $this->getTitleWithMockedMethod( 'getArticleID', $result );
				break;
			case '_namespace':
				$result = 5;
				$title = $this->getTitleWithMockedMethod( 'getNamespace', $result );
				break;
			case '_title':
				$result = 'Page title';
				$title = $this->getTitleWithMockedMethod( 'getText', $result );
				break;
			case '_prefixedtitle':
				$result = 'Page prefixedtitle';
				$title = $this->getTitleWithMockedMethod( 'getPrefixedText', $result );
				break;
			case '_restrictions_create':
			case '_restrictions_edit':
			case '_restrictions_move':
			case '_restrictions_upload':
				$result = $restricted ? [ 'sysop' ] : [];
				$title = $this->getTitleWithMockedMethod( 'getRestrictions', $result );
				break;
			// case '_recent_contributors' handled in AbuseFilterVariableGeneratorDBTest
			// case '_first_contributor' is handled in AbuseFilterDBTest
			case '_age':
				$result = 123;
				MWTimestamp::setFakeTime( self::FAKE_TIME );
				$title = $this->getTitleWithMockedMethod( 'getEarliestRevTime', self::FAKE_TIME - $result );
				break;
			default:
				throw new LogicException( "Given unknown title-related variable $prefix$suffix." );
		}

		return [ $title, $result ];
	}

	/**
	 * Check that the generated title-related variables are correct
	 *
	 * @param string $prefix The prefix of the variables we're currently testing
	 * @param string $suffix The suffix of the variables we're currently testing
	 * @param bool $restricted Used for _restrictions variable. If true,
	 *   the tested title will have the requested restriction.
	 * @covers \MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGenerator::addTitleVars
	 * @dataProvider provideTitleVars
	 */
	public function testAddTitleVars( $prefix, $suffix, $restricted = false ) {
		$varName = $prefix . $suffix;
		list( $title, $computed ) = $this->getTitleAndExpectedVariable( $prefix, $suffix, $restricted );

		$keywordsManager = new KeywordsManager( $this->createMock( AbuseFilterHookRunner::class ) );
		$variableHolder = new AbuseFilterVariableHolder( $keywordsManager );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitle' )->willReturn( $title );
		// The mock would return null, which would be interpreted as if the handler handled the event
		$hookRunner = AbuseFilterHookRunner::getRunner();
		/** @var LazyVariableComputer|MockObject $computer */
		$computer = new LazyVariableComputer(
			$this->createMock( TextExtractor::class ),
			$hookRunner,
			$titleFactory,
			new NullLogger(),
			$this->createMock( ILoadBalancer::class ),
			$this->createMock( WANObjectCache::class ),
			$this->createMock( RevisionLookup::class ),
			$this->createMock( RevisionStore::class ),
			$this->createMock( Language::class ),
			$this->createMock( Parser::class ),
			''
		);
		$variableHolder->setLazyComputer( $computer );

		$generator = new VariableGenerator( $variableHolder );
		$variableHolder = $generator->addTitleVars( $title, $prefix )->getVariableHolder();
		$actual = $variableHolder->getVar( $varName )->toNative();
		$this->assertSame( $computed, $actual );
	}

	/**
	 * Data provider for testAddTitleVars
	 * @return Generator|array
	 */
	public function provideTitleVars() {
		$prefixes = [ 'page', 'moved_from', 'moved_to' ];
		$suffixes = [
			'_id',
			'_namespace',
			'_title',
			'_prefixedtitle',
			'_restrictions_create',
			'_restrictions_edit',
			'_restrictions_move',
			'_restrictions_upload',
			'_age'
		];
		foreach ( $prefixes as $prefix ) {
			foreach ( $suffixes as $suffix ) {
				yield $prefix . $suffix => [ $prefix, $suffix ];
				if ( strpos( $suffix, 'restrictions' ) !== false ) {
					// Add a case where the page has the restriction
					yield $prefix . $suffix . ', restricted' => [ $prefix, $suffix, true ];
				}
			}
		}
	}
}
