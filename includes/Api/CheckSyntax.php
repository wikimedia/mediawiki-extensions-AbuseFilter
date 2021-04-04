<?php

namespace MediaWiki\Extension\AbuseFilter\Api;

use ApiBase;
use ApiMain;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\Parser\AFPUserVisibleException;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;

class CheckSyntax extends ApiBase {

	/** @var ParserFactory */
	private $afParserFactory;

	/** @var AbuseFilterPermissionManager */
	private $afPermManager;

	/**
	 * @param ApiMain $main
	 * @param string $action
	 * @param ParserFactory $afParserFactory
	 * @param AbuseFilterPermissionManager $afPermManager
	 */
	public function __construct(
		ApiMain $main,
		$action,
		ParserFactory $afParserFactory,
		AbuseFilterPermissionManager $afPermManager
	) {
		parent::__construct( $main, $action );
		$this->afParserFactory = $afParserFactory;
		$this->afPermManager = $afPermManager;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		// "Anti-DoS"
		if ( !$this->afPermManager->canUseTestTools( $this->getUser() )
			&& !$this->afPermManager->canEdit( $this->getUser() )
		) {
			$this->dieWithError( 'apierror-abusefilter-cantcheck', 'permissiondenied' );
		}

		$params = $this->extractRequestParams();
		$result = $this->afParserFactory->newParser()->checkSyntax( $params['filter'] );

		$r = [];
		$warnings = [];
		foreach ( $result->getWarnings() as $warning ) {
			$warnings[] = [
				'message' => $this->msg( $warning->getMessageObj() )->text(),
				'character' => $warning->getPosition()
			];
		}
		if ( $warnings ) {
			$r['warnings'] = $warnings;
		}

		if ( $result->getResult() === true ) {
			// Everything went better than expected :)
			$r['status'] = 'ok';
		} else {
			// TODO: Improve the type here.
			/** @var AFPUserVisibleException $excep */
			$excep = $result->getException();
			'@phan-var AFPUserVisibleException $excep';
			$r = [
				'status' => 'error',
				'message' => $this->msg( $excep->getMessageObj() )->text(),
				'character' => $excep->getPosition(),
			];
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $r );
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
		];
	}

	/**
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
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
