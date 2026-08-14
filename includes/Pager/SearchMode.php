<?php

namespace MediaWiki\Extension\AbuseFilter\Pager;

/**
 * @internal
 */
enum SearchMode: string {
	case LIKE = 'LIKE';
	case RLIKE = 'RLIKE';
	case IRLIKE = 'IRLIKE';

	public function requiresRegexMatching(): bool {
		return $this !== self::LIKE;
	}

	public function isCaseInsensitive(): bool {
		return $this === self::IRLIKE;
	}
}
