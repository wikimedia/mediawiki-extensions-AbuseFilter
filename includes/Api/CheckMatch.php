<?php

namespace MediaWiki\Extension\AbuseFilter\Api;

use ApiBase;
use ApiResult;
use FormatJson;
use LogEventsList;
use LogicException;
use LogPage;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseLog;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Revision\RevisionRecord;
use RecentChange;

class CheckMatch extends ApiBase {
	/**
	 * @inheritDoc
	 */
	public function execute() {
		$afPermManager = AbuseFilterServices::getPermissionManager();
		$user = $this->getUser();
		$params = $this->extractRequestParams();
		$this->requireOnlyOneParameter( $params, 'vars', 'rcid', 'logid' );

		// "Anti-DoS"
		if ( !$afPermManager->canUseTestTools( $this->getUser() ) ) {
			$this->dieWithError( 'apierror-abusefilter-canttest', 'permissiondenied' );
		}

		$vars = null;
		if ( $params['vars'] ) {
			$pairs = FormatJson::decode( $params['vars'], true );
			$vars = VariableHolder::newFromArray( $pairs );
		} elseif ( $params['rcid'] ) {
			$rc = RecentChange::newFromId( $params['rcid'] );

			if ( !$rc ) {
				$this->dieWithError( [ 'apierror-nosuchrcid', $params['rcid'] ] );
			}

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

			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable T240141
			$varGenerator = AbuseFilterServices::getVariableGeneratorFactory()->newRCGenerator( $rc, $user );
			$vars = $varGenerator->getVars();
		} elseif ( $params['logid'] ) {
			$dbr = wfGetDB( DB_REPLICA );
			$row = $dbr->selectRow(
				'abuse_filter_log',
				'*',
				[ 'afl_id' => $params['logid'] ],
				__METHOD__
			);

			if ( !$row ) {
				$this->dieWithError( [ 'apierror-abusefilter-nosuchlogid', $params['logid'] ], 'nosuchlogid' );
			}

			if ( !$afPermManager->canSeeHiddenLogEntries( $user ) && SpecialAbuseLog::isHidden( $row ) ) {
				// T223654 - Same check as in SpecialAbuseLog. Both the visibility of the AbuseLog entry
				// and the corresponding revision are checked.
				$this->dieWithError( 'apierror-permissiondenied-generic', 'deletedabuselog' );
			}

			$vars = AbuseFilterServices::getVariablesBlobStore()->loadVarDump( $row->afl_var_dump );
		}
		if ( $vars === null ) {
			throw new LogicException( 'Impossible.' );
		}

		$parser = AbuseFilterServices::getParserFactory()->newParser( $vars );
		if ( $parser->checkSyntax( $params['filter'] )->getResult() !== true ) {
			$this->dieWithError( 'apierror-abusefilter-badsyntax', 'badsyntax' );
		}

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
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
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
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=abusefiltercheckmatch&filter=!("autoconfirmed"%20in%20user_groups)&rcid=15'
				=> 'apihelp-abusefiltercheckmatch-example-1',
		];
	}
}
