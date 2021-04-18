<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Api;

use ApiTestCase;
use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterCachingParser;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use MediaWiki\Extension\AbuseFilter\Parser\ParserStatus;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Api\EvalExpression
 * @covers ::__construct
 * @group medium
 */
class EvalExpressionTest extends ApiTestCase {
	use AbuseFilterApiTestTrait;

	/**
	 * @covers ::execute
	 */
	public function testExecute_noPermissions() {
		$this->setExpectedApiException( 'apierror-abusefilter-canteval', 'permissiondenied' );

		$this->setService( ParserFactory::SERVICE_NAME, $this->getParserFactory() );

		$this->doApiRequest( [
			'action' => 'abusefilterevalexpression',
			'expression' => 'sampleExpression',
		], null, null, self::getTestUser()->getUser() );
	}

	/**
	 * @covers ::execute
	 * @covers ::evaluateExpression
	 */
	public function testExecute_error() {
		$this->setExpectedApiException( 'abusefilter-tools-syntax-error' );
		$expression = 'sampleExpression';
		$status = new ParserStatus( false, false, null, [] );
		$parser = $this->createMock( AbuseFilterCachingParser::class );
		$parser->method( 'checkSyntax' )->with( $expression )
			->willReturn( $status );
		$this->setService( ParserFactory::SERVICE_NAME, $this->getParserFactory( $parser ) );

		$this->doApiRequest( [
			'action' => 'abusefilterevalexpression',
			'expression' => $expression,
		], null, null, self::getTestSysop()->getUser() );
	}

	/**
	 * @covers ::execute
	 * @covers ::evaluateExpression
	 */
	public function testExecute_Ok() {
		$expression = 'sampleExpression';
		$status = new ParserStatus( true, false, null, [] );
		$parser = $this->createMock( AbuseFilterCachingParser::class );
		$parser->method( 'checkSyntax' )->with( $expression )
			->willReturn( $status );
		$parser->expects( $this->once() )->method( 'evaluateExpression' )
			->willReturn( 'output' );
		$this->setService( ParserFactory::SERVICE_NAME, $this->getParserFactory( $parser ) );

		$result = $this->doApiRequest( [
			'action' => 'abusefilterevalexpression',
			'expression' => $expression,
			'prettyprint' => false,
		], null, null, self::getTestSysop()->getUser() );

		$this->assertArrayEquals(
			[
				'abusefilterevalexpression' => [
					'result' => "'output'"
				]
			],
			$result[0],
			false,
			true
		);
	}

	/**
	 * @covers ::execute
	 * @covers ::evaluateExpression
	 */
	public function testExecute_OkAndPrettyPrint() {
		$expression = 'sampleExpression';
		$status = new ParserStatus( true, false, null, [] );
		$parser = $this->createMock( AbuseFilterCachingParser::class );
		$parser->method( 'checkSyntax' )->with( $expression )
			->willReturn( $status );
		$parser->expects( $this->once() )->method( 'evaluateExpression' )
			->willReturn( [ 'value1', 2 ] );
		$this->setService( ParserFactory::SERVICE_NAME, $this->getParserFactory( $parser ) );

		$result = $this->doApiRequest( [
			'action' => 'abusefilterevalexpression',
			'expression' => $expression,
			'prettyprint' => true,
		], null, null, self::getTestSysop()->getUser() );

		$this->assertArrayEquals(
			[
				'abusefilterevalexpression' => [
					'result' => "[\n\t0 => 'value1',\n\t1 => 2\n]"
				]
			],
			$result[0],
			false,
			true
		);
	}
}
