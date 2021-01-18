<?php

namespace MediaWiki\Extension\AbuseFilter\Consequences\Consequence;

use LogicException;
use MediaWiki\Extension\AbuseFilter\Consequences\Parameters;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use Title;

/**
 * BC class for custom consequences specified via $wgAbuseFilterCustomActionsHandlers
 * @internal Temporary class
 * @codeCoverageIgnore
 */
class BCConsequence extends Consequence implements HookAborterConsequence {
	/** @var array */
	private $rawParams;
	/** @var VariableHolder */
	private $vars;
	/** @var callable */
	private $callback;

	/** @var string|null */
	private $message;

	/**
	 * @param Parameters $parameters
	 * @param array $rawParams Parameters as stored in the DB
	 * @param VariableHolder $vars
	 * @param callable $cb
	 */
	public function __construct(
		Parameters $parameters,
		array $rawParams,
		VariableHolder $vars,
		callable $cb
	) {
		parent::__construct( $parameters );
		$this->rawParams = $rawParams;
		$this->vars = $vars;
		$this->callback = $cb;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() : bool {
		$msg = call_user_func(
			$this->callback,
			$this->parameters->getAction(),
			$this->rawParams,
			Title::castFromLinkTarget( $this->parameters->getTarget() ),
			$this->vars,
			$this->parameters->getFilter()->getName(),
			$this->parameters->getFilter()->getID()
		);
		$this->message = $msg;
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getMessage(): array {
		if ( $this->message === null ) {
			throw new LogicException( 'No message, did you call execute()?' );
		}
		return [ $this->message ];
	}
}
