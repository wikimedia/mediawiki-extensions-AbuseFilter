<?php

class ApiAbuseFilterEvalExpression extends ApiBase {
	/**
	 * @see ApiBase::execute()
	 */
	public function execute() {
		// "Anti-DoS"
		if ( !AbuseFilter::canViewPrivate( $this->getUser() ) ) {
			$this->dieWithError( 'apierror-abusefilter-canteval', 'permissiondenied' );
		}

		$params = $this->extractRequestParams();

		$result = AbuseFilter::evaluateExpression( $params['expression'] );

		$this->getResult()->addValue( null, $this->getModuleName(), [ 'result' => $result ] );
	}

	/**
	 * @see ApiBase::getAllowedParams()
	 * @return array
	 */
	public function getAllowedParams() {
		return [
			'expression' => [
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
			'action=abusefilterevalexpression&expression=lcase("FOO")'
				=> 'apihelp-abusefilterevalexpression-example-1',
		];
	}
}
