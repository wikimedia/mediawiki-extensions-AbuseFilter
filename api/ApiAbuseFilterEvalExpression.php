<?php

class ApiAbuseFilterEvalExpression extends ApiBase {
	public function execute() {
		$params = $this->extractRequestParams();

		$result = AbuseFilter::evaluateExpression( $params['expression'] );

		$this->getResult()->addValue( null, $this->getModuleName(), array( 'result' => $result ) );
	}

	public function getAllowedParams() {
		return array(
			'expression' => array(
				ApiBase::PARAM_REQUIRED => true,
			),
		);
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return array(
			'action=abusefilterevalexpression&expression=lcase("FOO")'
				=> 'apihelp-abusefilterevalexpression-example-1',
		);
	}
}
