<?php

namespace MediaWiki\Extension\AbuseFilter\Api;

use ApiBase;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;

class CheckSyntax extends ApiBase {

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$afPermManager = AbuseFilterServices::getPermissionManager();
		// "Anti-DoS"
		if ( !$afPermManager->canViewPrivateFilters( $this->getUser() ) ) {
			$this->dieWithError( 'apierror-abusefilter-cantcheck', 'permissiondenied' );
		}

		$params = $this->extractRequestParams();
		$result = AbuseFilterServices::getParserFactory()->newParser()->checkSyntax( $params['filter'] );

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
	 * @return array
	 * @see ApiBase::getAllowedParams
	 */
	public function getAllowedParams() {
		return [
			'filter' => [
				ApiBase::PARAM_REQUIRED => true,
			],
		];
	}

	/**
	 * @return array
	 * @see ApiBase::getExamplesMessages()
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
