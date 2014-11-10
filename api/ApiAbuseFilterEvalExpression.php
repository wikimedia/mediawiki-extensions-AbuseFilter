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
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getParamDescription() {
		return array(
			'expression' => 'The expression to evaluate',
		);
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getDescription() {
		return array(
			'Evaluates an AbuseFilter expression'
		);
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getExamples() {
		return array(
			'api.php?action=abusefilterevalexpression&expression=lcase("FOO")'
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
