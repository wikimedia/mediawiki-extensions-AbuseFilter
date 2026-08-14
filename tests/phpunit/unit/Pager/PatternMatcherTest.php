<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Pager;

use Generator;
use InvalidArgumentException;
use MediaWiki\Extension\AbuseFilter\Filter\Filter;
use MediaWiki\Extension\AbuseFilter\Pager\PatternMatcher;
use MediaWiki\Extension\AbuseFilter\Pager\SearchMode;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Pager\PatternMatcher
 */
class PatternMatcherTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideConstructorThrows
	 */
	public function testConstructorThrows( string $pattern, int $target ): void {
		$this->expectException( InvalidArgumentException::class );
		new PatternMatcher( $pattern, SearchMode::LIKE, $target );
	}

	public static function provideConstructorThrows(): array {
		return [
			'empty pattern' => [
				'pattern' => '',
				'target' => PatternMatcher::SEARCH_RULES,
			],
			'invalid target' => [
				'pattern' => 'foo',
				'target' => 0,
			],
		];
	}

	/**
	 * @dataProvider provideIsValidRegexPattern
	 */
	public function testIsValidRegexPattern( string $pattern, bool $expected ): void {
		$this->assertSame( $expected, PatternMatcher::isValidRegexPattern( $pattern ) );
	}

	public static function provideIsValidRegexPattern(): array {
		return [
			'valid pattern' => [ 'foo[0-9]+', true ],
			'valid pattern with slash' => [ 'foo/bar', true ],
			'invalid pattern' => [ 'foo[', false ],
			'empty pattern' => [ '', false ],
		];
	}

	/**
	 * @dataProvider provideIsValidSearchTarget
	 */
	public function testIsValidSearchTarget( string|int $target, bool $expected ): void {
		$this->assertSame( $expected, PatternMatcher::isValidSearchTarget( $target ) );
	}

	public static function provideIsValidSearchTarget(): Generator {
		foreach ( [
			'int' => false,
			'string' => true,
		] as $type => $castToString ) {
			foreach ( [
				'SEARCH_RULES' => PatternMatcher::SEARCH_RULES,
				'SEARCH_NOTES' => PatternMatcher::SEARCH_NOTES,
				'SEARCH_ALL' => PatternMatcher::SEARCH_ALL,
			] as $const => $target ) {
				yield "$const as $type" => [
					'target' => $castToString ? (string)$target : $target,
					'expected' => true,
				];
			}
		}

		yield 'negative integer' => [
			'target' => '-1',
			'expected' => false,
		];
		yield 'zero' => [
			'target' => '0',
			'expected' => false,
		];
		yield 'decimal' => [
			'target' => '1.1',
			'expected' => false,
		];
		yield 'unsupported bit' => [
			'target' => '4',
			'expected' => false,
		];
		yield 'non-integer' => [
			'target' => 'foo',
			'expected' => false,
		];
	}

	/**
	 * @dataProvider provideFindMatch
	 */
	public function testFindMatch(
		string $pattern,
		SearchMode $mode,
		string $text,
		?array $expected,
	): void {
		$matcher = new PatternMatcher( $pattern, $mode, PatternMatcher::SEARCH_RULES );
		$this->assertSame( $expected, $matcher->findMatch( $text ) );
	}

	public static function provideFindMatch(): array {
		return [
			'LIKE match' => [
				'pattern' => 'foo',
				'mode' => SearchMode::LIKE,
				'text' => 'xxFoOxx',
				'expected' => [ 'FoO', 2, 3 ],
			],
			'LIKE no match' => [
				'pattern' => 'foo',
				'mode' => SearchMode::LIKE,
				'text' => 'bar',
				'expected' => null,
			],
			'RLIKE match' => [
				'pattern' => 'foo[0-9]+',
				'mode' => SearchMode::RLIKE,
				'text' => 'xxfoo123xx',
				'expected' => [ 'foo123', 2, 6 ],
			],
			'RLIKE is case-sensitive' => [
				'pattern' => 'foo',
				'mode' => SearchMode::RLIKE,
				'text' => 'xxFoOxx',
				'expected' => null,
			],
			'IRLIKE is case-insensitive' => [
				'pattern' => 'foo',
				'mode' => SearchMode::IRLIKE,
				'text' => 'xxFoOxx',
				'expected' => [ 'FoO', 2, 3 ],
			],
			'regex match after multibyte characters' => [
				'pattern' => 'foo',
				'mode' => SearchMode::RLIKE,
				'text' => '＿＿foo',
				'expected' => [ 'foo', 2, 3 ],
			],
			'regex match with multibyte characters' => [
				'pattern' => 'foo',
				'mode' => SearchMode::RLIKE,
				'text' => '＿＿＿foo＿＿',
				'expected' => [ 'foo', 3, 3 ],
			],
			'regex matching the empty string' => [
				'pattern' => 'a*',
				'mode' => SearchMode::RLIKE,
				'text' => 'bbb',
				'expected' => [ '', 0, 0 ],
			],
			'zero-length regex match before a string' => [
				'pattern' => '\b(?=abc)',
				'mode' => SearchMode::RLIKE,
				'text' => 'abc',
				'expected' => [ '', 0, 0 ],
			],
			'zero-length regex match after multibyte characters' => [
				'pattern' => '(?=abc)',
				'mode' => SearchMode::RLIKE,
				'text' => '＿＿abc',
				'expected' => [ '', 2, 0 ],
			],
		];
	}

	/**
	 * @dataProvider provideFindMatchAndHighlight
	 */
	public function testFindMatchAndHighlight(
		string $pattern, SearchMode $mode, int $target,
		string $rules, string $comments,
		int $expectedRulesCalls, int $expectedCommentsCalls,
		?string $expected
	): void {
		$filter = $this->createMock( Filter::class );
		$filter
			->expects( $this->exactly( $expectedRulesCalls ) )
			->method( 'getRules' )
			->willReturn( $rules );
		$filter
			->expects( $this->exactly( $expectedCommentsCalls ) )
			->method( 'getComments' )
			->willReturn( $comments );

		$this->assertSame(
			$expected,
			( new PatternMatcher( $pattern, $mode, $target ) )->findMatchAndHighlight( $filter )
		);
	}

	public static function provideFindMatchAndHighlight(): Generator {
		foreach ( [
			'SEARCH_RULES' => PatternMatcher::SEARCH_RULES,
			'SEARCH_NOTES' => PatternMatcher::SEARCH_NOTES,
			'SEARCH_ALL' => PatternMatcher::SEARCH_ALL,
		] as $const => $target ) {
			yield "$const, match within subject" => [
				'pattern' => 'foo',
				'mode' => SearchMode::LIKE,
				'target' => $target,
				'rules' => 'xxfooxx',
				'comments' => 'xxfooxx',
				'expectedRulesCalls' => (int)(bool)( $target & PatternMatcher::SEARCH_RULES ),
				'expectedCommentsCalls' => (int)( $target === PatternMatcher::SEARCH_NOTES ),
				'expected' => 'xx<b>foo</b>xx',
			];
			yield "$const, long match is truncated" => [
				'pattern' => 'a+b',
				'mode' => SearchMode::RLIKE,
				'target' => $target,
				'rules' => str_repeat( 'a', PatternMatcher::HIGHLIGHT_MAX_LEN ) . 'b',
				'comments' => str_repeat( 'a', PatternMatcher::HIGHLIGHT_MAX_LEN ) . 'b',
				'expectedRulesCalls' => (int)(bool)( $target & PatternMatcher::SEARCH_RULES ),
				'expectedCommentsCalls' => (int)( $target === PatternMatcher::SEARCH_NOTES ),
				'expected' => '<b>' . str_repeat( 'a', PatternMatcher::HIGHLIGHT_MAX_LEN ) . '</b>',
			];
			yield "$const, zero-length match" => [
				'pattern' => '\b(?=abc)',
				'mode' => SearchMode::RLIKE,
				'target' => $target,
				'rules' => 'abc',
				'comments' => 'abc',
				'expectedRulesCalls' => (int)(bool)( $target & PatternMatcher::SEARCH_RULES ),
				'expectedCommentsCalls' => (int)( $target === PatternMatcher::SEARCH_NOTES ),
				'expected' => '<span class="mw-abusefilter-list-matched-zerolength"></span>abc',
			];
			yield "$const, match in a long subject is centered" => [
				'pattern' => 'foo',
				'mode' => SearchMode::LIKE,
				'target' => $target,
				'rules' => str_repeat( 'a', 30 ) . 'foo' . str_repeat( 'b', 30 ),
				'comments' => str_repeat( 'a', 30 ) . 'foo' . str_repeat( 'b', 30 ),
				'expectedRulesCalls' => (int)(bool)( $target & PatternMatcher::SEARCH_RULES ),
				'expectedCommentsCalls' => (int)( $target === PatternMatcher::SEARCH_NOTES ),
				'expected' => str_repeat( 'a', 24 ) . '<b>foo</b>' . str_repeat( 'b', 23 ),
			];
			yield "$const, no match" => [
				'pattern' => 'foo',
				'mode' => SearchMode::LIKE,
				'target' => $target,
				'rules' => 'bar',
				'comments' => 'bar',
				'expectedRulesCalls' => (int)(bool)( $target & PatternMatcher::SEARCH_RULES ),
				'expectedCommentsCalls' => (int)(bool)( $target & PatternMatcher::SEARCH_NOTES ),
				'expected' => null,
			];
		}

		yield 'SEARCH_ALL prefers rules' => [
			'pattern' => 'foo',
			'mode' => SearchMode::LIKE,
			'target' => PatternMatcher::SEARCH_ALL,
			'rules' => 'xxfooxx',
			'comments' => 'xxfooxx',
			'expectedRulesCalls' => 1,
			'expectedCommentsCalls' => 0,
			'expected' => 'xx<b>foo</b>xx',
		];
		yield 'SEARCH_ALL falls back to notes' => [
			'pattern' => 'foo',
			'mode' => SearchMode::LIKE,
			'target' => PatternMatcher::SEARCH_ALL,
			'rules' => 'bar',
			'comments' => 'xxfooxx',
			'expectedRulesCalls' => 1,
			'expectedCommentsCalls' => 1,
			'expected' => 'xx<b>foo</b>xx',
		];
	}
}
