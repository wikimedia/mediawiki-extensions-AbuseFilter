<?php

class ApiAbuseFilterCheckSyntax extends ApiBase {

	/**
	 * @see ApiBase::execute
	 */
	public function execute() {
		// "Anti-DoS"
		if ( !$this->getUser()->isAllowedAny( 'abusefilter-modify', 'abusefilter-view-private' ) ) {
			$this->dieWithError( 'apierror-abusefilter-cantcheck', 'permissiondenied' );
		}

		$params = $this->extractRequestParams();
		$result = AbuseFilter::checkSyntax( $params[ 'filter' ] );

		$r = [];
		if ( $result === true ) {
			// Everything went better than expected :)
			$r['status'] = 'ok';
		} else {
			$r = [
				'status' => 'error',
				'message' => $result[0],
				'character' => $result[1],
			];
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $r );
	}

	/**
	 * @see ApiBase::getAllowedParams
	 * @return array
	 */
	public function getAllowedParams() {
		return [
			'filter' => [
				ApiBase::PARAM_REQUIRED => true,
			],
		];
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=abusefilterchecksyntax&filter="foo"'
				=> 'apihelp-abusefilterchecksyntax-example-1',
			'action=abusefilterchecksyntax&filter="bar"%20bad_variable'
				=> 'apihelp-abusefilterchecksyntax-example-2',
		];
	}
}
