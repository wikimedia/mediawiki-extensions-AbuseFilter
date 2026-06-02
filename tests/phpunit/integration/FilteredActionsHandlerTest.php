<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Content\Content;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\AbuseFilter\BlockedDomains\BlockedDomainFilter;
use MediaWiki\Extension\AbuseFilter\BlockedDomains\IBlockedDomainStorage;
use MediaWiki\Extension\AbuseFilter\EditRevUpdater;
use MediaWiki\Extension\AbuseFilter\FilterRunner;
use MediaWiki\Extension\AbuseFilter\FilterRunnerFactory;
use MediaWiki\Extension\AbuseFilter\Hooks\Handlers\FilteredActionsHandler;
use MediaWiki\Extension\AbuseFilter\Parser\AFPData;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\RunVariableGenerator;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGeneratorFactory;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Message\Message;
use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\TempUser\TempUserConfig;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWikiIntegrationTestCase;
use Wikimedia\Stats\StatsFactory;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Hooks\Handlers\FilteredActionsHandler
 * @group Database
 */
class FilteredActionsHandlerTest extends MediaWikiIntegrationTestCase {

	private array $blockedDomains = [ 'foo.com' => true ];

	/**
	 * @dataProvider provideOnEditFilterMergedContent
	 * @covers \MediaWiki\Extension\AbuseFilter\BlockedDomains\BlockedDomainFilter
	 */
	public function testOnEditFilterMergedContent( $urlsAdded, $expected ) {
		$this->overrideConfigValue( 'AbuseFilterEnableBlockedExternalDomain', true );

		$statsHelper = StatsFactory::newUnitTestingHelper()->withComponent( 'AbuseFilter' );
		$filteredActionsHandler = $this->getFilteredActionsHandler( $urlsAdded, $statsHelper->getStatsFactory() );
		$context = RequestContext::getMain();
		$context->setTitle( Title::makeTitle( NS_MAIN, 'TestPage' ) );
		$content = $this->createMock( Content::class );
		$user = $this->getTestUser()->getUser();

		$status = Status::newGood();

		$res = $filteredActionsHandler->onEditFilterMergedContent(
			$context,
			$content,
			$status,
			'Edit summary',
			$user,
			false
		);
		$this->assertSame( $expected, $res );
		$this->assertSame( $expected, $status->isOK() );
		$this->assertSame( 1, $statsHelper->count( 'filter_run_duration_seconds{action="edit"}' ) );

		if ( !$expected ) {
			// If it's failing, it should report the URL somewhere
			$this->assertStringContainsString(
				'foo.com',
				wfMessage( $status->getMessages()[0] )->toString( Message::FORMAT_PLAIN )
			);
		}
	}

	public static function provideOnEditFilterMergedContent() {
		return [
			'subdomain of blocked domain' => [ 'https://bar.foo.com', false ],
			'bare domain with nothing' => [ 'https://foo.com', false ],
			'blocked domain with path' => [ 'https://foo.com/foo/', false ],
			'blocked domain with parameters' => [ 'https://foo.com?foo=bar', false ],
			'blocked domain with path and parameters' => [ 'https://foo.com/foo/?foo=bar', false ],
			'blocked domain with port' => [ 'https://foo.com:9000', false ],
			'blocked domain as uppercase' => [ 'https://FOO.com', false ],
			'unusual protocol' => [ 'ftp://foo.com', false ],
			'mailto is special' => [ 'mailto://user@foo.com', false ],
			'domain not blocked' => [ 'https://foo.bar.com', true ],
			'domain not blocked but it might mistake the subdomain' => [ 'https://foo.com.bar.com', true ],
		];
	}

	private function getFilteredActionsHandler(
		$urlsAdded,
		?StatsFactory $statsFactory = null
	): FilteredActionsHandler {
		$mockRunner = $this->createMock( FilterRunner::class );
		$mockRunner->method( 'run' )
			->willReturn( Status::newGood() );
		$filterRunnerFactory = $this->createMock( FilterRunnerFactory::class );
		$filterRunnerFactory->method( 'newRunner' )
			->willReturn( $mockRunner );

		$vars = new VariableHolder();
		$vars->setVar( 'added_links', AFPData::newFromPHPVar( $urlsAdded ) );

		$variableGenerator = $this->createMock( RunVariableGenerator::class );
		$variableGenerator->method( 'getEditVars' )
			->willReturn( $vars );

		$variableGeneratorFactory = $this->createMock( VariableGeneratorFactory::class );
		$variableGeneratorFactory->method( 'newRunGenerator' )
			->willReturn( $variableGenerator );

		$editRevUpdater = $this->createMock( EditRevUpdater::class );

		$variablesManager = $this->createMock( VariablesManager::class );
		$variablesManager->method( 'getVar' )
			->willReturnCallback( static fn ( $unused, $vars ) => AFPData::newFromPHPVar( $urlsAdded ) );

		$blockedDomainStorage = $this->createMock( IBlockedDomainStorage::class );
		$blockedDomainStorage->method( 'loadComputed' )
			->willReturn( $this->blockedDomains );
		$blockedDomainFilter = new BlockedDomainFilter( $variablesManager, $blockedDomainStorage );

		$permissionManager = $this->createMock( PermissionManager::class );
		$permissionManager->method( 'userHasRight' )
			->willReturn( false );

		return new FilteredActionsHandler(
			$statsFactory ?? StatsFactory::newNull(),
			$filterRunnerFactory,
			$variableGeneratorFactory,
			$editRevUpdater,
			$blockedDomainFilter,
			$permissionManager,
			$this->createMock( TitleFactory::class ),
			$this->createMock( UserFactory::class ),
			$this->createNoOpMock( TempUserConfig::class )
		);
	}

	/**
	 * @dataProvider provideParserOutputStashForEdit
	 */
	public function testParserOutputStashForEdit(
		bool $isPerformerRegistered,
		bool $tempAccountsEnabled,
		bool $shouldRunFilters
	): void {
		$user = $this->createMock( User::class );
		$user->method( 'isRegistered' )
			->willReturn( $isPerformerRegistered );

		$title = $this->createNoOpMock( Title::class );

		$page = $this->createMock( WikiPage::class );
		$page->method( 'getTitle' )
			->willReturn( $title );

		$content = $this->createNoOpMock( Content::class );
		$summary = 'test';

		$mockRunner = $this->createMock( FilterRunner::class );
		$mockRunner->expects( $shouldRunFilters ? $this->once() : $this->never() )
			->method( 'runForStash' );
		$filterRunnerFactory = $this->createMock( FilterRunnerFactory::class );
		$filterRunnerFactory->method( 'newRunner' )
			->willReturn( $mockRunner );

		$vars = new VariableHolder();

		$variableGenerator = $this->createMock( RunVariableGenerator::class );
		$variableGenerator->method( 'getStashEditVars' )
			->with( $content, $summary, SlotRecord::MAIN, $page )
			->willReturn( $vars );

		$variableGeneratorFactory = $this->createMock( VariableGeneratorFactory::class );
		$variableGeneratorFactory->method( 'newRunGenerator' )
			->with( $user, $title )
			->willReturn( $variableGenerator );

		$tempUserConfig = $this->createMock( TempUserConfig::class );
		$tempUserConfig->method( 'isEnabled' )
			->willReturn( $tempAccountsEnabled );

		$statsHelper = StatsFactory::newUnitTestingHelper()->withComponent( 'AbuseFilter' );
		$handler = new FilteredActionsHandler(
			$statsHelper->getStatsFactory(),
			$filterRunnerFactory,
			$variableGeneratorFactory,
			$this->createNoOpMock( EditRevUpdater::class ),
			$this->createNoOpMock( BlockedDomainFilter::class ),
			$this->createNoOpMock( PermissionManager::class ),
			$this->createNoOpMock( TitleFactory::class ),
			$this->createNoOpMock( UserFactory::class ),
			$tempUserConfig
		);

		$handler->onParserOutputStashForEdit(
			$page,
			$content,
			new ParserOutput(),
			$summary,
			$user
		);
		DeferredUpdates::doUpdates();

		// count() throws on zero matches; scan samples to also cover the no-timing case.
		$stashTimings = array_filter(
			$statsHelper->getAllFormatted(),
			static fn ( string $sample ) => str_contains( $sample, 'filter_run_duration_seconds' )
		);
		$this->assertCount( $shouldRunFilters ? 1 : 0, $stashTimings );
	}

	public static function provideParserOutputStashForEdit(): iterable {
		yield 'registered performer, temporary accounts not enabled' => [ true, false, true ];
		yield 'unregistered performer, temporary accounts not enabled' => [ false, false, true ];
		yield 'registered performer, temporary accounts enabled' => [ true, true, true ];
		yield 'unregistered performer, temporary accounts enabled' => [ false, true, false ];
	}
}
