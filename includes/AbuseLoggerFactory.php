<?php

namespace MediaWiki\Extension\AbuseFilter;

use AbuseFilterVariableHolder;
use MediaWiki\Config\ServiceOptions;
use Title;
use User;
use Wikimedia\Rdbms\ILoadBalancer;

class AbuseLoggerFactory {
	public const SERVICE_NAME = 'AbuseFilterAbuseLoggerFactory';

	/** @var CentralDBManager */
	private $centralDBManager;
	/** @var FilterLookup */
	private $filterLookup;
	/** @var ILoadBalancer */
	private $loadBalancer;
	/** @var ServiceOptions */
	private $options;
	/** @var string */
	private $wikiID;
	/** @var string */
	private $requestIP;

	/**
	 * @param CentralDBManager $centralDBManager
	 * @param FilterLookup $filterLookup
	 * @param ILoadBalancer $loadBalancer
	 * @param ServiceOptions $options
	 * @param string $wikiID
	 * @param string $requestIP
	 */
	public function __construct(
		CentralDBManager $centralDBManager,
		FilterLookup $filterLookup,
		ILoadBalancer $loadBalancer,
		ServiceOptions $options,
		string $wikiID,
		string $requestIP
	) {
		$this->centralDBManager = $centralDBManager;
		$this->filterLookup = $filterLookup;
		$this->loadBalancer = $loadBalancer;
		$this->options = $options;
		$this->wikiID = $wikiID;
		$this->requestIP = $requestIP;
	}

	/**
	 * @param Title $title
	 * @param User $user
	 * @param AbuseFilterVariableHolder $vars
	 * @return AbuseLogger
	 */
	public function newLogger(
		Title $title,
		User $user,
		AbuseFilterVariableHolder $vars
	) : AbuseLogger {
		return new AbuseLogger(
			$this->centralDBManager,
			$this->filterLookup,
			$this->loadBalancer,
			$this->options,
			$this->wikiID,
			$this->requestIP,
			$title,
			$user,
			$vars
		);
	}
}
