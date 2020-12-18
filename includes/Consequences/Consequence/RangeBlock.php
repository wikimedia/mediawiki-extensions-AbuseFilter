<?php

namespace MediaWiki\Extension\AbuseFilter\Consequences\Consequence;

use MediaWiki\Block\BlockUserFactory;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use Wikimedia\IPUtils;

/**
 * Consequence that blocks an IP range (retrieved from the current request for both anons and registered users).
 */
class RangeBlock extends BlockingConsequence {
	/** @var int[] */
	private $rangeBlockSize;
	/** @var int[] */
	private $blockCIDRLimit;
	/** @var string */
	private $requestIP;

	/**
	 * @param Parameters $parameters
	 * @param string $expiry
	 * @param BlockUserFactory $blockUserFactory
	 * @param FilterUser $filterUser
	 * @param array $rangeBlockSize
	 * @param array $blockCIDRLimit
	 * @param string $requestIP
	 */
	public function __construct(
		Parameters $parameters,
		string $expiry,
		BlockUserFactory $blockUserFactory,
		FilterUser $filterUser,
		array $rangeBlockSize,
		array $blockCIDRLimit,
		string $requestIP
	) {
		parent::__construct( $parameters, $expiry, $blockUserFactory, $filterUser );
		$this->rangeBlockSize = $rangeBlockSize;
		$this->blockCIDRLimit = $blockCIDRLimit;
		$this->requestIP = $requestIP;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() : bool {
		$type = IPUtils::isIPv6( $this->requestIP ) ? 'IPv6' : 'IPv4';
		$CIDRsize = max( $this->rangeBlockSize[$type], $this->blockCIDRLimit[$type] );
		$blockCIDR = $this->requestIP . '/' . $CIDRsize;

		$target = IPUtils::sanitizeRange( $blockCIDR );
		$this->doBlockInternal(
			$this->parameters->getFilter()->getName(),
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->parameters->getFilter()->getID(),
			$target,
			$this->expiry,
			$autoblock = false,
			$preventTalk = false
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
