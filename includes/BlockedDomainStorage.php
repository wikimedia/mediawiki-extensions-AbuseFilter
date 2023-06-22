<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */
namespace MediaWiki\Extension\AbuseFilter;

use ApiRawMessage;
use BagOStuff;
use DBAccessObjectUtils;
use FormatJson;
use IDBAccessObject;
use JsonContent;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\UserFactory;
use MediaWiki\Utils\UrlUtils;
use Message;
use RecentChange;
use StatusValue;
use TitleValue;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;

/**
 * Holds and updates information on blocked external domains
 */
class BlockedDomainStorage implements IDBAccessObject {
	public const SERVICE_NAME = 'AbuseFilterBlockedDomainStorage';

	public const TARGET_PAGE = 'BlockedExternalDomains.json';

	private RevisionLookup $revisionLookup;
	private BagOStuff $cache;
	private UserFactory $userFactory;
	private WikiPageFactory $wikiPageFactory;
	private UrlUtils $urlUtils;

	/**
	 * @param BagOStuff $cache
	 * @param RevisionLookup $revisionLookup
	 * @param UserFactory $userFactory
	 * @param WikiPageFactory $wikiPageFactory
	 * @param UrlUtils $urlUtils
	 */
	public function __construct(
		BagOStuff $cache,
		RevisionLookup $revisionLookup,
		UserFactory $userFactory,
		WikiPageFactory $wikiPageFactory,
		UrlUtils $urlUtils
	) {
		$this->cache = $cache;
		$this->revisionLookup = $revisionLookup;
		$this->userFactory = $userFactory;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->urlUtils = $urlUtils;
	}

	/**
	 * @return string
	 */
	private function makeCacheKey() {
		return $this->cache->makeKey( 'abusefilter-blocked-domains' );
	}

	/**
	 * Load the configured page, with caching.
	 * @param int $flags bit field, see self::READ_XXX
	 * @return StatusValue The content of the configuration page (as JSON
	 *   data in PHP-native format), or a StatusValue on error.
	 */
	public function load( int $flags = 0 ) {
		if ( DBAccessObjectUtils::hasFlags( $flags, self::READ_LATEST ) ) {
			return $this->fetchConfig( $flags );
		}

		return $this->loadFromCache( $flags );
	}

	public function loadComputed() {
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'abusefilter-blocked-domains-computed' ),
			ExpirationAwareness::TTL_MINUTE * 5,
			function ()  {
				return $this->loadComputedUncached();
			}
		);
	}

	private function loadComputedUncached() {
		$domains = $this->loadFromCache();
		if ( !$domains->isGood() ) {
			return [];
		}
		$domains = $domains->getValue();
		$computedDomains = [];
		foreach ( $domains as $domain ) {
			if ( !isset( $domain['domain'] ) || !$domain['domain'] ) {
				continue;
			}
			$validatedDomain = $this->validateDomain( $domain['domain'] );
			if ( !$validatedDomain ) {
				continue;
			}
			// It should be a map, benchmark at https://phabricator.wikimedia.org/P48956
			$computedDomains[$validatedDomain] = true;
		}
		return $computedDomains;
	}

	/**
	 * Validate if the entered domain is valid or not
	 *
	 * @param string $domain the domain such as foo.wikipedia.org
	 * @return bool|string false if the domain is invalid, the parsed domain otherwise
	 */
	private function validateDomain( $domain ) {
		$domain = trim( $domain ?? '' );
		if ( strpos( $domain, '//' ) === false ) {
			$domain = 'https://' . $domain;
		}

		$parsedUrl = $this->urlUtils->parse( $domain );
		if ( !$parsedUrl ) {
			return false;
		}
		// Parse url returns a valid URL for "foo"
		if ( strpos( $parsedUrl['host'], '.' ) === false ) {
			return false;
		}
		return $parsedUrl['host'];
	}

	/**
	 * Load configuration from the WAN cache
	 *
	 * @param int $flags bit field, see self::READ_XXX
	 * @return StatusValue The content of the configuration page (as JSON
	 *   data in PHP-native format), or a StatusValue on error.
	 */
	private function loadFromCache( int $flags = 0 ) {
		return $this->cache->getWithSetCallback(
			$this->makeCacheKey(),
			ExpirationAwareness::TTL_MINUTE * 5,
			function ( &$ttl ) use ( $flags ) {
				$result = $this->fetchConfig( $flags );
				if ( !$result->isGood() ) {
					// error should not be cached
					$ttl = ExpirationAwareness::TTL_UNCACHEABLE;
				}
				return $result;
			}
		);
	}

	/**
	 * Fetch the contents of the configuration page, without caching.
	 *
	 * Result is not validated with a config validator.
	 *
	 * @param int $flags bit field, see IDBAccessObject::READ_XXX; do NOT pass READ_UNCACHED
	 * @return StatusValue Status object, with the configuration (as JSON data) on success.
	 */
	private function fetchConfig( int $flags ) {
		$revision = $this->revisionLookup->getRevisionByTitle( $this->getBlockedDomainPage(), 0, $flags );
		if ( !$revision ) {
			// The configuration page does not exist. Pretend it does not configure anything
			// specific (failure mode and empty-page behavior is equal).
			return StatusValue::newGood( [] );
		}
		$content = $revision->getContent( SlotRecord::MAIN );
		if ( !$content instanceof JsonContent ) {
			return StatusValue::newFatal( new ApiRawMessage(
				'The configuration title has no content or is not JSON content.',
				'newcomer-tasks-configuration-loader-content-error' ) );
		}

		return FormatJson::parse( $content->getText(), FormatJson::FORCE_ASSOC );
	}

	/**
	 * This doesn't do validation.
	 *
	 * @param string $domain domain to be blocked
	 * @param string $notes User provided notes
	 * @param \MediaWiki\Permissions\Authority|\MediaWiki\User\UserIdentity $user Performer
	 * @return RevisionRecord|null
	 */
	public function addDomain( string $domain, string $notes, $user ): ?RevisionRecord {
		$content = $this->loadConfigContent();
		if ( $content === null ) {
			return null;
		}
		$content[] = [ 'domain' => $domain, 'notes' => $notes ];
		$comment = Message::newFromSpecifier( 'abusefilter-blocked-domains-domain-added-comment' )
			->params( $domain, $notes )
			->plain();
		return $this->saveContent( $content, $user, $comment );
	}

	/**
	 * This doesn't do validation
	 *
	 * @param string $domain domain to be removed from the blocked list
	 * @param string $notes User provided notes
	 * @param \MediaWiki\Permissions\Authority|\MediaWiki\User\UserIdentity $user Performer
	 * @return RevisionRecord|null RevisionRecord on success, StatusValue on failure.
	 */
	public function removeDomain( string $domain, string $notes, $user ): ?RevisionRecord {
		$content = $this->loadConfigContent();
		if ( !$content ) {
			return null;
		}
		foreach ( $content as $key => $value ) {
			if ( ( $value['domain'] ?? '' ) == $domain ) {
				unset( $content[$key] );
			}
		}
		$comment = Message::newFromSpecifier( 'abusefilter-blocked-domains-domain-removed-comment' )
			->params( $domain, $notes )
			->plain();
		return $this->saveContent( array_values( $content ), $user, $comment );
	}

	/**
	 * @return array|null
	 */
	private function loadConfigContent(): ?array {
		$configPage = $this->getBlockedDomainPage();
		$revision = $this->revisionLookup->getRevisionByTitle( $configPage, 0, self::READ_LATEST );
		if ( !$revision ) {
			$content = [];
		} else {
			$revContent = $revision->getContent( SlotRecord::MAIN );
			if ( !$revContent instanceof JsonContent ) {
				return null;
			}
			$status = FormatJson::parse( $revContent->getText(), FormatJson::FORCE_ASSOC );
			if ( !$status->isOK() ) {
				return null;
			}
			$content = $status->getValue();
		}
		return $content;
	}

	/**
	 * Save the provided content into the page
	 *
	 * @param array $content To be turned into JSON
	 * @param \MediaWiki\Permissions\Authority|\MediaWiki\User\UserIdentity $user Performer
	 * @param string $comment Save comment
	 * @return RevisionRecord|null
	 */
	private function saveContent( $content, $user, $comment ) {
		$configPage = $this->getBlockedDomainPage();
		$page = $this->wikiPageFactory->newFromLinkTarget( $configPage );
		$updater = $page->newPageUpdater( $user );
		$updater->setContent( SlotRecord::MAIN, new JsonContent( FormatJson::encode( $content ) ) );

		if ( $this->userFactory->newFromUserIdentity( $user )->isAllowed( 'autopatrol' ) ) {
			$updater->setRcPatrolStatus( RecentChange::PRC_AUTOPATROLLED );
		}

		return $updater->saveRevision(
			CommentStoreComment::newUnsavedComment( $comment )
		);
	}

	/**
	 * @return TitleValue TitleValue of the config json page
	 */
	public function getBlockedDomainPage() {
		return new TitleValue( NS_MEDIAWIKI, self::TARGET_PAGE );
	}
}
