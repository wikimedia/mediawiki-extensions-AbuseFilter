<?php

class ApiAbuseFilterUnblockAutopromote extends ApiBase {
	/**
	 * @see ApiBase::execute()
	 */
	public function execute() {
		$this->checkUserRightsAny( 'abusefilter-modify' );

		$params = $this->extractRequestParams();
		$user = User::newFromName( $params['user'] );

		if ( $user === false ) {
			$encParamName = $this->encodeParamName( 'user' );
			$this->dieWithError(
				[ 'apierror-baduser', $encParamName, wfEscapeWikiText( $params['user'] ) ],
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

	/**
	 * @see ApiBase::mustBePosted()
	 * @return bool
	 */
	public function mustBePosted() {
		return true;
	}

	/**
	 * @see ApiBase::isWriteMode()
	 * @return bool
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @see ApiBase::getAllowedParams()
	 * @return array
	 */
	public function getAllowedParams() {
		return [
			'user' => [
				ApiBase::PARAM_TYPE => 'user',
				ApiBase::PARAM_REQUIRED => true
			],
			'token' => null,
		];
	}

	/**
	 * @see ApiBase::needsToken()
	 * @return string
	 */
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
