<?php

namespace MediaWiki\Extension\AbuseFilter\Consequences\Consequence;

use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\GlobalNameUtils;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNameUtils;
use MessageLocalizer;

/**
 * Consequence that blocks/delays autopromotion of a registered user.
 */
class BlockAutopromote extends Consequence implements HookAborterConsequence, ReversibleConsequence {
	/** @var int */
	private $duration;
	/** @var BlockAutopromoteStore */
	private $blockAutopromoteStore;
	/** @var MessageLocalizer */
	private $messageLocalizer;
	/** @var UserNameUtils */
	private $userNameUtils;

	/**
	 * @param Parameters $params
	 * @param int $duration
	 * @param BlockAutopromoteStore $blockAutopromoteStore
	 * @param MessageLocalizer $messageLocalizer
	 * @param UserNameUtils $userNameUtils
	 */
	public function __construct(
		Parameters $params,
		int $duration,
		BlockAutopromoteStore $blockAutopromoteStore,
		MessageLocalizer $messageLocalizer,
		UserNameUtils $userNameUtils
	) {
		parent::__construct( $params );
		$this->duration = $duration;
		$this->blockAutopromoteStore = $blockAutopromoteStore;
		$this->messageLocalizer = $messageLocalizer;
		$this->userNameUtils = $userNameUtils;
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): bool {
		$target = $this->parameters->getUser();
		$isTemp = $this->userNameUtils->isTemp( $target->getName() );
		if ( !$target->isRegistered() || $isTemp ) {
			return false;
		}

		return $this->blockAutopromoteStore->blockAutoPromote(
			$target,
			$this->messageLocalizer->msg(
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
	public function revert( UserIdentity $performer, string $reason ): bool {
		return $this->blockAutopromoteStore->unblockAutopromote(
			$this->parameters->getUser(),
			$performer,
			$reason
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getMessage(): array {
		$filter = $this->parameters->getFilter();
		return [
			'abusefilter-autopromote-blocked',
			$filter->getName(),
			GlobalNameUtils::buildGlobalName( $filter->getID(), $this->parameters->getIsGlobalFilter() ),
			$this->duration
		];
	}
}
