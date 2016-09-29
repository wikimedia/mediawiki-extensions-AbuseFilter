<?php

/**
 * A version of the abuse filter parser that separates parsing the filter and
 * evaluating it into different passes, allowing the parse tree to be cached.
 *
 * @file
 */

/**
 * Represents a node of a parser tree.
 */
class AFPTreeNode {
	// Each of the constants below represents a node corresponding to a level
	// of the parser, from the top of the tree to the bottom.

	// ENTRY is always one-element and thus does not have its own node.

	// SEMICOLON is a many-children node, denoting that the nodes have to be
	// evaluated in order and the last value has to be returned.
	const SEMICOLON = 'SEMICOLON';

	// ASSIGNMENT (formerly known as SET) is a node which is responsible for
	// assigning values to variables.  ASSIGNMENT is a (variable name [string],
	// value [tree node]) tuple, INDEX_ASSIGNMENT (which is used to assign
	// values at list offsets) is a (variable name [string], index [tree node],
	// value [tree node]) tuple, and LIST_APPEND has the form of (variable name
	// [string], value [tree node]).
	const ASSIGNMENT = 'ASSIGNMENT';
	const INDEX_ASSIGNMENT = 'INDEX_ASSIGNMENT';
	const LIST_APPEND = 'LIST_APPEND';

	// CONDITIONAL represents both a ternary operator and an if-then-else-end
	// construct.  The format is (condition, evaluated-if-true,
	// evaluated-in-false), all tree nodes.
	const CONDITIONAL = 'CONDITIONAL';

	// LOGIC is a logic operator accepted by AFPData::boolOp.  The format is
	// (operation, left operand, right operand).
	const LOGIC = 'LOGIC';

	// COMPARE is a comparison operator accepted by AFPData::boolOp.  The format is
	// (operation, left operand, right operand).
	const COMPARE = 'COMPARE';

	// SUM_REL is either '+' or '-'.  The format is (operation, left operand,
	// right operand).
	const SUM_REL = 'SUM_REL';

	// MUL_REL is a multiplication-related operation accepted by AFPData::mulRel.
	// The format is (operation, left operand, right operand).
	const MUL_REL = 'MUL_REL';

	// POW is an exponentiation operator.  The format is (base, exponent).
	const POW = 'POW';

	// BOOL_INVERT is a boolean inversion operator.  The format is (operand).
	const BOOL_INVERT = 'BOOL_INVERT';

	// KEYWORD_OPERATOR is one of the binary keyword operators supported by the
	// filter language.  The format is (keyword, left operand, right operand).
	const KEYWORD_OPERATOR = 'KEYWORD_OPERATOR';

	// UNARY is either unary minus or unary plus.  The format is (operator,
	// operand).
	const UNARY = 'UNARY';

	// LIST_INDEX is an operation of accessing a list by an offset.  The format
	// is (list, offset).
	const LIST_INDEX = 'LIST_INDEX';

	// Since parenthesis only manipulate precedence of the operators, they are
	// not explicitly represented in the tree.

	// FUNCTION_CALL is an invocation of built-in function.  The format is a
	// tuple where the first element is a function name, and all subsequent
	// elements are the arguments.
	const FUNCTION_CALL = 'FUNCTION_CALL';

	// LIST_DEFINITION is a list literal.  The $children field contains tree
	// nodes for the values of each of the list element used.
	const LIST_DEFINITION = 'LIST_DEFINITION';

	// ATOM is a node representing a literal.  The only element of $children is a
	// token corresponding to the literal.
	const ATOM = 'ATOM';

	/** @var string Type of the node, one of the constants above */
	public $type;
	/**
	 * Parameters of the value. Typically it is an array of children nodes,
	 * which might be either strings (for parametrization of the node) or another
	 * node. In case of ATOM it's a parser token.
	 * @var AFPTreeNode[]|string[]|AFPToken
	 */
	public $children;

	// Position used for error reporting.
	public $position;

	public function __construct( $type, $children, $position ) {
		$this->type = $type;
		$this->children = $children;
		$this->position = $position;
	}

	public function toDebugString() {
		return implode( "\n", $this->toDebugStringInner() );
	}

	private function toDebugStringInner() {
		if ( $this->type == self::ATOM ) {
			return [ "ATOM({$this->children->type} {$this->children->value})" ];
		}

		$align = function ( $line ) {
			return '  ' . $line;
		};

		$lines = [ "{$this->type}" ];
		foreach ( $this->children as $subnode ) {
			if ( $subnode instanceof AFPTreeNode ) {
				$sublines = array_map( $align, $subnode->toDebugStringInner() );
			} elseif ( is_string( $subnode ) ) {
				$sublines = [ "  {$subnode}" ];
			} else {
				throw new AFPException( "Each node parameter has to be either a node or a string" );
			}

			$lines = array_merge( $lines, $sublines );
		}
		return $lines;
	}
}

/**
 * A parser that transforms the text of the filter into a parse tree.
 */
class AFPTreeParser {
	// The tokenized representation of the filter parsed.
	public $mTokens;

	// Current token handled by the parser and its position.
	public $mCur, $mPos;

	const CACHE_VERSION = 1;

	/**
	 * Create a new instance
	 */
	public function __construct() {
		$this->resetState();
	}

	public function resetState() {
		$this->mTokens = array();
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
							AFPTreeNode::LIST_APPEND, [ $varname, $value ], $position );
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
		if ( $this->mCur->type == AFPToken::TOP && in_array( $this->mCur->value, $ops ) ) {
			$op = $this->mCur->value;
			$position = $this->mPos;
			$this->move();

			$rightOperand = $this->doLevelBoolOps();

			return new AFPTreeNode(
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
			in_array( $keyword, array_keys( AbuseFilterParser::$mKeywords ) )
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
			$argument = $this->doLevelListElements();
			return new AFPTreeNode( AFPTreeNode::UNARY, [ $op, $argument ], $position );
		}
		return $this->doLevelListElements();
	}

	/**
	 * Handles accessing a list element by an offset.
	 *
	 * @return AFPTreeNode
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelListElements() {
		$list = $this->doLevelParenthesis();
		while ( $this->mCur->type == AFPToken::TSQUAREBRACKET && $this->mCur->value == '[' ) {
			$position = $this->mPos;
			$index = $this->doLevelSemicolon();
			$list = new AFPTreeNode( AFPTreeNode::LIST_INDEX, [ $list, $index ], $position );

			if ( !( $this->mCur->type == AFPToken::TSQUAREBRACKET && $this->mCur->value == ']' ) ) {
				throw new AFPUserVisibleException( 'expectednotfound', $this->mPos,
					[ ']', $this->mCur->type, $this->mCur->value ] );
			}
			$this->move();
		}

		return $list;
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

			$args = array();
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
					$list = array();
					while ( true ) {
						$this->move();
						if ( $this->mCur->type == AFPToken::TSQUAREBRACKET && $this->mCur->value == ']' ) {
							break;
						}

						$list[] = $this->doLevelSet();

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

					$result = new AFPTreeNode( AFPTreeNode::LIST_DEFINITION, $list, $this->mPos );
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

/**
 * AbuseFilterCachingParser is the version of AbuseFilterParser which parses
 * the code into an abstract syntax tree before evaluating it, and caches that
 * tree.
 *
 * It currently inherits AbuseFilterParser in order to avoid code duplication.
 * In future, this code will replace current AbuseFilterParser entirely.
 */
class AbuseFilterCachingParser extends AbuseFilterParser {
	/**
	 * Return the generated version of the parser for cache invalidation
	 * purposes.  Automatically tracks list of all functions and invalidates the
	 * cache if it is changed.
	 */
	public static function getCacheVersion() {
		static $version = null;
		if ( $version !== null ) {
			return $version;
		}

		$versionKey = [
			AFPTreeParser::CACHE_VERSION,
			AbuseFilterTokenizer::CACHE_VERSION,
			array_keys( AbuseFilterParser::$mFunctions ),
			array_keys( AbuseFilterParser::$mKeywords ),
		];
		$version = hash( 'sha256', serialize( $versionKey ) );

		return $version;
	}

	public function resetState() {
		$this->mVars = new AbuseFilterVariableHolder;
		$this->mCur = new AFPToken();
	}

	public function intEval( $code ) {
		static $cache = null;
		if ( !$cache ) {
			$cache = ObjectCache::getLocalServerInstance( 'hash' );
		}

		$tree = $cache->getWithSetCallback(
			$cache->makeGlobalKey(
				__CLASS__,
				self::getCacheVersion(),
				hash( 'sha256', $code )
			),
			$cache::TTL_DAY,
			function () use ( $code ) {
				$parser = new AFPTreeParser();
				return $parser->parse( $code ) ?: false;
			}
		);

		return $tree
			? $this->evalNode( $tree )
			: new AFPData( AFPData::DNULL, null );
	}

	/**
	 * Evaluate the value of the specified AST node.
	 *
	 * @param AFPTreeNode $node The node to evaluate.
	 * @return AFPData
	 * @throws AFPException
	 * @throws AFPUserVisibleException
	 * @throws MWException
	 */
	public function evalNode( AFPTreeNode $node ) {
		// A lot of AbuseFilterParser features rely on $this->mCur->pos or
		// $this->mPos for error reporting.
		// FIXME: this is a hack which needs to be removed when the parsers are
		// merged.
		$this->mPos = $node->position;
		$this->mCur->pos = $node->position;

		switch ( $node->type ) {
			case AFPTreeNode::ATOM:
				$tok = $node->children;
				switch ( $tok->type ) {
					case AFPToken::TID:
						return $this->getVarValue( strtolower( $tok->value ) );
					case AFPToken::TSTRING:
						return new AFPData( AFPData::DSTRING, $tok->value );
					case AFPToken::TFLOAT:
						return new AFPData( AFPData::DFLOAT, $tok->value );
					case AFPToken::TINT:
						return new AFPData( AFPData::DINT, $tok->value );
					/** @noinspection PhpMissingBreakStatementInspection */
					case AFPToken::TKEYWORD:
						switch ( $tok->value ) {
							case "true":
								return new AFPData( AFPData::DBOOL, true );
							case "false":
								return new AFPData( AFPData::DBOOL, false );
							case "null":
								return new AFPData();
						}
					// Fallthrough intended
					default:
						throw new AFPException( "Unknown token provided in the ATOM node" );
				}
			case AFPTreeNode::LIST_DEFINITION:
				$items = array_map( [ $this, 'evalNode' ], $node->children );
				return new AFPData( AFPData::DLIST, $items );

			case AFPTreeNode::FUNCTION_CALL:
				$functionName = $node->children[0];
				$args = array_slice( $node->children, 1 );

				$func = self::$mFunctions[$functionName];
				$dataArgs = array_map( [ $this, 'evalNode' ], $args );

				/** @noinspection PhpToStringImplementationInspection */
				$funcHash = md5( $func . serialize( $dataArgs ) );

				if ( isset( self::$funcCache[$funcHash] ) &&
					!in_array( $func, self::$ActiveFunctions )
				) {
					$result = self::$funcCache[$funcHash];
				} else {
					AbuseFilter::triggerLimiter();
					$result = self::$funcCache[$funcHash] = $this->$func( $dataArgs );
				}

				if ( count( self::$funcCache ) > 1000 ) {
					self::$funcCache = array();
				}

				return $result;

			case AFPTreeNode::LIST_INDEX:
				list( $list, $offset ) = $node->children;

				$list = $this->evalNode( $list );
				if ( $list->type != AFPData::DLIST ) {
					throw new AFPUserVisibleException( 'notlist', $node->position, array() );
				}

				$offset = $this->evalNode( $offset )->toInt();

				$list = $list->toList();
				if ( count( $list ) <= $offset ) {
					throw new AFPUserVisibleException( 'outofbounds', $node->position,
						[ $offset, count( $list ) ] );
				}

				return $list[$offset];

			case AFPTreeNode::UNARY:
				list( $operation, $argument ) = $node->children;
				$argument = $this->evalNode( $argument );
				if ( $operation == '-' ) {
					return AFPData::unaryMinus( $argument );
				}
				return $argument;

			case AFPTreeNode::KEYWORD_OPERATOR:
				list( $keyword, $leftOperand, $rightOperand ) = $node->children;
				$func = self::$mKeywords[$keyword];
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );

				AbuseFilter::triggerLimiter();
				$result = AFPData::$func( $leftOperand, $rightOperand, $node->position );

				return $result;
			case AFPTreeNode::BOOL_INVERT:
				list( $argument ) = $node->children;
				$argument = $this->evalNode( $argument );
				return AFPData::boolInvert( $argument );

			case AFPTreeNode::POW:
				list( $base, $exponent ) = $node->children;
				$base = $this->evalNode( $base );
				$exponent = $this->evalNode( $exponent );
				return AFPData::pow( $base, $exponent );

			case AFPTreeNode::MUL_REL:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				return AFPData::mulRel( $leftOperand, $rightOperand, $op, /* FIXME */ 0 );

			case AFPTreeNode::SUM_REL:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				switch ( $op ) {
					case '+':
						return AFPData::sum( $leftOperand, $rightOperand );
					case '-':
						return AFPData::sub( $leftOperand, $rightOperand );
					default:
						throw new AFPException( "Unknown sum-related operator: {$op}" );
				}

			case AFPTreeNode::COMPARE:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				AbuseFilter::triggerLimiter();
				return AFPData::compareOp( $leftOperand, $rightOperand, $op );

			case AFPTreeNode::LOGIC:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$value = $leftOperand->toBool();
				// Short-circuit.
				if ( ( !$value && $op == '&' ) || ( $value && $op == '|' ) ) {
					return $leftOperand;
				}
				$rightOperand = $this->evalNode( $rightOperand );
				return AFPData::boolOp( $leftOperand, $rightOperand, $op );

			case AFPTreeNode::CONDITIONAL:
				list( $condition, $valueIfTrue, $valueIfFalse ) = $node->children;
				$condition = $this->evalNode( $condition );
				if ( $condition->toBool() ) {
					return $this->evalNode( $valueIfTrue );
				} else {
					return $this->evalNode( $valueIfFalse );
				}

			case AFPTreeNode::ASSIGNMENT:
				list( $varName, $value ) = $node->children;
				$value = $this->evalNode( $value );
				$this->setUserVariable( $varName, $value );
				return $value;

			case AFPTreeNode::INDEX_ASSIGNMENT:
				list( $varName, $offset, $value ) = $node->children;

				$list = $this->mVars->getVar( $varName );
				if ( $list->type != AFPData::DLIST ) {
					throw new AFPUserVisibleException( 'notlist', $node->position, array() );
				}

				$offset = $this->evalNode( $offset )->toInt();

				$list = $list->toList();
				if ( count( $list ) <= $offset ) {
					throw new AFPUserVisibleException( 'outofbounds', $node->position,
						array( $offset, count( $list ) ) );
				}

				$list[$offset] = $this->evalNode( $value );
				$this->setUserVariable( $varName, new AFPData( AFPData::DLIST, $list ) );
				return $value;

			case AFPTreeNode::LIST_APPEND:
				list( $varName, $value ) = $node->children;

				$list = $this->mVars->getVar( $varName );
				if ( $list->type != AFPData::DLIST ) {
					throw new AFPUserVisibleException( 'notlist', $node->position, array() );
				}

				$list = $list->toList();
				$list[] = $this->evalNode( $value );
				$this->setUserVariable( $varName, new AFPData( AFPData::DLIST, $list ) );
				return $value;

			case AFPTreeNode::SEMICOLON:
				$lastValue = null;
				foreach ( $node->children as $statement ) {
					$lastValue = $this->evalNode( $statement );
				}

				return $lastValue;
			default:
				throw new AFPException( "Unknown node type passed: {$node->type}" );
		}
	}
}
