<?php

class ApiAbuseFilterCheckSyntax extends ApiBase {

	public function execute() {
		// "Anti-DoS"
		if ( !$this->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			$this->dieUsage( 'You don\'t have permission to check syntax of abuse filters', 'permissiondenied' );
		}

		$params = $this->extractRequestParams();
		$result = AbuseFilter::checkSyntax( $params[ 'filter' ] );

		$r = array();
		if ( $result === true ) {
			// Everything went better than expected :)
			$r['status'] = 'ok';
		} else {
			$r = array(
				'status' => 'error',
				'message' => $result[0],
				'character' => $result[1],
			);
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $r );
	}

	public function getAllowedParams() {
		return array(
			'filter' => array(
				ApiBase::PARAM_REQUIRED => true,
			),
		);
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getParamDescription() {
		return array(
			'filter' => 'The full filter text to check syntax on',
		);
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getDescription() {
		return array(
			'Check syntax of an AbuseFilter filter'
		);
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getExamples() {
		return array(
			'api.php?action=abusefilterchecksyntax&filter="foo"',
			'api.php?action=abusefilterchecksyntax&filter="bar"%20bad_variable',
		);
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return array(
			'action=abusefilterchecksyntax&filter="foo"'
				=> 'apihelp-abusefilterchecksyntax-example-1',
			'action=abusefilterchecksyntax&filter="bar"%20bad_variable'
				=> 'apihelp-abusefilterchecksyntax-example-2',
		);
	}
}
