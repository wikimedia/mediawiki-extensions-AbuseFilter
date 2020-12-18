<?php

namespace MediaWiki\Extension\AbuseFilter\Consequences\Consequence;

use MediaWiki\Block\BlockUserFactory;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\FilterUser;

/**
 * Consequence that blocks a single user.
 */
class Block extends BlockingConsequence {
	/** @var bool */
	private $preventsTalkEdit;

	/**
	 * @param Parameters $params
	 * @param string $expiry
	 * @param bool $preventTalkEdit
	 * @param BlockUserFactory $blockUserFactory
	 * @param FilterUser $filterUser
	 */
	public function __construct(
		Parameters $params,
		string $expiry,
		bool $preventTalkEdit,
		BlockUserFactory $blockUserFactory,
		FilterUser $filterUser
	) {
		parent::__construct( $params, $expiry, $blockUserFactory, $filterUser );
		$this->preventsTalkEdit = $preventTalkEdit;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() : bool {
		$this->doBlockInternal(
			$this->parameters->getFilter()->getName(),
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->parameters->getFilter()->getID(),
			$this->parameters->getUser()->getName(),
			$this->expiry,
			$autoblock = true,
			$this->preventsTalkEdit
		);
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getMessage(): array {
		return [
			'abusefilter-blocked-display',
			$this->parameters->getFilter()->getName(),
			$this->parameters->getFilter()->getID()
		];
	}
}
