<?php

class ApiAbuseFilterCheckMatch extends ApiBase {
	public function execute() {
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'vars', 'rcid', 'logid' );

		// "Anti-DoS"
		if ( !$this->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			if ( is_callable( [ $this, 'dieWithError' ] ) ) {
				$this->dieWithError( 'apierror-abusefilter-canttest', 'permissiondenied' );
			} else {
				$this->dieUsage( 'You don\'t have permission to test abuse filters', 'permissiondenied' );
			}
		}

		$vars = null;
		if ( $params['vars'] ) {
			$vars = new AbuseFilterVariableHolder;
			$pairs = FormatJson::decode( $params['vars'], true );
			foreach ( $pairs as $name => $value ) {
				$vars->setVar( $name, $value );
			}
		} elseif ( $params['rcid'] ) {
			$dbr = wfGetDB( DB_SLAVE );
			$row = $dbr->selectRow(
				'recentchanges',
				'*',
				array( 'rc_id' => $params['rcid'] ),
				__METHOD__
			);

			if ( !$row ) {
				if ( is_callable( [ $this, 'dieWithError' ] ) ) {
					$this->dieWithError( [ 'apierror-nosuchrcid', $params['rcid'] ] );
				} else {
					$this->dieUsageMsg( [ 'nosuchrcid', $params['rcid'] ] );
				}
			}

			$vars = AbuseFilter::getVarsFromRCRow( $row );
		} elseif ( $params['logid'] ) {
			$dbr = wfGetDB( DB_SLAVE );
			$row = $dbr->selectRow(
				'abuse_filter_log',
				'*',
				array( 'afl_id' => $params['logid'] ),
				__METHOD__
			);

			if ( !$row ) {
				if ( is_callable( [ $this, 'dieWithError' ] ) ) {
					$this->dieWithError( [ 'apierror-abusefilter-nosuchlogid', $params['logid'] ], 'nosuchlogid' );
				} else {
					$this->dieUsage(
						"There is no abuselog entry with the id ``{$params['logid']}''",
						'nosuchlogid'
					);
				}
			}

			$vars = AbuseFilter::loadVarDump( $row->afl_var_dump );
		}

		if ( AbuseFilter::checkSyntax( $params[ 'filter' ] ) !== true ) {
			if ( is_callable( [ $this, 'dieWithError' ] ) ) {
				$this->dieWithError( 'apierror-abusefilter-badsyntax', 'badsyntax' );
			} else {
				$this->dieUsage( 'The filter has invalid syntax', 'badsyntax' );
			}
		}

		$result = array(
			ApiResult::META_BC_BOOLS => [ 'result' ],
			'result' => AbuseFilter::checkConditions( $params['filter'], $vars ),
		);

		$this->getResult()->addValue(
			null,
			$this->getModuleName(),
			$result
		);
	}

	public function getAllowedParams() {
		return array(
			'filter' => array(
				ApiBase::PARAM_REQUIRED => true,
			),
			'vars' => null,
			'rcid' => array(
				ApiBase::PARAM_TYPE => 'integer'
			),
			'logid' => array(
				ApiBase::PARAM_TYPE => 'integer'
			),
		);
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return array(
			'action=abusefiltercheckmatch&filter=!("autoconfirmed"%20in%20user_groups)&rcid=15'
				=> 'apihelp-abusefiltercheckmatch-example-1',
		);
	}
}
