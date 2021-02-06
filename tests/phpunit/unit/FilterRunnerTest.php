<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use IBufferingStatsdDataFactory;
use InvalidArgumentException;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\AbuseLoggerFactory;
use MediaWiki\Extension\AbuseFilter\ChangeTags\ChangeTagger;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesExecutorFactory;
use MediaWiki\Extension\AbuseFilter\EditStashCache;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\FilterProfiler;
use MediaWiki\Extension\AbuseFilter\FilterRunner;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterParser;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGeneratorFactory;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;
use Title;
use User;

/**
 * @group Test
 * @group AbuseFilter
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\FilterRunner
 * @covers ::__construct
 */
class FilterRunnerTest extends MediaWikiUnitTestCase {
	/**
	 * @param ParserFactory|null $parserFactory
	 * @param ChangeTagger|null $changeTagger
	 * @param array $options
	 * @param VariableHolder|null $vars
	 * @param string $group
	 * @return FilterRunner
	 */
	private function getRunner(
		ParserFactory $parserFactory = null,
		ChangeTagger $changeTagger = null,
		$options = [],
		VariableHolder $vars = null,
		$group = 'default'
	) : FilterRunner {
		$opts = new ServiceOptions(
			FilterRunner::CONSTRUCTOR_OPTIONS,
			$options + [
				'AbuseFilterValidGroups' => [ 'default' ],
				'AbuseFilterCentralDB' => false,
				'AbuseFilterIsCentral' => false,
				'AbuseFilterConditionLimit' => 1000,
			]
		);
		return new FilterRunner(
			new AbuseFilterHookRunner( $this->createHookContainer() ),
			$this->createMock( FilterProfiler::class ),
			$changeTagger ?? $this->createMock( ChangeTagger::class ),
			$this->createMock( FilterLookup::class ),
			$parserFactory ?? $this->createMock( ParserFactory::class ),
			$this->createMock( ConsequencesExecutorFactory::class ),
			$this->createMock( AbuseLoggerFactory::class ),
			$this->createMock( VariablesManager::class ),
			$this->createMock( VariableGeneratorFactory::class ),
			[],
			$this->createMock( EditStashCache::class ),
			new NullLogger(),
			$this->createMock( IBufferingStatsdDataFactory::class ),
			$opts,
			$this->createMock( User::class ),
			$this->createMock( Title::class ),
			// Temporary hack: don't use action=edit so we won't check the cache (temporary until a cache is injected,
			// or a service wrapping the caching code is created)
			$vars ?? VariableHolder::newFromArray( [ 'action' => 'move' ] ),
			$group
		);
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor_invalidGroup() {
		$invalidGroup = 'invalid-group';
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $invalidGroup );
		$this->getRunner( null, null, [], new VariableHolder(), $invalidGroup );
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor_noAction() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'variable is not set' );
		$this->getRunner( null, null, [], new VariableHolder() );
	}

	/**
	 * @covers ::run
	 * @covers ::checkAllFilters
	 */
	public function testConditionsLimit() {
		$parser = $this->createMock( AbuseFilterParser::class );
		$parser->expects( $this->atLeastOnce() )->method( 'getCondCount' )->willReturn( 1e6 );
		$parserFactory = $this->createMock( ParserFactory::class );
		$parserFactory->method( 'newParser' )->willReturn( $parser );
		$changeTagger = $this->createMock( ChangeTagger::class );
		$changeTagger->expects( $this->once() )->method( 'addConditionsLimitTag' );
		$runner = $this->getRunner( $parserFactory, $changeTagger );
		$this->assertTrue( $runner->run()->isGood() );
	}
}
