<?php

namespace MediaWiki\Extension\AbuseFilter\BlockedDomains;

use MediaWiki\Content\JsonContent;
use MediaWiki\Extension\AbuseFilter\ServiceNames;
use MediaWiki\Json\FormatJson;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * Loads the local allow list of external domains and URLs, stored in
 * MediaWiki:BlockedExternalDomainsAllowList.json. Links matching an entry
 * bypass both the local and the global lists of blocked external domains.
 *
 * Each entry is an object with a 'domain' and a 'notes' field. The 'domain'
 * is either a domain (such as foo.example.org, which also allows its subdomains)
 * or a full URL (such as https://example.org/page, which only allows that URL).
 */
class BlockedDomainAllowListLookup {

	public const SERVICE_NAME = ServiceNames::BlockedDomainAllowListLookup;

	public const TARGET_PAGE = 'BlockedExternalDomainsAllowList.json';

	public function __construct(
		private readonly BagOStuff $cache,
		private readonly RevisionLookup $revisionLookup,
		private readonly BlockedDomainValidator $domainValidator
	) {
	}

	/**
	 * Load the computed allow list
	 *
	 * @return array{domains:array<string,true>,urls:array<string,true>} Flipped for
	 *   performance reasons; URLs are normalized with BlockedDomainValidator::normalizeUrl()
	 */
	public function loadComputed(): array {
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'abusefilter-blockeddomains-allowlist-computed' ),
			BagOStuff::TTL_MINUTE * 5,
			function () {
				$computed = [ 'domains' => [], 'urls' => [] ];
				foreach ( $this->fetchConfig() as $entry ) {
					if ( !is_array( $entry ) || !is_string( $entry['domain'] ?? null ) ) {
						continue;
					}
					if ( str_contains( $entry['domain'], '//' ) ) {
						$url = $this->domainValidator->normalizeUrl( $entry['domain'] );
						if ( $url ) {
							$computed['urls'][$url] = true;
						}
					} else {
						$domain = $this->domainValidator->validateDomain( $entry['domain'] );
						if ( $domain ) {
							$computed['domains'][strtolower( $domain )] = true;
						}
					}
				}
				return $computed;
			}
		);
	}

	/**
	 * Normalize an added URL for lookup in the 'urls' part of loadComputed()
	 *
	 * @param string $url
	 * @return string|false
	 */
	public function normalizeUrl( string $url ) {
		return $this->domainValidator->normalizeUrl( $url );
	}

	/**
	 * Fetch the contents of the allow list page
	 *
	 * @return array Parsed JSON, or an empty array if the page is missing or invalid
	 */
	private function fetchConfig(): array {
		$revision = $this->revisionLookup->getRevisionByTitle(
			new PageReferenceValue( NS_MEDIAWIKI, self::TARGET_PAGE, PageReference::LOCAL )
		);
		if ( !$revision ) {
			return [];
		}
		$content = $revision->getContent( SlotRecord::MAIN );
		if ( !$content instanceof JsonContent ) {
			return [];
		}
		$status = FormatJson::parse( $content->getText(), FormatJson::FORCE_ASSOC );
		if ( !$status->isGood() || !is_array( $status->getValue() ) ) {
			return [];
		}
		return $status->getValue();
	}
}
