<?php

namespace MediaWiki\Extension\AbuseFilter;

/**
 * @internal
 */
class RegexpUtils {
	/**
	 * Given a raw regexp, make it PCRE-compliant
	 * (escape slashes, add delimiters and modifiers).
	 *
	 * @param string $rawRegexp
	 * @param bool $caseInsensitive
	 * @return string
	 */
	public static function buildPattern( string $rawRegexp, bool $caseInsensitive = false ): string {
		$needle = preg_replace( '!((\\\\\\\\)*)(\\\\)?/!', '$1\/', $rawRegexp );

		return "/$needle/u" . ( $caseInsensitive ? 'i' : '' );
	}
}
