<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Api;

use ApiTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Api\QueryAbuseLog
 * @group medium
 * @group Database
 * @todo Extend this
 */
class QueryAbuseLogTest extends ApiTestCase {

	public function testConstruct() {
		$this->doApiRequest( [
			'action' => 'query',
			'list' => 'abuselog',
		] );
		$this->addToAssertionCount( 1 );
	}

}
