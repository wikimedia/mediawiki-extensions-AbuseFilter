<?php

class ApiAbuseFilterCheckSyntax extends ApiBase {

	public function execute() {
		// "Anti-DoS"
		if ( !$this->getUser()->isAllowed( 'abusefilter-modify' ) ) {
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
