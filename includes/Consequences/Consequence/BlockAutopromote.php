<?php

namespace MediaWiki\Extension\AbuseFilter\Consequences\Consequence;

use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;

/**
 * Consequence that blocks/delays autopromotion of a registered user.
 */
class BlockAutopromote extends Consequence implements HookAborterConsequence {
	/** @var int */
	private $duration;
	/** @var BlockAutopromoteStore */
	private $blockAutopromoteStore;

	/**
	 * @param Parameters $params
	 * @param int $duration
	 * @param BlockAutopromoteStore $blockAutopromoteStore
	 */
	public function __construct(
		Parameters $params,
		int $duration,
		BlockAutopromoteStore $blockAutopromoteStore
	) {
		parent::__construct( $params );
		$this->duration = $duration;
		$this->blockAutopromoteStore = $blockAutopromoteStore;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() : bool {
		$target = $this->parameters->getUser();
		if ( !$target->isRegistered() ) {
			return false;
		}

		return $this->blockAutopromoteStore->blockAutoPromote(
			$target,
			// TODO: inject MessageLocalizer
			wfMessage(
				'abusefilter-blockautopromotereason',
				$this->parameters->getFilter()->getName(),
				$this->parameters->getFilter()->getID()
			)->inContentLanguage()->text(),
			$this->duration
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getMessage(): array {
		return [
			'abusefilter-autopromote-blocked',
			$this->parameters->getFilter()->getName(),
			$this->parameters->getFilter()->getID(),
			$this->duration
		];
	}
}
