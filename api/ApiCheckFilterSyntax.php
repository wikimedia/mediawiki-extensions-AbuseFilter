<?php

class ApiCheckFilterSyntax extends ApiBase {

	public function execute() {
		global $wgUser;
		$params = $this->extractRequestParams();

		// "Anti-DoS"
		if ( !$wgUser->isAllowed( 'abusefilter-modify' ) ) {
			$this->dieUsageMsg( 'permissiondenied' );
		}

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

	public function getParamDescription() {
		return array(
			'filter' => 'The full filter text to check syntax on',
		);
	}

	public function getDescription() {
		return array(
			'Check syntax of an AbuseFilter filter'
		);
	}

	public function getPossibleErrors() {
		return array_merge( parent::getPossibleErrors(), array(
			array( 'permissiondenied' ),
		) );
	}

	public function getExamples() {
		return array(
			'api.php?action=checkfiltersyntax&filter="foo"',
			'api.php?action=checkfiltersyntax&filter="bar"%20bad_variable',
		);
	}

	public function getVersion() {
		return __CLASS__ . ': $Id$';
	}
}