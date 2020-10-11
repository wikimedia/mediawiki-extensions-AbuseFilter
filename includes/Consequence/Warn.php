<?php

namespace MediaWiki\Extension\AbuseFilter\Consequence;

use MediaWiki\Session\Session;
use Title;

/**
 * Consequence that warns the user once, allowing the action on the second attempt.
 */
class Warn extends Consequence implements HookAborterConsequence, ConsequencesDisablerConsequence {
	/** @var Session */
	private $session;
	/** @var string */
	private $message;
	/** @var bool|null */
	private $shouldWarn;

	/**
	 * @param Parameters $parameters
	 * @param string $message
	 * @param Session $session
	 */
	public function __construct( Parameters $parameters, string $message, Session $session ) {
		parent::__construct( $parameters );
		$this->message = $message;
		$this->session = $session;
	}

	/**
	 * @return bool Whether the user should be warned (i.e. this is the first attempt)
	 * @throws ConsequenceNotPrecheckedException
	 */
	public function execute() : bool {
		if ( $this->shouldWarn === null ) {
			throw new ConsequenceNotPrecheckedException();
		}
		$this->setWarn();
		return $this->shouldWarn;
	}

	/**
	 * @inheritDoc
	 */
	public function shouldDisableOtherConsequences() : bool {
		$this->shouldWarn = $this->shouldBeWarned();
		return $this->shouldWarn;
	}

	/**
	 * @inheritDoc
	 */
	public function getSort(): int {
		return 5;
	}

	/**
	 * @inheritDoc
	 */
	public function getMessage(): array {
		$filter = $this->parameters->getFilter();
		return [
			$this->message,
			$filter->getName(),
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			\AbuseFilter::buildGlobalName( $filter->getID(), $this->parameters->getIsGlobalFilter() )
		];
	}

	/**
	 * @return bool
	 */
	private function shouldBeWarned() : bool {
		// Make sure the session is started prior to using it
		$this->session->persist();
		$warnKey = $this->getWarnKey();
		return ( !isset( $this->session[$warnKey] ) || !$this->session[$warnKey] );
	}

	/**
	 * Sets the parameters needed to warn the user, *without* checking if the user should be warned.
	 */
	private function setWarn() : void {
		$warnKey = $this->getWarnKey();
		$this->session[$warnKey] = $this->shouldWarn;
	}

	/**
	 * Generate a unique key to determine whether the user has already been warned.
	 * We'll warn again if one of these changes: session, page, triggered filter, or action
	 * @return string
	 */
	private function getWarnKey() : string {
		$globalFilterName = \AbuseFilter::buildGlobalName(
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$this->parameters->getFilter()->getID(),
			$this->parameters->getIsGlobalFilter()
		);
		$title = Title::castFromLinkTarget( $this->parameters->getTarget() );
		return 'abusefilter-warned-' . md5( $title->getPrefixedText() ) .
			'-' . $globalFilterName . '-' . $this->parameters->getAction();
	}
}
