<?php

namespace MediaWiki\Extension\AbuseFilter\Consequence;

use BagOStuff;
use Psr\Log\LoggerInterface;
use Title;
use User;
use Wikimedia\IPUtils;

/**
 * Consequence that delays executing other actions until certain conditions are met
 */
class Throttle extends Consequence implements ConsequencesDisablerConsequence {
	/** @var array */
	private $throttleParams;
	/** @var BagOStuff */
	private $mainStash;
	/** @var LoggerInterface */
	private $logger;
	/** @var string */
	private $requestIP;
	/** @var $bool */
	private $filterIsCentral;
	/** @var string|null */
	private $centralDB;

	/** @var bool|null */
	private $hitThrottle;

	/**
	 * @param Parameters $parameters
	 * @param array $throttleParams
	 * @phan-param array{groups:string[],id:int|string,count:int,period:int} $throttleParams
	 * @param BagOStuff $mainStash
	 * @param LoggerInterface $logger
	 * @param string $requestIP
	 * @param bool $filterIsCentral
	 * @param string|null $centralDB
	 */
	public function __construct(
		Parameters $parameters,
		array $throttleParams,
		BagOStuff $mainStash,
		LoggerInterface $logger,
		string $requestIP,
		bool $filterIsCentral,
		?string $centralDB
	) {
		parent::__construct( $parameters );
		$this->throttleParams = $throttleParams;
		$this->mainStash = $mainStash;
		$this->logger = $logger;
		$this->requestIP = $requestIP;
		$this->filterIsCentral = $filterIsCentral;
		$this->centralDB = $centralDB;
	}

	/**
	 * @return bool Whether the throttling took place (i.e. the limit was NOT hit)
	 * @throws ConsequenceNotPrecheckedException
	 */
	public function execute() : bool {
		if ( $this->hitThrottle === null ) {
			throw new ConsequenceNotPrecheckedException();
		}
		foreach ( $this->throttleParams['groups'] as $throttleType ) {
			$this->setThrottled( $throttleType );
		}
		return !$this->hitThrottle;
	}

	/**
	 * @inheritDoc
	 */
	public function shouldDisableOtherConsequences() : bool {
		$this->hitThrottle = false;
		foreach ( $this->throttleParams['groups'] as $throttleType ) {
			$this->hitThrottle = $this->isThrottled( $throttleType ) || $this->hitThrottle;
		}
		return !$this->hitThrottle;
	}

	/**
	 * @inheritDoc
	 */
	public function getSort(): int {
		return 0;
	}

	/**
	 * Determines whether the throttle has been hit with the given parameters
	 * @note If caching is disabled, get() will return false, so the throttle count will never be reached (if >0).
	 *  This means that filters with 'throttle' enabled won't ever trigger any consequence.
	 *
	 * @param string $types
	 * @return bool
	 */
	protected function isThrottled( string $types ) : bool {
		$key = $this->throttleKey( $this->throttleParams['id'], $types, $this->parameters->getIsGlobalFilter() );
		$newCount = (int)$this->mainStash->get( $key ) + 1;

		$this->logger->debug(
			'New value is {newCount} for throttle key {key}. Maximum is {rateCount}.',
			[
				'newCount' => $newCount,
				'key' => $key,
				'rateCount' => $this->throttleParams['count'],
			]
		);

		return $newCount > $this->throttleParams['count'];
	}

	/**
	 * Updates the throttle status with the given parameters
	 *
	 * @param string $types
	 */
	protected function setThrottled( string $types ) : void {
		$key = $this->throttleKey( $this->throttleParams['id'], $types, $this->parameters->getIsGlobalFilter() );
		$this->logger->debug(
			'Increasing throttle key {key}',
			[ 'key' => $key ]
		);
		$this->mainStash->incrWithInit( $key, $this->throttleParams['period'] );
	}

	/**
	 * @param string $throttleId
	 * @param string $type
	 * @param bool $global
	 * @return string
	 */
	private function throttleKey( string $throttleId, string $type, bool $global = false ) : string {
		$types = explode( ',', $type );

		$identifiers = [];

		foreach ( $types as $subtype ) {
			$identifiers[] = $this->throttleIdentifier( $subtype );
		}

		$identifier = sha1( implode( ':', $identifiers ) );

		if ( $global && !$this->filterIsCentral ) {
			return $this->mainStash->makeGlobalKey(
				'abusefilter', 'throttle', $this->centralDB, $throttleId, $type, $identifier
			);
		}

		return $this->mainStash->makeKey( 'abusefilter', 'throttle', $throttleId, $type, $identifier );
	}

	/**
	 * @param string $type
	 * @return int|string
	 */
	private function throttleIdentifier( string $type ) {
		$user = User::newFromIdentity( $this->parameters->getUser() );
		$title = Title::castFromLinkTarget( $this->parameters->getTarget() );
		switch ( $type ) {
			case 'ip':
				$identifier = $this->requestIP;
				break;
			case 'user':
				$identifier = $user->getId();
				break;
			case 'range':
				$identifier = substr( IPUtils::toHex( $this->requestIP ), 0, 4 );
				break;
			case 'creationdate':
				$reg = (int)$user->getRegistration();
				$identifier = $reg - ( $reg % 86400 );
				break;
			case 'editcount':
				// Hack for detecting different single-purpose accounts.
				$identifier = (int)$user->getEditCount();
				break;
			case 'site':
				$identifier = 1;
				break;
			case 'page':
				$identifier = $title->getPrefixedText();
				break;
			default:
				// Should never happen
				// @codeCoverageIgnoreStart
				$identifier = 0;
				// @codeCoverageIgnoreEnd
		}

		return $identifier;
	}
}
