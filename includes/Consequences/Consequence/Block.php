<?php

namespace MediaWiki\Extension\AbuseFilter\Consequences\Consequence;

use ManualLogEntry;
use MediaWiki\Block\BlockUserFactory;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use MediaWiki\Extension\AbuseFilter\GlobalNameUtils;
use MediaWiki\User\UserIdentity;
use MessageLocalizer;
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
	 * @param MessageLocalizer $messageLocalizer
	 */
	public function __construct(
		Parameters $params,
		string $expiry,
		bool $preventTalkEdit,
		BlockUserFactory $blockUserFactory,
		DatabaseBlockStore $databaseBlockStore,
		FilterUser $filterUser,
		MessageLocalizer $messageLocalizer
	) {
		parent::__construct( $params, $expiry, $blockUserFactory, $filterUser, $messageLocalizer );
		$this->databaseBlockStore = $databaseBlockStore;
		$this->preventsTalkEdit = $preventTalkEdit;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() : bool {
		$status = $this->doBlockInternal(
			$this->parameters->getFilter()->getName(),
			$this->parameters->getFilter()->getID(),
			$this->parameters->getUser()->getName(),
			$this->expiry,
			$autoblock = true,
			$this->preventsTalkEdit
		);
		// TODO: Should we reblock in case of partial blocks? At that point we could return
		// the status of doBlockInternal
		return defined( 'MW_PHPUNIT_TEST' ) ? $status->isOK() : true;
	}

	/**
	 * @inheritDoc
	 * @todo This could use UnblockUser, but we need to check if the block was performed by the AF user
	 */
	public function revert( $info, UserIdentity $performer, string $reason ): bool {
		// TODO: DI once T255433 is resolved
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
		$id = $logEntry->insert();
		if ( !defined( 'MW_PHPUNIT_TEST' ) ) {
			// This has a bazillion of static dependencies all around the place, and a nightmare to deal with in tests
			// TODO: Remove this check once T253717 is resolved
			// @codeCoverageIgnoreStart
			$logEntry->publish( $id );
			// @codeCoverageIgnoreEnd
		}
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getMessage(): array {
		$filter = $this->parameters->getFilter();
		return [
			'abusefilter-blocked-display',
			$filter->getName(),
			GlobalNameUtils::buildGlobalName( $filter->getID(), $this->parameters->getIsGlobalFilter() )
		];
	}
}
