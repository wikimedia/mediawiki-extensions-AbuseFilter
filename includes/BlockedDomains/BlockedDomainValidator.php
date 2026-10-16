<?php

namespace MediaWiki\Extension\AbuseFilter\BlockedDomains;

use MediaWiki\Extension\AbuseFilter\ServiceNames;
use MediaWiki\Utils\UrlUtils;

class BlockedDomainValidator {

	public const SERVICE_NAME = ServiceNames::BlockedDomainValidator;

	public function __construct( private readonly UrlUtils $urlUtils ) {
	}

	/**
	 * Validate an input domain
	 *
	 * @param string|null $domain Domain such as foo.wikipedia.org
	 * @return string|false Parsed domain, or false otherwise
	 */
	public function validateDomain( ?string $domain ) {
		if ( !$domain ) {
			return false;
		}

		$domain = trim( $domain );
		if ( !str_contains( $domain, '//' ) ) {
			$domain = 'https://' . $domain;
		}

		$parsedUrl = $this->urlUtils->parse( $domain );
		// Parse url returns a valid URL for "foo"
		if ( !$parsedUrl || !str_contains( $parsedUrl['host'], '.' ) ) {
			return false;
		}
		return $parsedUrl['host'];
	}

	/**
	 * Normalize a full URL so that it can be compared against other URLs.
	 *
	 * The scheme and fragment are ignored, the host is lowercased and an empty path is
	 * treated as "/", so https://Example.org and http://example.org/#foo are equal.
	 *
	 * @param string|null $url URL such as https://foo.wikipedia.org/wiki/Bar
	 * @return string|false Normalized URL, or false if it is not a valid URL with a host
	 */
	public function normalizeUrl( ?string $url ) {
		if ( !$url ) {
			return false;
		}

		$parsedUrl = $this->urlUtils->parse( trim( $url ) );
		if ( !$parsedUrl || !str_contains( $parsedUrl['host'], '.' ) ) {
			return false;
		}

		$normalized = strtolower( $parsedUrl['host'] );
		if ( isset( $parsedUrl['port'] ) ) {
			$normalized .= ':' . $parsedUrl['port'];
		}
		$normalized .= ( $parsedUrl['path'] ?? '' ) ?: '/';
		if ( ( $parsedUrl['query'] ?? '' ) !== '' ) {
			$normalized .= '?' . $parsedUrl['query'];
		}
		return $normalized;
	}
}
