<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Extension\AbuseFilter\Consequences\ConsequencesRegistry;
use MediaWiki\User\Hook\GetAutoPromoteGroupsHook;
use MediaWiki\User\UserIdentity;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\HashBagOStuff;

class AutoPromoteGroupsHandler implements GetAutoPromoteGroupsHook {

	public function __construct(
		private readonly ConsequencesRegistry $consequencesRegistry,
		private readonly BlockAutopromoteStore $blockAutopromoteStore,
		private readonly BagOStuff $cache = new HashBagOStuff(),
	) {
	}

	/**
	 * @param UserIdentity $user
	 * @param string[] &$promote
	 */
	public function onGetAutoPromoteGroups( $user, &$promote ): void {
		if (
			in_array( 'blockautopromote', $this->consequencesRegistry->getAllEnabledActionNames() )
			&& $promote
		) {
			// Proxy the blockautopromote data to a faster backend, using an appropriate key
			$quickCacheKey = $this->cache->makeKey(
				'abusefilter',
				'blockautopromote',
				'quick',
				$user->getId()
			);
			$blocked = (bool)$this->cache->getWithSetCallback(
				$quickCacheKey,
				BagOStuff::TTL_PROC_LONG,
				function () use ( $user ) {
					return $this->blockAutopromoteStore->getAutoPromoteBlockStatus( $user );
				}
			);

			if ( $blocked ) {
				$promote = [];
			}
		}
	}
}
