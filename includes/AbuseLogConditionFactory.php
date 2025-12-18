<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\User\UserIdentity;
use Wikimedia\IPUtils;

/**
 * Factory used for constructing query expressions for filtering records in
 * the abuse_filter_log table.
 *
 * @since 1.46
 */
class AbuseLogConditionFactory {

	public const SERVICE_NAME = 'AbuseLogConditionFactory';

	/**
	 * Returns an expression for filtering out log entries not associated with a
	 * given IP or IP range.
	 *
	 * @param string $address IP address or range to filter by
	 */
	public function getUserFilterByIPAddress( string $address ): array {
		// @todo Support for IP ranges, use afl_ip_hex for queries (T412339)

		return [
			'afl_user' => 0,
			'afl_user_text' => IPUtils::sanitizeIP( $address )
		];
	}

	/**
	 * Returns a query condition for filtering out log entries not associated
	 * with the provided user.
	 *
	 * Note that both the ID and username are used: For local users, the caller
	 * needs to know the local user's ID; for external users, the caller should
	 * provide the user ID as zero.
	 *
	 * @param UserIdentity $userIdentity User to filter by
	 * @return array List of conditions
	 */
	public function getUserFilterByUserIdentity(
		UserIdentity $userIdentity
	): array {
		return [
			'afl_user' => $userIdentity->getId(),
			'afl_user_text' => $userIdentity->getName(),
		];
	}
}
