<?php

namespace MediaWiki\Extension\AbuseFilter\Api;

use AbuseFilterVariableHolder;
use ApiBase;
use ApiResult;
use FormatJson;
use LogicException;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\RCVariableGenerator;
use RecentChange;

class CheckMatch extends ApiBase {
	/**
	 * @inheritDoc
	 */
	public function execute() {
		$afPermManager = AbuseFilterServices::getPermissionManager();
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'vars', 'rcid', 'logid' );

		// "Anti-DoS"
		if ( !$afPermManager->canViewPrivateFilters( $this->getUser() ) ) {
			$this->dieWithError( 'apierror-abusefilter-canttest', 'permissiondenied' );
		}

		$vars = null;
		if ( $params['vars'] ) {
			$pairs = FormatJson::decode( $params['vars'], true );
			$vars = AbuseFilterVariableHolder::newFromArray( $pairs );
		} elseif ( $params['rcid'] ) {
			$rc = RecentChange::newFromId( $params['rcid'] );

			if ( !$rc ) {
				$this->dieWithError( [ 'apierror-nosuchrcid', $params['rcid'] ] );
			}

			$vars = new AbuseFilterVariableHolder();
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable T240141
			$varGenerator = new RCVariableGenerator( $vars, $rc, $this->getUser() );
			$vars = $varGenerator->getVars();
		} elseif ( $params['logid'] ) {
			$dbr = wfGetDB( DB_REPLICA );
			$row = $dbr->selectRow(
				'abuse_filter_log',
				'afl_var_dump',
				[ 'afl_id' => $params['logid'] ],
				__METHOD__
			);

			if ( !$row ) {
				$this->dieWithError( [ 'apierror-abusefilter-nosuchlogid', $params['logid'] ], 'nosuchlogid' );
			}

			$vars = AbuseFilterServices::getVariablesBlobStore()->loadVarDump( $row->afl_var_dump );
		}
		if ( $vars === null ) {
			throw new LogicException( 'Impossible.' );
		}

		$parser = AbuseFilterServices::getParserFactory()->newParser();
		if ( $parser->checkSyntax( $params['filter'] ) !== true ) {
			$this->dieWithError( 'apierror-abusefilter-badsyntax', 'badsyntax' );
		}

		$parser->setVariables( $vars );
		$result = [
			ApiResult::META_BC_BOOLS => [ 'result' ],
			'result' => $parser->checkConditions( $params['filter'] )->getResult(),
		];

		$this->getResult()->addValue(
			null,
			$this->getModuleName(),
			$result
		);
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
			'vars' => null,
			'rcid' => [
				ApiBase::PARAM_TYPE => 'integer'
			],
			'logid' => [
				ApiBase::PARAM_TYPE => 'integer'
			],
		];
	}

	/**
	 * @return array
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return [
			'action=abusefiltercheckmatch&filter=!("autoconfirmed"%20in%20user_groups)&rcid=15'
				=> 'apihelp-abusefiltercheckmatch-example-1',
		];
	}
}
