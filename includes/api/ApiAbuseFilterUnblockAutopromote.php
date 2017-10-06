<?php

class ApiAbuseFilterUnblockAutopromote extends ApiBase {
	public function execute() {
		$this->checkUserRightsAny( 'abusefilter-modify' );

		$params = $this->extractRequestParams();
		$user = User::newFromName( $params['user'] );

		if ( $user === false ) {
			$encParamName = $this->encodeParamName( 'user' );
			$this->dieWithError(
				[ 'apierror-baduser', $encParamName, wfEscapeWikiText( $param['user'] ) ],
				"baduser_{$encParamName}"
			);
		}

		$key = AbuseFilter::autoPromoteBlockKey( $user );
		$stash = ObjectCache::getMainStashInstance();
		if ( !$stash->get( $key ) ) {
			$this->dieWithError( [ 'abusefilter-reautoconfirm-none', $user->getName() ], 'notsuspended' );
		}

		$stash->delete( $key );

		$res = [ 'user' => $params['user'] ];
		$this->getResult()->addValue( null, $this->getModuleName(), $res );
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'user' => [
				ApiBase::PARAM_TYPE => 'user',
				ApiBase::PARAM_REQUIRED => true
			],
			'token' => null,
		];
	}

	public function needsToken() {
		return 'csrf';
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=abusefilterunblockautopromote&user=Example&token=123ABC'
				=> 'apihelp-abusefilterunblockautopromote-example-1',
		];
	}
}
