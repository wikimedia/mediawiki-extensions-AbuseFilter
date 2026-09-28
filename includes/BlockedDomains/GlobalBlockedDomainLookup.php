<?php

namespace MediaWiki\Extension\AbuseFilter\BlockedDomains;

use MediaWiki\Content\JsonContent;
use MediaWiki\Extension\AbuseFilter\ServiceNames;
use MediaWiki\Json\FormatJson;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Revision\RevisionStoreFactory;
use MediaWiki\Revision\SlotRecord;
use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\DBError;

/**
 * Loads the global list of blocked external domains, stored in
 * MediaWiki:BlockedExternalDomains.json on a central wiki
 * (see $wgAbuseFilterGlobalBlockedExternalDomainsDB).
 */
class GlobalBlockedDomainLookup {

	public const SERVICE_NAME = ServiceNames::GlobalBlockedDomainLookup;

	/**
	 * @param BagOStuff $cache
	 * @param RevisionStoreFactory $revisionStoreFactory
	 * @param BlockedDomainValidator $domainValidator
	 * @param LoggerInterface $logger
	 * @param string|false $centralWiki DB domain of the central wiki, or false if the
	 *   global list is disabled (or if the current wiki is the central wiki)
	 */
	public function __construct(
		private readonly BagOStuff $cache,
		private readonly RevisionStoreFactory $revisionStoreFactory,
		private readonly BlockedDomainValidator $domainValidator,
		private readonly LoggerInterface $logger,
		private readonly string|false $centralWiki
	) {
	}

	/**
	 * Load the computed global domain blocklist
	 *
	 * @return array<string,true> Flipped for performance reasons
	 */
	public function loadComputed(): array {
		if ( $this->centralWiki === false ) {
			return [];
		}
		return $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey( 'abusefilter-blockeddomains-global-computed', $this->centralWiki ),
			BagOStuff::TTL_MINUTE * 5,
			function ( &$ttl ) {
				try {
					$config = $this->fetchConfig();
				} catch ( DBError $e ) {
					$this->logger->warning(
						'Failed to load global blocked domains from {wiki}: {error}',
						[ 'wiki' => $this->centralWiki, 'error' => $e->getMessage(), 'exception' => $e ]
					);
					// Do not hammer an unavailable DB, but retry soon
					$ttl = BagOStuff::TTL_MINUTE;
					return [];
				}

				$computedDomains = [];
				foreach ( $config as $domain ) {
					if ( !is_array( $domain ) || !is_string( $domain['domain'] ?? null ) ) {
						continue;
					}
					$validatedDomain = $this->domainValidator->validateDomain( $domain['domain'] );
					if ( $validatedDomain ) {
						// It should be a map, benchmark at https://phabricator.wikimedia.org/P48956
						$computedDomains[$validatedDomain] = true;
					}
				}
				return $computedDomains;
			}
		);
	}

	/**
	 * Fetch the contents of the configuration page on the central wiki
	 *
	 * @return array Parsed JSON, or an empty array if the page is missing or invalid
	 * @throws DBError
	 */
	private function fetchConfig(): array {
		$revision = $this->revisionStoreFactory->getRevisionStore( $this->centralWiki )->getRevisionByTitle(
			new PageReferenceValue( NS_MEDIAWIKI, CustomBlockedDomainStorage::TARGET_PAGE, $this->centralWiki )
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
			$this->logger->warning(
				'Invalid global blocked domains JSON on {wiki}',
				[ 'wiki' => $this->centralWiki ]
			);
			return [];
		}
		return $status->getValue();
	}
}
