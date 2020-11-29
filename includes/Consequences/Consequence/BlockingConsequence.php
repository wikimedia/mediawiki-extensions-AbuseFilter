<?php

namespace MediaWiki\Extension\AbuseFilter\Consequences\Consequence;

use MediaWiki\Block\BlockUserFactory;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use Status;
use User;

/**
 * Base class for consequences that block a user
 */
abstract class BlockingConsequence extends Consequence implements HookAborterConsequence {
	/** @var BlockUserFactory */
	private $blockUserFactory;

	/** @var FilterUser */
	protected $filterUser;

	/** @var string Expiry of the block */
	protected $expiry;

	/**
	 * @param Parameters $params
	 * @param string $expiry
	 * @param BlockUserFactory $blockUserFactory
	 * @param FilterUser $filterUser
	 */
	public function __construct(
		Parameters $params,
		string $expiry,
		BlockUserFactory $blockUserFactory,
		FilterUser $filterUser
	) {
		parent::__construct( $params );
		$this->expiry = $expiry;
		$this->blockUserFactory = $blockUserFactory;
		$this->filterUser = $filterUser;
	}

	/**
	 * Perform a block by the AbuseFilter system user
	 * @param string $ruleDesc
	 * @param int|string $ruleNumber
	 * @param string $target
	 * @param string $expiry
	 * @param bool $isAutoBlock
	 * @param bool $preventEditOwnUserTalk
	 * @return Status
	 */
	protected function doBlockInternal(
		string $ruleDesc,
		$ruleNumber,
		string $target,
		string $expiry,
		bool $isAutoBlock,
		bool $preventEditOwnUserTalk
	) : Status {
		$reason = wfMessage( 'abusefilter-blockreason', $ruleDesc, $ruleNumber )->inContentLanguage()->text();

		return $this->blockUserFactory->newBlockUser(
			$target,
			// TODO: Avoid User here (T266409)
			User::newFromIdentity( $this->filterUser->getUser() ),
			$expiry,
			$reason,
			[
				'isHardBlock' => false,
				'isAutoblocking' => $isAutoBlock,
				'isCreateAccountBlocked' => true,
				'isUserTalkEditBlocked' => $preventEditOwnUserTalk
			]
		)->placeBlockUnsafe();
	}
}
