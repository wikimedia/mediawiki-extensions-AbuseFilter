<?php

namespace MediaWiki\Extension\AbuseFilter\Api;

use ApiBase;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use User;

class UnblockAutopromote extends ApiBase {
	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->checkUserRightsAny( 'abusefilter-modify' );

		$params = $this->extractRequestParams();
		$target = User::newFromName( $params['user'] );

		if ( $target === false ) {
			$encParamName = $this->encodeParamName( 'user' );
			$this->dieWithError(
				[ 'apierror-baduser', $encParamName, wfEscapeWikiText( $params['user'] ) ],
				"baduser_{$encParamName}"
			);
		}

		$block = $this->getUser()->getBlock();
		if ( $block && $block->isSitewide() ) {
			$this->dieBlocked( $block );
		}

		$msg = $this->msg( 'abusefilter-tools-restoreautopromote' )->inContentLanguage()->text();
		$blockAutopromoteStore = AbuseFilterServices::getBlockAutopromoteStore();
		$res = $blockAutopromoteStore->unblockAutopromote( $target, $this->getUser(), $msg );

		if ( $res === false ) {
			$this->dieWithError( [ 'abusefilter-reautoconfirm-none', $target->getName() ], 'notsuspended' );
		}

		$finalResult = [ 'user' => $params['user'] ];
		$this->getResult()->addValue( null, $this->getModuleName(), $finalResult );
	}

	/**
	 * @return bool
	 * @see ApiBase::mustBePosted()
	 */
	public function mustBePosted() {
		return true;
	}

	/**
	 * @return bool
	 * @see ApiBase::isWriteMode()
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @return array
	 * @see ApiBase::getAllowedParams()
	 */
	public function getAllowedParams() {
		return [
			'user' => [
				ApiBase::PARAM_TYPE => 'user',
				ApiBase::PARAM_REQUIRED => true
			],
			'token' => null,
		];
	}

	/**
	 * @return string
	 * @see ApiBase::needsToken()
	 */
	public function needsToken() {
		return 'csrf';
	}

	/**
	 * @return array
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return [
			'action=abusefilterunblockautopromote&user=Example&token=123ABC'
				=> 'apihelp-abusefilterunblockautopromote-example-1',
		];
	}
}
