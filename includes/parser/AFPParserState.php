<?php

class AFPParserState {
	public $pos, $token;

	/**
	 * @param AFPToken $token
	 * @param int $pos
	 */
	public function __construct( AFPToken $token, $pos ) {
		$this->token = $token;
		$this->pos = $pos;
	}
}
