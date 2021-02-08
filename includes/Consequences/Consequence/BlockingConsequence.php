<?php

namespace MediaWiki\Extension\AbuseFilter\Consequences\Consequence;

use LogPage;
use MediaWiki\Block\BlockUserFactory;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use MessageLocalizer;
use Status;
use User;
use Wikimedia\IPUtils;

/**
 * Base class for consequences that block a user
 */
abstract class BlockingConsequence extends Consequence implements HookAborterConsequence {
	/** @var BlockUserFactory */
	private $blockUserFactory;

	/** @var FilterUser */
	protected $filterUser;

	/** @var MessageLocalizer */
	private $messageLocalizer;

	/** @var string Expiry of the block */
	protected $expiry;

	/**
	 * @param Parameters $params
	 * @param string $expiry
	 * @param BlockUserFactory $blockUserFactory
	 * @param FilterUser $filterUser
	 * @param MessageLocalizer $messageLocalizer
	 */
	public function __construct(
		Parameters $params,
		string $expiry,
		BlockUserFactory $blockUserFactory,
		FilterUser $filterUser,
		MessageLocalizer $messageLocalizer
	) {
		parent::__construct( $params );
		$this->expiry = $expiry;
		$this->blockUserFactory = $blockUserFactory;
		$this->filterUser = $filterUser;
		$this->messageLocalizer = $messageLocalizer;
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
		$reason = $this->messageLocalizer->msg(
			'abusefilter-blockreason',
			$ruleDesc,
			$ruleNumber
		)->inContentLanguage()->text();

		$blockUser = $this->blockUserFactory->newBlockUser(
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
		);
		if (
			strpos( $this->parameters->getAction(), 'createaccount' ) !== false &&
			IPUtils::isIPAddress( $target )
		) {
			$blockUser->setLogDeletionFlags( LogPage::SUPPRESSED_ACTION );
		}
		return $blockUser->placeBlockUnsafe();
	}
}
