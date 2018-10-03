<?php
/**
 * Abuse filter parser.
 * Copyright © Victor Vasiliev, 2008.
 * Based on ideas by Andrew Garrett
 * Distributed under GNU GPL v2 terms.
 *
 * Types of token:
 * * T_NONE - special-purpose token
 * * T_BRACE  - ( or )
 * * T_COMMA - ,
 * * T_OP - operator like + or ^
 * * T_NUMBER - number
 * * T_STRING - string, in "" or ''
 * * T_KEYWORD - keyword
 * * T_ID - identifier
 * * T_STATEMENT_SEPARATOR - ;
 * * T_SQUARE_BRACKETS - [ or ]
 *
 * Levels of parsing:
 * * Entry - catches unexpected characters
 * * Semicolon - ;
 * * Set - :=
 * * Conditionals (IF) - if-then-else-end, cond ? a :b
 * * BoolOps (BO) - &, |, ^
 * * CompOps (CO) - ==, !=, ===, !==, >, <, >=, <=
 * * SumRel (SR) - +, -
 * * MulRel (MR) - *, /, %
 * * Pow (P) - **
 * * BoolNeg (BN) - ! operation
 * * SpecialOperators (SO) - in and like
 * * Unarys (U) - plus and minus in cases like -5 or -(2 * +2)
 * * ArrayElement (AE) - array[number]
 * * Braces (B) - ( and )
 * * Functions (F)
 * * Atom (A) - return value
 */
class AFPToken {
	const TNONE = 'T_NONE';
	const TID = 'T_ID';
	const TKEYWORD = 'T_KEYWORD';
	const TSTRING = 'T_STRING';
	const TINT = 'T_INT';
	const TFLOAT = 'T_FLOAT';
	const TOP = 'T_OP';
	const TBRACE = 'T_BRACE';
	const TSQUAREBRACKET = 'T_SQUARE_BRACKET';
	const TCOMMA = 'T_COMMA';
	const TSTATEMENTSEPARATOR = 'T_STATEMENT_SEPARATOR';

	public $type;
	public $value;
	public $pos;

	/**
	 * @param string $type
	 * @param mixed|null $value
	 * @param int $pos
	 */
	public function __construct( $type = self::TNONE, $value = null, $pos = 0 ) {
		$this->type = $type;
		$this->value = $value;
		$this->pos = $pos;
	}
}
