<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Pager;

use MediaWiki\Extension\AbuseFilter\Pager\SearchMode;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Pager\SearchMode
 */
class SearchModeTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideRequiresRegexMatching
	 */
	public function testRequiresRegexMatching( SearchMode $mode, bool $expected ): void {
		$this->assertSame( $expected, $mode->requiresRegexMatching() );
	}

	public static function provideRequiresRegexMatching(): array {
		return [
			'LIKE' => [ SearchMode::LIKE, false ],
			'RLIKE' => [ SearchMode::RLIKE, true ],
			'IRLIKE' => [ SearchMode::IRLIKE, true ],
		];
	}

	/**
	 * @dataProvider provideIsCaseInsensitive
	 */
	public function testIsCaseInsensitive( SearchMode $mode, bool $expected ): void {
		$this->assertSame( $expected, $mode->isCaseInsensitive() );
	}

	public static function provideIsCaseInsensitive(): array {
		return [
			'LIKE' => [ SearchMode::LIKE, false ],
			'RLIKE' => [ SearchMode::RLIKE, false ],
			'IRLIKE' => [ SearchMode::IRLIKE, true ],
		];
	}
}
