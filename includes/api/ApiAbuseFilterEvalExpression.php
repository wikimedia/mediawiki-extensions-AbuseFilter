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

		$status = AbuseFilter::evaluateExpression( $params['expression'] );
		if ( !$status->isGood() ) {
			$this->dieWithError( $status->getErrors()[0] );
		} else {
			$res = $status->getValue();
			$res = $params['prettyprint'] ? AbuseFilter::formatVar( $res ) : $res;
			$this->getResult()->addValue(
				null,
				$this->getModuleName(),
				ApiResult::addMetadataToResultVars( [ 'result' => $res ] )
			);
		}
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
			'prettyprint' => [
				ApiBase::PARAM_TYPE => 'boolean'
			]
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
			'action=abusefilterevalexpression&expression=lcase("FOO")&prettyprint=1'
				=> 'apihelp-abusefilterevalexpression-example-2',
		];
	}
}
