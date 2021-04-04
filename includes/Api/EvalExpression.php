<?php

namespace MediaWiki\Extension\AbuseFilter\Api;

use ApiBase;
use ApiMain;
use ApiResult;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGeneratorFactory;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesFormatter;
use Status;

class EvalExpression extends ApiBase {

	/** @var ParserFactory */
	private $afParserFactory;

	/** @var AbuseFilterPermissionManager */
	private $afPermManager;

	/** @var VariableGeneratorFactory */
	private $afVariableGeneratorFactory;

	/**
	 * @param ApiMain $main
	 * @param string $action
	 * @param ParserFactory $afParserFactory
	 * @param AbuseFilterPermissionManager $afPermManager
	 * @param VariableGeneratorFactory $afVariableGeneratorFactory
	 */
	public function __construct(
		ApiMain $main,
		$action,
		ParserFactory $afParserFactory,
		AbuseFilterPermissionManager $afPermManager,
		VariableGeneratorFactory $afVariableGeneratorFactory
	) {
		parent::__construct( $main, $action );
		$this->afParserFactory = $afParserFactory;
		$this->afPermManager = $afPermManager;
		$this->afVariableGeneratorFactory = $afVariableGeneratorFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		// "Anti-DoS"
		if ( !$this->afPermManager->canUseTestTools( $this->getUser() ) ) {
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
		$parser = $this->afParserFactory->newParser();
		if ( $parser->checkSyntax( $expr )->getResult() !== true ) {
			return Status::newFatal( 'abusefilter-tools-syntax-error' );
		}

		// Generic vars are the only ones available
		$generator = $this->afVariableGeneratorFactory->newGenerator();
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
