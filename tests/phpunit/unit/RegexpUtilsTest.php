<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use MediaWiki\Extension\AbuseFilter\RegexpUtils;
use MediaWikiUnitTestCase;

/**
 * @group AbuseFilter
 * @covers \MediaWiki\Extension\AbuseFilter\RegexpUtils
 */
class RegexpUtilsTest extends MediaWikiUnitTestCase {

	/**
	 * @param string $rawRegexp
	 * @param bool $caseInsensitive
	 * @param string $expected
	 * @dataProvider provideBuildPattern
	 */
	public function testBuildPattern( string $rawRegexp, bool $caseInsensitive, string $expected ) {
		$this->assertSame( $expected, RegexpUtils::buildPattern( $rawRegexp, $caseInsensitive ) );
	}

	public static function provideBuildPattern() {
		return [
			'plain pattern' => [ 'foobar', false, '/foobar/u' ],
			'case-insensitive modifier' => [ 'foobar', true, '/foobar/ui' ],
			'unescaped slash is escaped' => [ 'foo/bar', false, '/foo\\/bar/u' ],
			'already-escaped slash unchanged' => [ 'foo\\/bar', false, '/foo\\/bar/u' ],
			'one escaped backslash, then unescaped slash' => [ '\\\\/', false, '/\\\\\\//u' ],
			'one escaped backslash, then escaped slash' => [ '\\\\\\/', false, '/\\\\\\//u' ],
			'two escaped backslashes, then unescaped slash' => [ '\\\\\\\\/', false, '/\\\\\\\\\\//u' ],
			'two escaped backslashes, then escaped slash' => [ '\\\\\\\\\\/', false, '/\\\\\\\\\\//u' ],
		];
	}
}
