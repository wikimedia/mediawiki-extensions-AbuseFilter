<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Api;

use MediaWiki\Extension\AbuseFilter\Parser\FilterEvaluator;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;

/**
 * This trait contains helper methods for Api integration tests.
 */
trait AbuseFilterApiTestTrait {

	/**
	 * @param FilterEvaluator|null $parser
	 * @return ParserFactory
	 */
	protected function getParserFactory( FilterEvaluator $parser = null ): ParserFactory {
		$factory = $this->createMock( ParserFactory::class );
		if ( $parser !== null ) {
			$factory->expects( $this->atLeastOnce() )
				->method( 'newParser' )
				->willReturn( $parser );
		} else {
			$factory->expects( $this->never() )->method( 'newParser' );
		}
		return $factory;
	}
}
