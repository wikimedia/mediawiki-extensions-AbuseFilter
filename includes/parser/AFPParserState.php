<?php

class AFPParserState {
	public $pos, $token;

	public function __construct( $token, $pos ) {
		$this->token = $token;
		$this->pos = $pos;
	}
}
