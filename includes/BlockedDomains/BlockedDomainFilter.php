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
 */
namespace MediaWiki\Extension\AbuseFilter\BlockedDomains;

use MediaWiki\CheckUser\Services\CheckUserInsert;
use MediaWiki\Extension\AbuseFilter\Variables\UnsetVariableException;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Logging\LogPage;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Page\PageReference;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Status\Status;
use MediaWiki\User\UserIdentity;

/**
 * Filters blocked domains
 *
 * @ingroup SpecialPage
 */
class BlockedDomainFilter implements IBlockedDomainFilter {
	public function __construct(
		private readonly VariablesManager $variablesManager,
		private readonly IBlockedDomainStorage $blockedDomainStorage,
		private readonly ?GlobalBlockedDomainLookup $globalBlockedDomainLookup = null,
		private readonly ?BlockedDomainAllowListLookup $allowListLookup = null
	) {
	}

	/** @inheritDoc */
	public function filter( VariableHolder $vars, UserIdentity $user, PageReference $title ): Status {
		// whether the feature is enabled is checked in ServiceWiring

		$status = Status::newGood();
		try {
			$urls = $this->variablesManager->getVar( $vars, 'added_links', VariablesManager::GET_STRICT );
		} catch ( UnsetVariableException ) {
			return $status;
		}

		$addedUrls = [];
		$addedDomains = [];
		foreach ( $urls->toArray() as $addedUrl ) {
			$addedUrl = (string)$addedUrl->getData();
			$parsedHost = parse_url( $addedUrl, PHP_URL_HOST );
			if ( !is_string( $parsedHost ) ) {
				continue;
			}
			$parsedHost = strtolower( $parsedHost );
			$urlDomains = [ $parsedHost => true ];
			// Given that we block subdomains of blocked domains too
			// pretend that all the higher-level domains are added as well
			// so for foo.bar.com, you will have three domains to check:
			// foo.bar.com, bar.com, and com
			// This saves string search in the large list of blocked domains
			// making it much faster.
			$pos = 0;
			// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			while ( ( $pos = strpos( $parsedHost, '.', $pos ) ) !== false ) {
				// Skip the dot we just found
				$pos++;
				// It should be a map, benchmark at https://phabricator.wikimedia.org/P48956
				$urlDomains[ substr( $parsedHost, $pos ) ] = true;
			}
			$addedUrls[$addedUrl] = $urlDomains;
			$addedDomains += $urlDomains;
		}
		if ( !$addedDomains ) {
			return $status;
		}
		$blockedDomains = $this->blockedDomainStorage->loadComputed();
		if ( $this->globalBlockedDomainLookup ) {
			$blockedDomains += $this->globalBlockedDomainLookup->loadComputed();
		}
		$blockedDomainsAdded = array_intersect_key( $addedDomains, $blockedDomains );
		if ( $blockedDomainsAdded && $this->allowListLookup ) {
			$blockedDomainsAdded = $this->removeAllowed( $addedUrls, $blockedDomains );
		}
		if ( !$blockedDomainsAdded ) {
			return $status;
		}
		$blockedDomainsAdded = array_keys( $blockedDomainsAdded );
		$error = Message::newFromSpecifier( 'abusefilter-blocked-domains-attempted' );
		$error->params( Message::listParam( $blockedDomainsAdded ) );
		$status->fatal( $error );
		$this->logFilterHit(
			$user,
			$title,
			implode( ' ', $blockedDomainsAdded )
		);
		return $status;
	}

	/**
	 * Compute the blocked domains that were added, ignoring URLs that are exempt
	 * because the exact URL or its domain (or a higher-level domain) is allow-listed
	 *
	 * @param array<string,array<string,true>> $addedUrls Map of added URLs to their
	 *   domain and higher-level domains
	 * @param array<string,true> $blockedDomains
	 * @return array<string,true> Blocked domains that were added
	 */
	private function removeAllowed( array $addedUrls, array $blockedDomains ): array {
		$allowList = $this->allowListLookup->loadComputed();
		$blockedDomainsAdded = [];
		foreach ( $addedUrls as $url => $urlDomains ) {
			$urlBlockedDomains = array_intersect_key( $urlDomains, $blockedDomains );
			if ( !$urlBlockedDomains || array_intersect_key( $urlDomains, $allowList['domains'] ) ) {
				continue;
			}
			if ( $allowList['urls'] ) {
				$normalizedUrl = $this->allowListLookup->normalizeUrl( (string)$url );
				if ( $normalizedUrl && isset( $allowList['urls'][$normalizedUrl] ) ) {
					continue;
				}
			}
			$blockedDomainsAdded += $urlBlockedDomains;
		}
		return $blockedDomainsAdded;
	}

	/**
	 * Logs the filter hit to Special:Log
	 *
	 * @param UserIdentity $user
	 * @param PageReference $title
	 * @param string $blockedDomain The blocked domain the user attempted to add
	 */
	private function logFilterHit( UserIdentity $user, PageReference $title, string $blockedDomain ) {
		$logEntry = new ManualLogEntry( 'abusefilterblockeddomainhit', 'hit' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $title );
		$logEntry->setParameters( [ '4::blocked' => $blockedDomain ] );
		$logid = $logEntry->insert();
		$log = new LogPage( 'abusefilterblockeddomainhit' );
		if ( $log->isRestricted() ) {
			// Make sure checkusers can see this action if the log is restricted
			// (which is the default)
			if ( ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' ) ) {
				$rc = $logEntry->getRecentChange( $logid );
				/** @var CheckUserInsert $checkUserInsert */
				$checkUserInsert = MediaWikiServices::getInstance()->get( 'CheckUserInsert' );
				$checkUserInsert->updateCheckUserData( $rc );
			}
		} else {
			// If the log is unrestricted, publish normally to RC,
			// which will also update checkuser
			$logEntry->publish( $logid, "rc" );
		}
	}
}

// @deprecated since 1.44
class_alias( BlockedDomainFilter::class, 'MediaWiki\Extension\AbuseFilter\BlockedDomainFilter' );
