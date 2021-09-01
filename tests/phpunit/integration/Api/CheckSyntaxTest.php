<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Api;

use ApiTestCase;
use MediaWiki\Extension\AbuseFilter\Parser\Exception\UserVisibleException;
use MediaWiki\Extension\AbuseFilter\Parser\Exception\UserVisibleWarning;
use MediaWiki\Extension\AbuseFilter\Parser\FilterEvaluator;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use MediaWiki\Extension\AbuseFilter\Parser\ParserStatus;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Api\CheckSyntax
 * @covers ::__construct
 * @group medium
 */
class CheckSyntaxTest extends ApiTestCase {
	use AbuseFilterApiTestTrait;

	/**
	 * @covers ::execute
	 */
	public function testExecute_noPermissions() {
		$this->setExpectedApiException( 'apierror-abusefilter-cantcheck', 'permissiondenied' );

		$this->setService( ParserFactory::SERVICE_NAME, $this->getParserFactory() );

		$this->doApiRequest( [
			'action' => 'abusefilterchecksyntax',
			'filter' => 'sampleFilter',
		], null, null, self::getTestUser()->getUser() );
	}

	/**
	 * @covers ::execute
	 */
	public function testExecute_Ok() {
		$input = 'sampleFilter';
		$status = new ParserStatus( true, false, null, [], 1 );
		$parser = $this->createMock( FilterEvaluator::class );
		$parser->method( 'checkSyntax' )->with( $input )
			->willReturn( $status );
		$this->setService( ParserFactory::SERVICE_NAME, $this->getParserFactory( $parser ) );

		$result = $this->doApiRequest( [
			'action' => 'abusefilterchecksyntax',
			'filter' => $input,
		], null, null, self::getTestSysop()->getUser() );

		$this->assertArrayEquals(
			[ 'abusefilterchecksyntax' => [ 'status' => 'ok' ] ],
			$result[0],
			false,
			true
		);
	}

	/**
	 * @covers ::execute
	 */
	public function testExecute_OkAndWarnings() {
		$input = 'sampleFilter';
		$warnings = [
			new UserVisibleWarning( 'exception-1', 3, [] ),
			new UserVisibleWarning( 'exception-2', 8, [ 'param' ] ),
		];
		$status = new ParserStatus( true, false, null, $warnings, 1 );
		$parser = $this->createMock( FilterEvaluator::class );
		$parser->method( 'checkSyntax' )->with( $input )
			->willReturn( $status );
		$this->setService( ParserFactory::SERVICE_NAME, $this->getParserFactory( $parser ) );

		$result = $this->doApiRequest( [
			'action' => 'abusefilterchecksyntax',
			'filter' => $input,
		], null, null, self::getTestSysop()->getUser() );

		$this->assertArrayEquals(
			[
				'abusefilterchecksyntax' => [
					'status' => 'ok',
					'warnings' => [
						[
							'message' => wfMessage(
								'abusefilter-parser-warning-exception-1',
								3
							)->text(),
							'character' => 3,
						],
						[
							'message' => wfMessage(
								'abusefilter-parser-warning-exception-2',
								8,
								'param'
							)->text(),
							'character' => 8,
						],
					]
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
		$input = 'sampleFilter';
		$exception = new UserVisibleException( 'error-id', 4, [] );
		$status = new ParserStatus( false, false, $exception, [], 1 );
		$parser = $this->createMock( FilterEvaluator::class );
		$parser->method( 'checkSyntax' )->with( $input )
			->willReturn( $status );
		$this->setService( ParserFactory::SERVICE_NAME, $this->getParserFactory( $parser ) );

		$result = $this->doApiRequest( [
			'action' => 'abusefilterchecksyntax',
			'filter' => $input,
		], null, null, self::getTestSysop()->getUser() );

		$this->assertArrayEquals(
			[
				'abusefilterchecksyntax' => [
					'status' => 'error',
					'message' => wfMessage(
						'abusefilter-exception-error-id',
						4
					)->text(),
					'character' => 4
				]
			],
			$result[0],
			false,
			true
		);
	}
}
