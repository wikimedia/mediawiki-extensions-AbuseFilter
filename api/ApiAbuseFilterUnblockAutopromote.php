<?php

class ApiAbuseFilterUnblockAutopromote extends ApiBase {
	public function execute() {
		if ( !$this->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			$this->dieUsage( 'You do not have permissions to unblock autopromotion', 'permissiondenied' );
		}

		$params = $this->extractRequestParams();
		$user = User::newFromName( $params['user'] );

		if ( $user === false ) {
			// Oh god this is so bad but this message uses GENDER
			$msg = wfMessage( 'abusefilter-reautoconfirm-none', $params['user'] )->text();
			$this->dieUsage( $msg, 'notsuspended' );
		}

		$key = AbuseFilter::autoPromoteBlockKey( $user );
		$stash = ObjectCache::getMainStashInstance();
		if ( !$stash->get( $key ) ) {
			// Same as above :(
			$msg = wfMessage( 'abusefilter-reautoconfirm-none', $params['user'] )->text();
			$this->dieUsage( $msg, 'notsuspended' );
		}

		$stash->delete( $key );

		$res = array( 'user' => $params['user'] );
		$this->getResult()->addValue( null, $this->getModuleName(), $res );
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return array(
			'user' => array(
				ApiBase::PARAM_REQUIRED => true
			),
			'token' => null,
		);
	}

	public function needsToken() {
		return 'csrf';
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return array(
			'action=abusefilterunblockautopromote&user=Example&token=123ABC'
				=> 'apihelp-abusefilterunblockautopromote-example-1',
		);
	}
}
