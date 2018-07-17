<?php
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
	 * @return string
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

	/**
	 * Resets the state of the parser
	 */
	public function resetState() {
		$this->mVars = new AbuseFilterVariableHolder;
		$this->mCur = new AFPToken();
	}

	/**
	 * @param string $code
	 * @return AFPData
	 */
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
	 * @return AFPData|AFPTreeNode|string
	 * @throws AFPException
	 * @throws AFPUserVisibleException
	 * @throws MWException
	 */
	public function evalNode( AFPTreeNode $node ) {
		// A lot of AbuseFilterParser features rely on $this->mCur->pos or
		// $this->mPos for error reporting.
		// FIXME: this is a hack which needs to be removed when the parsers are merged.
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
			case AFPTreeNode::ARRAY_DEFINITION:
				$items = array_map( [ $this, 'evalNode' ], $node->children );
				return new AFPData( AFPData::DARRAY, $items );

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
					self::$funcCache = [];
				}

				return $result;

			case AFPTreeNode::ARRAY_INDEX:
				list( $array, $offset ) = $node->children;

				$array = $this->evalNode( $array );
				if ( $array->type != AFPData::DARRAY ) {
					throw new AFPUserVisibleException( 'notarray', $node->position, [] );
				}

				$offset = $this->evalNode( $offset )->toInt();

				$array = $array->toArray();
				if ( count( $array ) <= $offset ) {
					throw new AFPUserVisibleException( 'outofbounds', $node->position,
						[ $offset, count( $array ) ] );
				}

				return $array[$offset];

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
				// FIXME
				return AFPData::mulRel( $leftOperand, $rightOperand, $op, 0 );

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

				$array = $this->mVars->getVar( $varName );
				if ( $array->type != AFPData::DARRAY ) {
					throw new AFPUserVisibleException( 'notarray', $node->position, [] );
				}

				$offset = $this->evalNode( $offset )->toInt();

				$array = $array->toArray();
				if ( count( $array ) <= $offset ) {
					throw new AFPUserVisibleException( 'outofbounds', $node->position,
						[ $offset, count( $array ) ] );
				}

				$array[$offset] = $this->evalNode( $value );
				$this->setUserVariable( $varName, new AFPData( AFPData::DARRAY, $array ) );
				return $value;

			case AFPTreeNode::ARRAY_APPEND:
				list( $varName, $value ) = $node->children;

				$array = $this->mVars->getVar( $varName );
				if ( $array->type != AFPData::DARRAY ) {
					throw new AFPUserVisibleException( 'notarray', $node->position, [] );
				}

				$array = $array->toArray();
				$array[] = $this->evalNode( $value );
				$this->setUserVariable( $varName, new AFPData( AFPData::DARRAY, $array ) );
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
