<?php

namespace MediaWiki\Extension\AbuseFilter\Consequence;

use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use Psr\Log\LoggerInterface;
use User;

/**
 * Consequence that blocks/delays autopromotion of a registered user.
 */
class BlockAutopromote extends Consequence implements HookAborterConsequence {
	/** @var int */
	private $duration;
	/** @var BlockAutopromoteStore */
	private $blockAutopromoteStore;
	/** @var LoggerInterface */
	private $logger;

	/**
	 * @param Parameters $params
	 * @param int $duration
	 * @param BlockAutopromoteStore $blockAutopromoteStore
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		Parameters $params,
		int $duration,
		BlockAutopromoteStore $blockAutopromoteStore,
		LoggerInterface $logger
	) {
		parent::__construct( $params );
		$this->duration = $duration;
		$this->blockAutopromoteStore = $blockAutopromoteStore;
		$this->logger = $logger;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() : bool {
		$target = User::newFromIdentity( $this->parameters->getUser() );
		if ( $target->isAnon() ) {
			return false;
		}

		$blocked = $this->blockAutopromoteStore->blockAutoPromote(
			$target,
			wfMessage(
				'abusefilter-blockautopromotereason',
				$this->parameters->getFilter()->getName(),
				$this->parameters->getFilter()->getID()
			)->inContentLanguage()->text(),
			$this->duration
		);

		if ( $blocked ) {
			return true;
		}

		$this->logger->warning(
			'Cannot block autopromotion to {target}',
			[ 'target' => $target->getName() ]
		);
		return false;
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
