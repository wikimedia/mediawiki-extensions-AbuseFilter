<?php

class ApiAbuseFilterUnblockAutopromote extends ApiBase {
	/**
	 * @see ApiBase::execute()
	 */
	public function execute() {
		$this->checkUserRightsAny( 'abusefilter-modify' );

		$params = $this->extractRequestParams();
		$target = User::newFromName( $params['user'] );

		if ( $target === false ) {
			$encParamName = $this->encodeParamName( 'user' );
			$this->dieWithError(
				[ 'apierror-baduser', $encParamName, wfEscapeWikiText( $params['user'] ) ],
				"baduser_{$encParamName}"
			);
		}

		$block = $this->getUser()->getBlock();
		if ( $block && $block->isSitewide() ) {
			$this->dieWithError( 'apierror-blocked' );
		}

		$msg = $this->msg( 'abusefilter-tools-restoreautopromote' )->inContentLanguage()->text();
		$res = AbuseFilter::unblockAutopromote( $target, $this->getUser(), $msg );

		if ( $res === false ) {
			$this->dieWithError( [ 'abusefilter-reautoconfirm-none', $target->getName() ], 'notsuspended' );
		}

		$finalResult = [ 'user' => $params['user'] ];
		$this->getResult()->addValue( null, $this->getModuleName(), $finalResult );
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
