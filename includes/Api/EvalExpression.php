<?php

namespace MediaWiki\Extension\AbuseFilter\Api;

use ApiBase;
use ApiResult;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesFormatter;
use Status;

class EvalExpression extends ApiBase {
	/**
	 * @inheritDoc
	 */
	public function execute() {
		$afPermManager = AbuseFilterServices::getPermissionManager();
		// "Anti-DoS"
		if ( !$afPermManager->canViewPrivateFilters( $this->getUser() ) ) {
			$this->dieWithError( 'apierror-abusefilter-canteval', 'permissiondenied' );
		}

		$params = $this->extractRequestParams();

		$status = $this->evaluateExpression( $params['expression'] );
		if ( !$status->isGood() ) {
			$this->dieWithError( $status->getErrors()[0] );
		} else {
			$res = $status->getValue();
			$res = $params['prettyprint'] ? VariablesFormatter::formatVar( $res ) : $res;
			$this->getResult()->addValue(
				null,
				$this->getModuleName(),
				ApiResult::addMetadataToResultVars( [ 'result' => $res ] )
			);
		}
	}

	/**
	 * @param string $expr
	 * @return Status
	 */
	private function evaluateExpression( string $expr ): Status {
		$parser = AbuseFilterServices::getParserFactory()->newParser();
		if ( $parser->checkSyntax( $expr )->getResult() !== true ) {
			return Status::newFatal( 'abusefilter-tools-syntax-error' );
		}

		// Generic vars are the only ones available
		$generator = AbuseFilterServices::getVariableGeneratorFactory()->newGenerator();
		$vars = $generator->addGenericVars()->getVariableHolder();
		$vars->setVar( 'timestamp', wfTimestamp( TS_UNIX ) );
		$parser->setVariables( $vars );

		return Status::newGood( $parser->evaluateExpression( $expr ) );
	}

	/**
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
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
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
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
