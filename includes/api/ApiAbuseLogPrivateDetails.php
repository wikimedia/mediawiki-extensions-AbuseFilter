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
 */

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;

/**
 * API module to allow accessing private details (the user's IP) from AbuseLog entries
 *
 * @ingroup API
 * @ingroup Extensions
 */
class ApiAbuseLogPrivateDetails extends ApiBase {
	/**
	 * @inheritDoc
	 */
	public function mustBePosted() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function needsToken() {
		return 'csrf';
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$user = $this->getUser();
		$afPermManager = AbuseFilterServices::getPermissionManager();

		if ( !$afPermManager->canSeePrivateDetails( $user ) ) {
			$this->dieWithError( 'abusefilter-log-cannot-see-privatedetails' );
		}
		$params = $this->extractRequestParams();

		if ( !SpecialAbuseLog::checkPrivateDetailsAccessReason( $params['reason'] ) ) {
			// Double check, in case we add some extra validation
			$this->dieWithError( 'abusefilter-noreason' );
		}
		$status = SpecialAbuseLog::getPrivateDetailsRow( $user, $params['logid'] );
		if ( !$status->isGood() ) {
			$this->dieWithError( $status->getErrors()[0] );
		}
		$row = $status->getValue();
		// Log accessing private details
		if ( $this->getConfig()->get( 'AbuseFilterLogPrivateDetailsAccess' ) ) {
			SpecialAbuseLog::addPrivateDetailsAccessLogEntry(
				$params['logid'],
				$params['reason'],
				$user
			);
		}

		$result = [
			'log-id' => $params['logid'],
			'user' => $row->afl_user_text,
			'filter-id' => $row->af_id,
			'filter-description' => $row->af_public_comments,
			'ip-address' => $row->afl_ip !== '' ? $row->afl_ip : null
		];
		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			'logid' => [
				ApiBase::PARAM_TYPE => 'integer'
			],
			'reason' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => $this->getConfig()->get( 'AbuseFilterPrivateDetailsForceReason' ),
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=abuselogprivatedetails&logid=1&reason=example&token=ABC123'
				=> 'apihelp-abuselogprivatedetails-example-1'
		];
	}
}
