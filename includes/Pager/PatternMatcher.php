<?php

namespace MediaWiki\Extension\AbuseFilter\Pager;

use InvalidArgumentException;
use MediaWiki\Extension\AbuseFilter\Filter\AbstractFilter;
use MediaWiki\Extension\AbuseFilter\RegexpUtils;
use MediaWiki\Html\Html;
use Wikimedia\StringUtils\StringUtils;

/**
 * @internal
 */
class PatternMatcher {

	public const SEARCH_RULES = 1 << 0;
	public const SEARCH_NOTES = 1 << 1;
	public const SEARCH_ALL = self::SEARCH_RULES | self::SEARCH_NOTES;

	public const HIGHLIGHT_MAX_LEN = 50;

	private readonly ?string $regex;

	/**
	 * @param string $pattern The pattern to search for. For `RLIKE` and `IRLIKE`, the pattern must
	 * already have been validated as a valid PCRE pattern.
	 * @param SearchMode $mode The search mode for the `$pattern`.
	 * @param int $target The target to search the pattern in: One of the SEARCH_* constants.
	 * @throws InvalidArgumentException If the pattern is empty or the search target is invalid.
	 * @see \Wikimedia\StringUtils\StringUtils::isValidPCRERegex
	 */
	public function __construct( private readonly string $pattern, SearchMode $mode, private readonly int $target ) {
		if ( $pattern === '' ) {
			throw new InvalidArgumentException( '$pattern must not be an empty string' );
		}
		if ( !self::isValidSearchTarget( $target ) ) {
			throw new InvalidArgumentException( "Invalid search target $target" );
		}

		$this->regex = $mode->requiresRegexMatching()
			? RegexpUtils::buildPattern( $pattern, $mode->isCaseInsensitive() )
			: null;
	}

	/**
	 * Checks whether the given pattern is non-empty and valid as a regular expression.
	 */
	public static function isValidRegexPattern( string $pattern ): bool {
		if ( $pattern === '' ) {
			return false;
		}
		// The case-insensitivity modifier does not affect regex validity
		return StringUtils::isValidPCRERegex( RegexpUtils::buildPattern( $pattern ) );
	}

	/**
	 * Checks whether the given search target is valid and supported.
	 */
	public static function isValidSearchTarget( string|int $target ): bool {
		if ( is_string( $target ) && ctype_digit( $target ) ) {
			// Cast to int if $target is an int-representing string
			$target = (int)$target;
		}
		if ( is_int( $target ) ) {
			// At least one supported bit is set AND no unsupported bits are set
			return ( $target & self::SEARCH_ALL ) && ( $target & ~self::SEARCH_ALL ) === 0;
		}
		return false;
	}

	/**
	 * Finds the first match of the pattern in the given text.
	 *
	 * @return array{0:string,1:int,2:int}|null `[ $matchedText, $position, $length ]`,
	 * or `null` if no match is found
	 */
	public function findMatch( string $text ): ?array {
		if ( $this->regex !== null ) {
			return $this->checkMatches( $text );
		} else {
			return $this->checkInclusion( $text );
		}
	}

	/**
	 * @return array{0:string,1:int,2:int}|null `[ $matchedText, $position, $length ]`,
	 * or `null` if no match is found
	 */
	private function checkInclusion( string $text ): ?array {
		$position = mb_stripos( $text, $this->pattern );
		if ( $position === false ) {
			return null;
		}
		$length = mb_strlen( $this->pattern );
		$matchedText = mb_substr( $text, $position, $length );
		return [ $matchedText, $position, $length ];
	}

	/**
	 * @return array{0:string,1:int,2:int}|null `[ $matchedText, $position, $length ]`,
	 * or `null` if no match is found
	 */
	private function checkMatches( string $text ): ?array {
		/** @var array{0:array{0:string,1:int}} $matches */
		$matches = [];
		$check = preg_match(
			$this->regex,
			$text,
			$matches,
			PREG_OFFSET_CAPTURE
		);
		if ( !$check ) {
			return null;
		}

		[ $matchedText, $byteOffset ] = $matches[0];
		$position = mb_strlen( substr( $text, 0, $byteOffset ) );
		$length = mb_strlen( $matchedText );
		return [ $matchedText, $position, $length ];
	}

	/**
	 * Finds the first match of the pattern in a field of the given filter and
	 * highlights it.
	 *
	 * The returned text is limited to `::HIGHLIGHT_MAX_LEN` characters, with the
	 * matched text wrapped in `<b>` tags and surrounding context included
	 * where possible.
	 *
	 * @return string|null The highlighted text, or `null` if no match is found
	 */
	public function findMatchAndHighlight( AbstractFilter $filter ): ?string {
		foreach ( [
			self::SEARCH_RULES => $filter->getRules( ... ),
			self::SEARCH_NOTES => $filter->getComments( ... ),
		] as $target => $getText ) {
			if ( !( $this->target & $target ) ) {
				continue;
			}
			$haystack = $getText();
			$match = $this->findMatch( $haystack );
			if ( $match !== null ) {
				return self::highlightMatchedText( $haystack, $match );
			}
		}
		return null;
	}

	/**
	 * @param string $text
	 * @param array{0:string,1:int,2:int} $match `[ $matchedText, $position, $length ]`
	 * @return string
	 */
	private static function highlightMatchedText( string $text, array $match ): string {
		[ , $position, $length ] = $match;
		$contextLength = self::HIGHLIGHT_MAX_LEN - $length;

		if ( $contextLength <= 0 ) {
			return Html::element( 'b', [], mb_substr( $text, $position, self::HIGHLIGHT_MAX_LEN ) );
		}

		// Center the snippet on the matched string
		$start = max( $position - (int)round( $contextLength / 2 ), 0 );
		$matchStart = $position - $start;
		$snippet = mb_substr( $text, $start, self::HIGHLIGHT_MAX_LEN );

		$leading = htmlspecialchars( mb_substr( $snippet, 0, $matchStart ) );
		$trailing = htmlspecialchars( mb_substr( $snippet, $matchStart + $length ) );

		if ( $length === 0 ) {
			// There is no text to highlight, so use a visual marker for the match
			$highlighted = Html::element( 'span', [ 'class' => [ 'mw-abusefilter-list-matched-zerolength' ] ] );
		} else {
			$highlighted = Html::element( 'b', [], mb_substr( $snippet, $matchStart, $length ) );
		}

		return $leading . $highlighted . $trailing;
	}
}
