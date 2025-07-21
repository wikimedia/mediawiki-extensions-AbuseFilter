<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Permissions\Authority;
use StatusValue;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * This service allows code to lookup details about abuse_filter_log rows.
 *
 * @since 1.45
 */
class AbuseFilterLogDetailsLookup {

	public const SERVICE_NAME = 'AbuseFilterLogDetailsLookup';

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly AbuseFilterPermissionManager $afPermissionManager,
		private readonly FilterLookup $filterLookup
	) {
	}

	/**
	 * Returns the IP address associated with a given abuse_filter_log row identified by its afl_id.
	 *
	 * This method does NOT check whether the given {@link Authority} has the right to see IP
	 * addresses in general, but does check if the user can see each associated abuse_filter_log row.
	 *
	 * Callers are expected to log the access if the given {@link Authority} actually does view
	 * the IP address that was returned.
	 *
	 * @param Authority $authority The user who's trying to view the IP address.
	 * @param int $id The afl_id values for the rows
	 * @return StatusValue If a good status, the associated IP address is stored as the value of the status.
	 *    The IP address may be empty string if the row is more than $wgAbuseFilterLogIPMaxAge seconds old
	 *    or $wgAbuseFilterLogIP was false when the row was created.
	 */
	public function getIPForAbuseFilterLog( Authority $authority, int $id ): StatusValue {
		$row = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'afl_filter_id', 'afl_global', 'afl_ip_hex' ] )
			->from( 'abuse_filter_log' )
			->where( [ 'afl_id' => $id ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			return StatusValue::newFatal( 'abusefilter-log-nonexistent' );
		}

		$filter = $this->filterLookup->getFilter( $row->afl_filter_id, $row->afl_global );
		if ( !$this->afPermissionManager->canSeeLogDetailsForFilter( $authority, $filter ) ) {
			return StatusValue::newFatal( 'abusefilter-log-cannot-see-details' );
		}

		return StatusValue::newGood( $row->afl_ip_hex ? IPUtils::formatHex( $row->afl_ip_hex ) : '' );
	}
}
