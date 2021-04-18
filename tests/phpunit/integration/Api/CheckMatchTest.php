<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Api;

use ApiTestCase;
use FormatJson;
use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterCachingParser;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use MediaWiki\Extension\AbuseFilter\Parser\ParserStatus;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Api\CheckMatch
 * @covers ::__construct
 * @group medium
 */
class CheckMatchTest extends ApiTestCase {
	use AbuseFilterApiTestTrait;

	/**
	 * @covers ::execute
	 */
	public function testExecute_noPermissions() {
		$this->setExpectedApiException( 'apierror-abusefilter-canttest', 'permissiondenied' );

		$this->setService( ParserFactory::SERVICE_NAME, $this->getParserFactory() );

		$this->doApiRequest( [
			'action' => 'abusefiltercheckmatch',
			'filter' => 'sampleFilter',
			'vars' => FormatJson::encode( [] ),
		], null, null, self::getTestUser()->getUser() );
	}

	public function provideExecuteOk() {
		return [
			'matched' => [ true ],
			'no match' => [ false ],
		];
	}

	/**
	 * @dataProvider provideExecuteOk
	 * @covers ::execute
	 */
	public function testExecute_Ok( bool $expected ) {
		$filter = 'sampleFilter';
		$checkStatus = new ParserStatus( true, false, null, [] );
		$resultStatus = new ParserStatus( $expected, false, null, [] );
		$parser = $this->createMock( AbuseFilterCachingParser::class );
		$parser->expects( $this->once() )
			->method( 'checkSyntax' )->with( $filter )
			->willReturn( $checkStatus );
		$parser->expects( $this->once() )
			->method( 'checkConditions' )->with( $filter )
			->willReturn( $resultStatus );
		$this->setService( ParserFactory::SERVICE_NAME, $this->getParserFactory( $parser ) );

		$result = $this->doApiRequest( [
			'action' => 'abusefiltercheckmatch',
			'filter' => $filter,
			'vars' => FormatJson::encode( [] ),
		], null, null, self::getTestSysop()->getUser() );

		$this->assertArrayEquals(
			[
				'abusefiltercheckmatch' => [
					'result' => $expected
				]
			],
			$result[0],
			false,
			true
		);
	}

	/**
	 * @covers ::execute
	 */
	public function testExecute_error() {
		$this->setExpectedApiException( 'apierror-abusefilter-badsyntax', 'badsyntax' );
		$filter = 'sampleFilter';
		$status = new ParserStatus( false, false, null, [] );
		$parser = $this->createMock( AbuseFilterCachingParser::class );
		$parser->expects( $this->once() )
			->method( 'checkSyntax' )->with( $filter )
			->willReturn( $status );
		$this->setService( ParserFactory::SERVICE_NAME, $this->getParserFactory( $parser ) );

		$this->doApiRequest( [
			'action' => 'abusefiltercheckmatch',
			'filter' => $filter,
			'vars' => FormatJson::encode( [] ),
		], null, null, self::getTestSysop()->getUser() );
	}

}
