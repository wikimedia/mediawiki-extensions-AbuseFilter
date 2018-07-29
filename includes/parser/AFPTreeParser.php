<?php

/**
 * A version of the abuse filter parser that separates parsing the filter and
 * evaluating it into different passes, allowing the parse tree to be cached.
 *
 * @file
 */

/**
 * A parser that transforms the text of the filter into a parse tree.
 */
class AFPTreeParser {
	// The tokenized representation of the filter parsed.
	public $mTokens;

	// Current token handled by the parser and its position.
	public $mCur, $mPos;

	const CACHE_VERSION = 2;

	/**
	 * Create a new instance
	 */
	public function __construct() {
		$this->resetState();
	}

	/**
	 * Resets the state
	 */
	public function resetState() {
		$this->mTokens = [];
		$this->mPos = 0;
	}

	/**
	 * Advances the parser to the next token in the filter code.
	 */
	protected function move() {
		list( $this->mCur, $this->mPos ) = $this->mTokens[$this->mPos];
	}

	/**
	 * getState() function allows parser state to be rollbacked to several tokens
	 * back.
	 *
	 * @return AFPParserState
	 */
	protected function getState() {
		return new AFPParserState( $this->mCur, $this->mPos );
	}

	/**
	 * setState() function allows parser state to be rollbacked to several tokens
	 * back.
	 *
	 * @param AFPParserState $state
	 */
	protected function setState( AFPParserState $state ) {
		$this->mCur = $state->token;
		$this->mPos = $state->pos;
	}

	/**
	 * Parse the supplied filter source code into a tree.
	 *
	 * @param string $code
	 * @throws AFPUserVisibleException
	 * @return AFPTreeNode|null
	 */
	public function parse( $code ) {
		$this->mTokens = AbuseFilterTokenizer::tokenize( $code );
		$this->mPos = 0;

		return $this->doLevelEntry();
	}

	/* Levels */

	/**
	 * Handles unexpected characters after the expression.
	 * @return AFPTreeNode|null
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelEntry() {
		$result = $this->doLevelSemicolon();

		if ( $this->mCur->type != AFPToken::TNONE ) {
			throw new AFPUserVisibleException(
				'unexpectedatend',
				$this->mPos, [ $this->mCur->type ]
			);
		}

		return $result;
	}

	/**
	 * Handles the semicolon operator.
	 *
	 * @return AFPTreeNode|null
	 */
	protected function doLevelSemicolon() {
		$statements = [];

		do {
			$this->move();
			$position = $this->mPos;

			if ( $this->mCur->type == AFPToken::TNONE ) {
				break;
			}

			// Allow empty statements.
			if ( $this->mCur->type == AFPToken::TSTATEMENTSEPARATOR ) {
				continue;
			}

			$statements[] = $this->doLevelSet();
			$position = $this->mPos;
		} while ( $this->mCur->type == AFPToken::TSTATEMENTSEPARATOR );

		// Flatten the tree if possible.
		if ( count( $statements ) == 0 ) {
			return null;
		} elseif ( count( $statements ) == 1 ) {
			return $statements[0];
		} else {
			return new AFPTreeNode( AFPTreeNode::SEMICOLON, $statements, $position );
		}
	}

	/**
	 * Handles variable assignment.
	 *
	 * @return AFPTreeNode
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelSet() {
		if ( $this->mCur->type == AFPToken::TID ) {
			$varname = $this->mCur->value;

			// Speculatively parse the assignment statement assuming it can
			// potentially be an assignment, but roll back if it isn't.
			$initialState = $this->getState();
			$this->move();

			if ( $this->mCur->type == AFPToken::TOP && $this->mCur->value == ':=' ) {
				$position = $this->mPos;
				$this->move();
				$value = $this->doLevelSet();

				return new AFPTreeNode( AFPTreeNode::ASSIGNMENT, [ $varname, $value ], $position );
			}

			if ( $this->mCur->type == AFPToken::TSQUAREBRACKET && $this->mCur->value == '[' ) {
				$this->move();

				if ( $this->mCur->type == AFPToken::TSQUAREBRACKET && $this->mCur->value == ']' ) {
					$index = 'append';
				} else {
					// Parse index offset.
					$this->setState( $initialState );
					$this->move();
					$index = $this->doLevelSemicolon();
					if ( !( $this->mCur->type == AFPToken::TSQUAREBRACKET && $this->mCur->value == ']' ) ) {
						throw new AFPUserVisibleException( 'expectednotfound', $this->mPos,
							[ ']', $this->mCur->type, $this->mCur->value ] );
					}
				}

				$this->move();
				if ( $this->mCur->type == AFPToken::TOP && $this->mCur->value == ':=' ) {
					$position = $this->mPos;
					$this->move();
					$value = $this->doLevelSet();
					if ( $index === 'append' ) {
						return new AFPTreeNode(
							AFPTreeNode::ARRAY_APPEND, [ $varname, $value ], $position );
					} else {
						return new AFPTreeNode(
							AFPTreeNode::INDEX_ASSIGNMENT,
							[ $varname, $index, $value ],
							$position
						);
					}
				}
			}

			// If we reached this point, we did not find an assignment.  Roll back
			// and assume this was just a literal.
			$this->setState( $initialState );
		}

		return $this->doLevelConditions();
	}

	/**
	 * Handles ternary operator and if-then-else-end.
	 *
	 * @return AFPTreeNode
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelConditions() {
		if ( $this->mCur->type == AFPToken::TKEYWORD && $this->mCur->value == 'if' ) {
			$position = $this->mPos;
			$this->move();
			$condition = $this->doLevelBoolOps();

			if ( !( $this->mCur->type == AFPToken::TKEYWORD && $this->mCur->value == 'then' ) ) {
				throw new AFPUserVisibleException( 'expectednotfound',
					$this->mPos,
					[
						'then',
						$this->mCur->type,
						$this->mCur->value
					]
				);
			}
			$this->move();

			$valueIfTrue = $this->doLevelConditions();

			if ( !( $this->mCur->type == AFPToken::TKEYWORD && $this->mCur->value == 'else' ) ) {
				throw new AFPUserVisibleException( 'expectednotfound',
					$this->mPos,
					[
						'else',
						$this->mCur->type,
						$this->mCur->value
					]
				);
			}
			$this->move();

			$valueIfFalse = $this->doLevelConditions();

			if ( !( $this->mCur->type == AFPToken::TKEYWORD && $this->mCur->value == 'end' ) ) {
				throw new AFPUserVisibleException( 'expectednotfound',
					$this->mPos,
					[
						'end',
						$this->mCur->type,
						$this->mCur->value
					]
				);
			}
			$this->move();

			return new AFPTreeNode(
				AFPTreeNode::CONDITIONAL,
				[ $condition, $valueIfTrue, $valueIfFalse ],
				$position
			);
		}

		$condition = $this->doLevelBoolOps();
		if ( $this->mCur->type == AFPToken::TOP && $this->mCur->value == '?' ) {
			$position = $this->mPos;
			$this->move();

			$valueIfTrue = $this->doLevelConditions();
			if ( !( $this->mCur->type == AFPToken::TOP && $this->mCur->value == ':' ) ) {
				throw new AFPUserVisibleException( 'expectednotfound',
					$this->mPos,
					[
						':',
						$this->mCur->type,
						$this->mCur->value
					]
				);
			}
			$this->move();

			$valueIfFalse = $this->doLevelConditions();
			return new AFPTreeNode(
				AFPTreeNode::CONDITIONAL,
				[ $condition, $valueIfTrue, $valueIfFalse ],
				$position
			);
		}

		return $condition;
	}

	/**
	 * Handles logic operators.
	 *
	 * @return AFPTreeNode
	 */
	protected function doLevelBoolOps() {
		$leftOperand = $this->doLevelCompares();
		$ops = [ '&', '|', '^' ];
		while ( $this->mCur->type == AFPToken::TOP && in_array( $this->mCur->value, $ops ) ) {
			$op = $this->mCur->value;
			$position = $this->mPos;
			$this->move();

			$rightOperand = $this->doLevelCompares();

			$leftOperand = new AFPTreeNode(
				AFPTreeNode::LOGIC,
				[ $op, $leftOperand, $rightOperand ],
				$position
			);
		}
		return $leftOperand;
	}

	/**
	 * Handles comparison operators.
	 *
	 * @return AFPTreeNode
	 */
	protected function doLevelCompares() {
		$leftOperand = $this->doLevelSumRels();
		$ops = [ '==', '===', '!=', '!==', '<', '>', '<=', '>=', '=' ];
		while ( $this->mCur->type == AFPToken::TOP && in_array( $this->mCur->value, $ops ) ) {
			$op = $this->mCur->value;
			$position = $this->mPos;
			$this->move();
			$rightOperand = $this->doLevelSumRels();
			$leftOperand = new AFPTreeNode(
				AFPTreeNode::COMPARE,
				[ $op, $leftOperand, $rightOperand ],
				$position
			);
		}
		return $leftOperand;
	}

	/**
	 * Handle addition and subtraction.
	 *
	 * @return AFPTreeNode
	 */
	protected function doLevelSumRels() {
		$leftOperand = $this->doLevelMulRels();
		$ops = [ '+', '-' ];
		while ( $this->mCur->type == AFPToken::TOP && in_array( $this->mCur->value, $ops ) ) {
			$op = $this->mCur->value;
			$position = $this->mPos;
			$this->move();
			$rightOperand = $this->doLevelMulRels();
			$leftOperand = new AFPTreeNode(
				AFPTreeNode::SUM_REL,
				[ $op, $leftOperand, $rightOperand ],
				$position
			);
		}
		return $leftOperand;
	}

	/**
	 * Handles multiplication and division.
	 *
	 * @return AFPTreeNode
	 */
	protected function doLevelMulRels() {
		$leftOperand = $this->doLevelPow();
		$ops = [ '*', '/', '%' ];
		while ( $this->mCur->type == AFPToken::TOP && in_array( $this->mCur->value, $ops ) ) {
			$op = $this->mCur->value;
			$position = $this->mPos;
			$this->move();
			$rightOperand = $this->doLevelPow();
			$leftOperand = new AFPTreeNode(
				AFPTreeNode::MUL_REL,
				[ $op, $leftOperand, $rightOperand ],
				$position
			);
		}
		return $leftOperand;
	}

	/**
	 * Handles exponentiation.
	 *
	 * @return AFPTreeNode
	 */
	protected function doLevelPow() {
		$base = $this->doLevelBoolInvert();
		while ( $this->mCur->type == AFPToken::TOP && $this->mCur->value == '**' ) {
			$position = $this->mPos;
			$this->move();
			$exponent = $this->doLevelBoolInvert();
			$base = new AFPTreeNode( AFPTreeNode::POW, [ $base, $exponent ], $position );
		}
		return $base;
	}

	/**
	 * Handles boolean inversion.
	 *
	 * @return AFPTreeNode
	 */
	protected function doLevelBoolInvert() {
		if ( $this->mCur->type == AFPToken::TOP && $this->mCur->value == '!' ) {
			$position = $this->mPos;
			$this->move();
			$argument = $this->doLevelKeywordOperators();
			return new AFPTreeNode( AFPTreeNode::BOOL_INVERT, [ $argument ], $position );
		}

		return $this->doLevelKeywordOperators();
	}

	/**
	 * Handles keyword operators.
	 *
	 * @return AFPTreeNode
	 */
	protected function doLevelKeywordOperators() {
		$leftOperand = $this->doLevelUnarys();
		$keyword = strtolower( $this->mCur->value );
		if ( $this->mCur->type == AFPToken::TKEYWORD &&
			isset( AbuseFilterParser::$mKeywords[$keyword] )
		) {
			$position = $this->mPos;
			$this->move();
			$rightOperand = $this->doLevelUnarys();

			return new AFPTreeNode(
				AFPTreeNode::KEYWORD_OPERATOR,
				[ $keyword, $leftOperand, $rightOperand ],
				$position
			);
		}

		return $leftOperand;
	}

	/**
	 * Handles unary operators.
	 *
	 * @return AFPTreeNode
	 */
	protected function doLevelUnarys() {
		$op = $this->mCur->value;
		if ( $this->mCur->type == AFPToken::TOP && ( $op == "+" || $op == "-" ) ) {
			$position = $this->mPos;
			$this->move();
			$argument = $this->doLevelArrayElements();
			return new AFPTreeNode( AFPTreeNode::UNARY, [ $op, $argument ], $position );
		}
		return $this->doLevelArrayElements();
	}

	/**
	 * Handles accessing an array element by an offset.
	 *
	 * @return AFPTreeNode
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelArrayElements() {
		$array = $this->doLevelParenthesis();
		while ( $this->mCur->type == AFPToken::TSQUAREBRACKET && $this->mCur->value == '[' ) {
			$position = $this->mPos;
			$index = $this->doLevelSemicolon();
			$array = new AFPTreeNode( AFPTreeNode::ARRAY_INDEX, [ $array, $index ], $position );

			if ( !( $this->mCur->type == AFPToken::TSQUAREBRACKET && $this->mCur->value == ']' ) ) {
				throw new AFPUserVisibleException( 'expectednotfound', $this->mPos,
					[ ']', $this->mCur->type, $this->mCur->value ] );
			}
			$this->move();
		}

		return $array;
	}

	/**
	 * Handles parenthesis.
	 *
	 * @return AFPTreeNode
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelParenthesis() {
		if ( $this->mCur->type == AFPToken::TBRACE && $this->mCur->value == '(' ) {
			$result = $this->doLevelSemicolon();

			if ( !( $this->mCur->type == AFPToken::TBRACE && $this->mCur->value == ')' ) ) {
				throw new AFPUserVisibleException(
					'expectednotfound',
					$this->mPos,
					[ ')', $this->mCur->type, $this->mCur->value ]
				);
			}
			$this->move();

			return $result;
		}

		return $this->doLevelFunction();
	}

	/**
	 * Handles function calls.
	 *
	 * @return AFPTreeNode
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelFunction() {
		if ( $this->mCur->type == AFPToken::TID &&
			isset( AbuseFilterParser::$mFunctions[$this->mCur->value] )
		) {
			$func = $this->mCur->value;
			$position = $this->mPos;
			$this->move();
			if ( $this->mCur->type != AFPToken::TBRACE || $this->mCur->value != '(' ) {
				throw new AFPUserVisibleException( 'expectednotfound',
					$this->mPos,
					[
						'(',
						$this->mCur->type,
						$this->mCur->value
					]
				);
			}

			$args = [];
			do {
				$args[] = $this->doLevelSemicolon();
			} while ( $this->mCur->type == AFPToken::TCOMMA );

			if ( $this->mCur->type != AFPToken::TBRACE || $this->mCur->value != ')' ) {
				throw new AFPUserVisibleException( 'expectednotfound',
					$this->mPos,
					[
						')',
						$this->mCur->type,
						$this->mCur->value
					]
				);
			}
			$this->move();

			array_unshift( $args, $func );
			return new AFPTreeNode( AFPTreeNode::FUNCTION_CALL, $args, $position );
		}

		return $this->doLevelAtom();
	}

	/**
	 * Handle literals.
	 * @return AFPTreeNode
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelAtom() {
		$tok = $this->mCur->value;
		switch ( $this->mCur->type ) {
			case AFPToken::TID:
			case AFPToken::TSTRING:
			case AFPToken::TFLOAT:
			case AFPToken::TINT:
				$result = new AFPTreeNode( AFPTreeNode::ATOM, $this->mCur, $this->mPos );
				break;
			case AFPToken::TKEYWORD:
				if ( in_array( $tok, [ "true", "false", "null" ] ) ) {
					$result = new AFPTreeNode( AFPTreeNode::ATOM, $this->mCur, $this->mPos );
					break;
				}

				throw new AFPUserVisibleException(
					'unrecognisedkeyword',
					$this->mPos,
					[ $tok ]
				);
			/** @noinspection PhpMissingBreakStatementInspection */
			case AFPToken::TSQUAREBRACKET:
				if ( $this->mCur->value == '[' ) {
					$array = [];
					while ( true ) {
						$this->move();
						if ( $this->mCur->type == AFPToken::TSQUAREBRACKET && $this->mCur->value == ']' ) {
							break;
						}

						$array[] = $this->doLevelSet();

						if ( $this->mCur->type == AFPToken::TSQUAREBRACKET && $this->mCur->value == ']' ) {
							break;
						}
						if ( $this->mCur->type != AFPToken::TCOMMA ) {
							throw new AFPUserVisibleException(
								'expectednotfound',
								$this->mPos,
								[ ', or ]', $this->mCur->type, $this->mCur->value ]
							);
						}
					}

					$result = new AFPTreeNode( AFPTreeNode::ARRAY_DEFINITION, $array, $this->mPos );
					break;
				}

			// Fallthrough expected
			default:
				throw new AFPUserVisibleException(
					'unexpectedtoken',
					$this->mPos,
					[
						$this->mCur->type,
						$this->mCur->value
					]
				);
		}

		$this->move();
		return $result;
	}
}
