<?php

namespace MediaWiki\Extension\AbuseFilter\Consequences\Consequence;

use ManualLogEntry;
use MediaWiki\Block\BlockUserFactory;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use MediaWiki\User\UserIdentity;
use TitleValue;

/**
 * Consequence that blocks a single user.
 */
class Block extends BlockingConsequence implements ReversibleConsequence {
	/** @var bool */
	private $preventsTalkEdit;
	/** @var DatabaseBlockStore */
	private $databaseBlockStore;

	/**
	 * @param Parameters $params
	 * @param string $expiry
	 * @param bool $preventTalkEdit
	 * @param BlockUserFactory $blockUserFactory
	 * @param DatabaseBlockStore $databaseBlockStore
	 * @param FilterUser $filterUser
	 */
	public function __construct(
		Parameters $params,
		string $expiry,
		bool $preventTalkEdit,
		BlockUserFactory $blockUserFactory,
		DatabaseBlockStore $databaseBlockStore,
		FilterUser $filterUser
	) {
		parent::__construct( $params, $expiry, $blockUserFactory, $filterUser );
		$this->databaseBlockStore = $databaseBlockStore;
		$this->preventsTalkEdit = $preventTalkEdit;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() : bool {
		$status = $this->doBlockInternal(
			$this->parameters->getFilter()->getName(),
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->parameters->getFilter()->getID(),
			$this->parameters->getUser()->getName(),
			$this->expiry,
			$autoblock = true,
			$this->preventsTalkEdit
		);
		return $status->isOK();
	}

	/**
	 * @inheritDoc
	 */
	public function revert( $info, UserIdentity $performer, string $reason ): bool {
		$block = DatabaseBlock::newFromTarget( $this->parameters->getUser()->getName() );
		if ( !( $block && $block->getBy() === $this->filterUser->getUser()->getId() ) ) {
			// Not blocked by abuse filter
			return false;
		}
		if ( !$this->databaseBlockStore->deleteBlock( $block ) ) {
			return false;
		}
		$logEntry = new ManualLogEntry( 'block', 'unblock' );
		$logEntry->setTarget( new TitleValue( NS_USER, $this->parameters->getUser()->getName() ) );
		$logEntry->setComment( $reason );
		$logEntry->setPerformer( $performer );
		$logEntry->publish( $logEntry->insert() );
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
