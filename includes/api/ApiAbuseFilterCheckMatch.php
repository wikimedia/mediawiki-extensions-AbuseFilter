<?php

use MediaWiki\Storage\RevisionRecord;

class ApiAbuseFilterCheckMatch extends ApiBase {
	public function execute() {
		$user = $this->getUser();
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'vars', 'rcid', 'logid' );

		// "Anti-DoS"
		if ( !$this->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			$this->dieWithError( 'apierror-abusefilter-canttest', 'permissiondenied' );
		}

		$vars = null;
		if ( $params['vars'] ) {
			$vars = new AbuseFilterVariableHolder;
			$pairs = FormatJson::decode( $params['vars'], true );
			foreach ( $pairs as $name => $value ) {
				$vars->setVar( $name, $value );
			}
		} elseif ( $params['rcid'] ) {
			$dbr = wfGetDB( DB_REPLICA );
			$rcQuery = RecentChange::getQueryInfo();
			$row = $dbr->selectRow(
				$rcQuery['tables'],
				$rcQuery['fields'],
				[ 'rc_id' => $params['rcid'] ],
				__METHOD__,
				[],
				$rcQuery['joins']
			);

			if ( !$row ) {
				$this->dieWithError( [ 'apierror-nosuchrcid', $params['rcid'] ] );
			}

			$rc = RecentChange::newFromRow( $row );
			$type = (int)$rc->getAttribute( 'rc_type' );
			$deletedValue = $rc->getAttribute( 'rc_deleted' );
			if (
				(
					$type === RC_LOG &&
					!LogEventsList::userCanBitfield(
						$deletedValue,
						LogPage::SUPPRESSED_ACTION | LogPage::SUPPRESSED_USER,
						$user
					)
				) || (
					$type !== RC_LOG &&
					!RevisionRecord::userCanBitfield( $deletedValue, RevisionRecord::SUPPRESSED_ALL, $user )
				)
			) {
				// T223654 - Same check as in AbuseFilterChangesList
				$this->dieWithError( 'apierror-permissiondenied-generic', 'deletedrc' );
			}

			$vars = AbuseFilter::getVarsFromRCRow( $row );
		} elseif ( $params['logid'] ) {
			$dbr = wfGetDB( DB_REPLICA );
			$row = $dbr->selectRow(
				'abuse_filter_log',
				'*',
				[ 'afl_id' => $params['logid'] ],
				__METHOD__
			);

			$permManager = MediaWikiServices::getInstance()->getPermissionManager();
			if ( !$user->isAllowed( 'abusefilter-hidden-log' ) && SpecialAbuseLog::isHidden( $row ) ) {
				// T223654 - Same check as in SpecialAbuseLog. Both the visibility of the AbuseLog entry
				// and the corresponding revision are checked.
				$this->dieWithError( 'apierror-permissiondenied-generic', 'deletedabuselog' );
			}

			if ( !$row ) {
				$this->dieWithError( [ 'apierror-abusefilter-nosuchlogid', $params['logid'] ], 'nosuchlogid' );
			}

			$vars = AbuseFilter::loadVarDump( $row->afl_var_dump );
		}

		if ( AbuseFilter::checkSyntax( $params[ 'filter' ] ) !== true ) {
			$this->dieWithError( 'apierror-abusefilter-badsyntax', 'badsyntax' );
		}

		$result = [
			ApiResult::META_BC_BOOLS => [ 'result' ],
			'result' => AbuseFilter::checkConditions( $params['filter'], $vars ),
		];

		$this->getResult()->addValue(
			null,
			$this->getModuleName(),
			$result
		);
	}

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
	 * @see ApiBase::getExamplesMessages()
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=abusefiltercheckmatch&filter=!("autoconfirmed"%20in%20user_groups)&rcid=15'
				=> 'apihelp-abusefiltercheckmatch-example-1',
		];
	}
}
