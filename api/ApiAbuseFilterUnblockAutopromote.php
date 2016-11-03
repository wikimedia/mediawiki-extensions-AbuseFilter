<?php

class ApiAbuseFilterUnblockAutopromote extends ApiBase {
	public function execute() {
		if ( is_callable( [ $this, 'checkUserRightsAny' ] ) ) {
			$this->checkUserRightsAny( 'abusefilter-modify' );
		} else {
			if ( !$this->getUser()->isAllowed( 'abusefilter-modify' ) ) {
				$this->dieUsage( 'You do not have permissions to unblock autopromotion', 'permissiondenied' );
			}
		}

		$params = $this->extractRequestParams();
		$user = User::newFromName( $params['user'] );

		if ( $user === false ) {
			$encParamName = $this->encodeParamName( 'user' );
			if ( is_callable( [ $this, 'dieWithError' ] ) ) {
				$this->dieWithError(
					[ 'apierror-baduser', $encParamName, wfEscapeWikiText( $param['user'] ) ],
					"baduser_{$encParamName}"
				);
			} else {
				$this->dieUsage(
					"Invalid value '{$param['user']}' for user parameter $encParamName",
					"baduser_{$encParamName}"
				);
			}
		}

		$key = AbuseFilter::autoPromoteBlockKey( $user );
		$stash = ObjectCache::getMainStashInstance();
		if ( !$stash->get( $key ) ) {
			if ( is_callable( [ $this, 'dieWithError' ] ) ) {
				$this->dieWithError( [ 'abusefilter-reautoconfirm-none', $user->getName() ], 'notsuspended' );
			} else {
				$msg = wfMessage( 'abusefilter-reautoconfirm-none', $user->getName() )
					->inLanguage( 'en' )->useDatabase( false )->text();
				$this->dieUsage( $msg, 'notsuspended' );
			}
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
				ApiBase::PARAM_TYPE => 'user',
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
