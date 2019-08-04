<?php

use Wikimedia\Equivset\Equivset;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

class AbuseFilterParser {
	/**
	 * @var array Contains the AFPTokens for the code being parsed
	 */
	public $mTokens;
	/**
	 * @var int The position of the current token
	 */
	public $mPos;
	/**
	 * @var bool Are we inside a short circuit evaluation?
	 */
	public $mShortCircuit;
	/**
	 * @var bool Are we allowed to use short-circuit evaluation?
	 */
	public $mAllowShort;
	/**
	 * @var AFPToken The current token
	 */
	public $mCur;
	/**
	 * @var AbuseFilterVariableHolder
	 */
	public $mVariables;

	/**
	 * @var int The current amount of conditions being consumed
	 */
	protected $mCondCount;

	/**
	 * @var bool Whether the condition limit is enabled.
	 */
	protected $condLimitEnabled = true;

	public static $mFunctions = [
		'lcase' => 'funcLc',
		'ucase' => 'funcUc',
		'length' => 'funcLen',
		'string' => 'castString',
		'int' => 'castInt',
		'float' => 'castFloat',
		'bool' => 'castBool',
		'norm' => 'funcNorm',
		'ccnorm' => 'funcCCNorm',
		'ccnorm_contains_any' => 'funcCCNormContainsAny',
		'ccnorm_contains_all' => 'funcCCNormContainsAll',
		'specialratio' => 'funcSpecialRatio',
		'rmspecials' => 'funcRMSpecials',
		'rmdoubles' => 'funcRMDoubles',
		'rmwhitespace' => 'funcRMWhitespace',
		'count' => 'funcCount',
		'rcount' => 'funcRCount',
		'get_matches' => 'funcGetMatches',
		'ip_in_range' => 'funcIPInRange',
		'contains_any' => 'funcContainsAny',
		'contains_all' => 'funcContainsAll',
		'equals_to_any' => 'funcEqualsToAny',
		'substr' => 'funcSubstr',
		'strlen' => 'funcLen',
		'strpos' => 'funcStrPos',
		'str_replace' => 'funcStrReplace',
		'rescape' => 'funcStrRegexEscape',
		'set' => 'funcSetVar',
		'set_var' => 'funcSetVar',
		'sanitize' => 'funcSanitize',
	];

	// Functions that affect parser state, and shouldn't be cached.
	public static $ActiveFunctions = [
		'funcSetVar',
	];

	public static $mKeywords = [
		'in' => 'keywordIn',
		'like' => 'keywordLike',
		'matches' => 'keywordLike',
		'contains' => 'keywordContains',
		'rlike' => 'keywordRegex',
		'irlike' => 'keywordRegexInsensitive',
		'regex' => 'keywordRegex',
	];

	/**
	 * @var array Cached results of functions
	 */
	protected $funcCache = [];

	/**
	 * @var Equivset
	 */
	protected static $equivset;

	/**
	 * Create a new instance
	 *
	 * @param AbuseFilterVariableHolder|null $vars
	 */
	public function __construct( AbuseFilterVariableHolder $vars = null ) {
		$this->resetState();
		if ( $vars ) {
			$this->mVariables = $vars;
		}
	}

	/**
	 * @return int
	 */
	public function getCondCount() {
		return $this->mCondCount;
	}

	/**
	 * Reset the conditions counter
	 */
	public function resetCondCount() {
		$this->mCondCount = 0;
	}

	/**
	 * For use in batch scripts and the like
	 *
	 * @param bool $enable True to enable the limit, false to disable it
	 * @codeCoverageIgnore
	 */
	public function toggleConditionLimit( $enable ) {
		$this->condLimitEnabled = $enable;
	}

	/**
	 * @param int $val The amount to increase the conditions count of.
	 * @throws MWException
	 */
	protected function raiseCondCount( $val = 1 ) {
		global $wgAbuseFilterConditionLimit;

		$this->mCondCount += $val;

		if ( $this->condLimitEnabled && $this->mCondCount > $wgAbuseFilterConditionLimit ) {
			throw new MWException( 'Condition limit reached.' );
		}
	}

	/**
	 * Resets the state of the parser.
	 */
	public function resetState() {
		$this->mTokens = [];
		$this->mVariables = new AbuseFilterVariableHolder;
		$this->mPos = 0;
		$this->mShortCircuit = false;
		$this->mAllowShort = true;
		$this->mCondCount = 0;
	}

	/**
	 * Clears the array of cached function results
	 */
	public function clearFuncCache() {
		$this->funcCache = [];
	}

	/**
	 * @param AbuseFilterVariableHolder $vars
	 */
	public function setVariables( AbuseFilterVariableHolder $vars ) {
		$this->mVariables = $vars;
	}

	/**
	 * @param string $filter
	 * @return true|array True when successful, otherwise a two-element array with exception message
	 *  and character position of the syntax error
	 */
	public function checkSyntax( $filter ) {
		$origAS = $this->mAllowShort;
		try {
			$this->mAllowShort = false;
			$this->intEval( $filter );
		} catch ( AFPUserVisibleException $excep ) {
			$this->mAllowShort = $origAS;

			return [ $excep->getMessageObj()->text(), $excep->mPosition ];
		}
		$this->mAllowShort = $origAS;

		return true;
	}

	/**
	 * Move to the next token
	 */
	protected function move() {
		list( $this->mCur, $this->mPos ) = $this->mTokens[$this->mPos];
	}

	/**
	 * Get the next token. This is similar to move() but doesn't change class members,
	 *   allowing to look ahead without rolling back the state.
	 *
	 * @return AFPToken
	 */
	protected function getNextToken() {
		return $this->mTokens[$this->mPos][0];
	}

	/**
	 * getState() function allows parser state to be rollbacked to several tokens back
	 * @return AFPParserState
	 */
	protected function getState() {
		return new AFPParserState( $this->mCur, $this->mPos );
	}

	/**
	 * setState() function allows parser state to be rollbacked to several tokens back
	 * @param AFPParserState $state
	 */
	protected function setState( AFPParserState $state ) {
		$this->mCur = $state->token;
		$this->mPos = $state->pos;
	}

	/**
	 * @throws AFPUserVisibleException
	 */
	protected function skipOverBraces() {
		$braces = 1;
		while ( $this->mCur->type !== AFPToken::TNONE && $braces > 0 ) {
			$this->move();
			if ( $this->mCur->type === AFPToken::TBRACE ) {
				if ( $this->mCur->value === '(' ) {
					$braces++;
				} elseif ( $this->mCur->value === ')' ) {
					$braces--;
				}
			} elseif ( $this->mCur->type === AFPToken::TID ) {
				// T214674, define variables
				$varname = $this->mCur->value;
				$next = $this->getNextToken();
				if ( $next->type === AFPToken::TOP && $next->value === ':=' ) {
					$this->setUserVariable( $varname, new AFPData( AFPData::DUNDEFINED ) );
				} elseif ( $next->type === AFPToken::TSQUAREBRACKET && $next->value === '[' ) {
					if ( !$this->mVariables->varIsSet( $varname ) ) {
						throw new AFPUserVisibleException( 'unrecognisedvar',
							$next->pos,
							[ $varname ]
						);
					}
					$this->setUserVariable( $varname, new AFPData( AFPData::DUNDEFINED ) );
				}
			}
		}
		if ( !( $this->mCur->type === AFPToken::TBRACE && $this->mCur->value === ')' ) ) {
			throw new AFPUserVisibleException( 'expectednotfound', $this->mCur->pos, [ ')' ] );
		}
	}

	/**
	 * @param string $code
	 * @return bool
	 */
	public function parse( $code ) {
		return $this->intEval( $code )->toBool();
	}

	/**
	 * @param string $filter
	 * @return string
	 */
	public function evaluateExpression( $filter ) {
		return $this->intEval( $filter )->toString();
	}

	/**
	 * @param string $code
	 * @return AFPData
	 */
	public function intEval( $code ) {
		// Reset all class members to their default value
		$this->mTokens = AbuseFilterTokenizer::getTokens( $code );
		$this->mPos = 0;
		$this->mShortCircuit = false;

		$result = new AFPData( AFPData::DEMPTY );
		$this->doLevelEntry( $result );

		return $result;
	}

	/* Levels */

	/**
	 * Handles unexpected characters after the expression
	 *
	 * @param AFPData &$result
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelEntry( &$result ) {
		$this->doLevelSemicolon( $result );

		if ( $this->mCur->type !== AFPToken::TNONE ) {
			throw new AFPUserVisibleException(
				'unexpectedatend',
				$this->mCur->pos, [ $this->mCur->type ]
			);
		}
	}

	/**
	 * Handles multiple expressions delimited by a semicolon
	 *
	 * @param AFPData &$result
	 */
	protected function doLevelSemicolon( &$result ) {
		do {
			$this->move();
			if ( $this->mCur->type !== AFPToken::TSTATEMENTSEPARATOR ) {
				$this->doLevelSet( $result );
			}
		} while ( $this->mCur->type === AFPToken::TSTATEMENTSEPARATOR );
	}

	/**
	 * Handles assignments (:=)
	 *
	 * @param AFPData &$result
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelSet( &$result ) {
		if ( $this->mCur->type === AFPToken::TID ) {
			$varname = $this->mCur->value;
			$prev = $this->getState();
			$this->move();

			if ( $this->mCur->type === AFPToken::TOP && $this->mCur->value === ':=' ) {
				$this->move();
				$this->doLevelSet( $result );
				$this->setUserVariable( $varname, $result );

				return;
			} elseif ( $this->mCur->type === AFPToken::TSQUAREBRACKET && $this->mCur->value === '[' ) {
				if ( !$this->mVariables->varIsSet( $varname ) ) {
					throw new AFPUserVisibleException( 'unrecognisedvar',
						$this->mCur->pos,
						[ $varname ]
					);
				}
				$array = $this->mVariables->getVar( $varname );
				if ( $array->getType() !== AFPData::DARRAY && $array->getType() !== AFPData::DUNDEFINED ) {
					throw new AFPUserVisibleException( 'notarray', $this->mCur->pos, [] );
				}

				$this->move();
				if ( $this->mCur->type === AFPToken::TSQUAREBRACKET && $this->mCur->value === ']' ) {
					$idx = 'new';
				} else {
					$this->setState( $prev );
					$this->move();
					$idx = new AFPData( AFPData::DEMPTY );
					$this->doLevelSemicolon( $idx );
					$idx = $idx->toInt();
					if ( !( $this->mCur->type === AFPToken::TSQUAREBRACKET && $this->mCur->value === ']' ) ) {
						throw new AFPUserVisibleException( 'expectednotfound', $this->mCur->pos,
							[ ']', $this->mCur->type, $this->mCur->value ] );
					}
					if ( $array->getType() === AFPData::DARRAY ) {
						if ( count( $array->toArray() ) <= $idx ) {
							throw new AFPUserVisibleException( 'outofbounds', $this->mCur->pos,
								[ $idx, count( $result->getData() ) ] );
						}
					}
				}
				$this->move();
				if ( $this->mCur->type === AFPToken::TOP && $this->mCur->value === ':=' ) {
					$this->move();
					$this->doLevelSet( $result );
					if ( $array->getType() === AFPData::DARRAY ) {
						// If it's a DUNDEFINED, leave it as is
						$array = $array->toArray();
						if ( $idx === 'new' ) {
							$array[] = $result;
						} else {
							$array[$idx] = $result;
						}
						$this->setUserVariable( $varname, new AFPData( AFPData::DARRAY, $array ) );
					}

					return;
				} else {
					$this->setState( $prev );
				}
			} else {
				$this->setState( $prev );
			}
		}
		$this->doLevelConditions( $result );
	}

	/**
	 * Handles conditionals: if-then-else and ternary operator
	 *
	 * @param AFPData &$result
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelConditions( &$result ) {
		if ( $this->mCur->type === AFPToken::TKEYWORD && $this->mCur->value === 'if' ) {
			$this->move();
			$this->doLevelBoolOps( $result );

			if ( !( $this->mCur->type === AFPToken::TKEYWORD && $this->mCur->value === 'then' ) ) {
				throw new AFPUserVisibleException( 'expectednotfound',
					$this->mCur->pos,
					[
						'then',
						$this->mCur->type,
						$this->mCur->value
					]
				);
			}
			$this->move();

			$r1 = new AFPData( AFPData::DEMPTY );
			$r2 = new AFPData( AFPData::DEMPTY );

			$isTrue = $result->toBool();

			if ( !$isTrue ) {
				$scOrig = $this->mShortCircuit;
				$this->mShortCircuit = $this->mAllowShort;
			}
			$this->doLevelConditions( $r1 );
			if ( !$isTrue ) {
				$this->mShortCircuit = $scOrig;
			}

			if ( !( $this->mCur->type === AFPToken::TKEYWORD && $this->mCur->value === 'else' ) ) {
				throw new AFPUserVisibleException( 'expectednotfound',
					$this->mCur->pos,
					[
						'else',
						$this->mCur->type,
						$this->mCur->value
					]
				);
			}
			$this->move();

			if ( $isTrue ) {
				$scOrig = $this->mShortCircuit;
				$this->mShortCircuit = $this->mAllowShort;
			}
			$this->doLevelConditions( $r2 );
			if ( $isTrue ) {
				$this->mShortCircuit = $scOrig;
			}

			if ( !( $this->mCur->type === AFPToken::TKEYWORD && $this->mCur->value === 'end' ) ) {
				throw new AFPUserVisibleException( 'expectednotfound',
					$this->mCur->pos,
					[
						'end',
						$this->mCur->type,
						$this->mCur->value
					]
				);
			}
			$this->move();

			if ( $result->toBool() ) {
				$result = $r1;
			} else {
				$result = $r2;
			}
		} else {
			$this->doLevelBoolOps( $result );
			if ( $this->mCur->type === AFPToken::TOP && $this->mCur->value === '?' ) {
				$this->move();
				$r1 = new AFPData( AFPData::DEMPTY );
				$r2 = new AFPData( AFPData::DEMPTY );

				$isTrue = $result->toBool();

				if ( !$isTrue ) {
					$scOrig = $this->mShortCircuit;
					$this->mShortCircuit = $this->mAllowShort;
				}
				$this->doLevelConditions( $r1 );
				if ( !$isTrue ) {
					$this->mShortCircuit = $scOrig;
				}

				if ( !( $this->mCur->type === AFPToken::TOP && $this->mCur->value === ':' ) ) {
					throw new AFPUserVisibleException( 'expectednotfound',
						$this->mCur->pos,
						[
							':',
							$this->mCur->type,
							$this->mCur->value
						]
					);
				}
				$this->move();

				if ( $isTrue ) {
					$scOrig = $this->mShortCircuit;
					$this->mShortCircuit = $this->mAllowShort;
				}
				$this->doLevelConditions( $r2 );
				if ( $isTrue ) {
					$this->mShortCircuit = $scOrig;
				}

				if ( $isTrue ) {
					$result = $r1;
				} else {
					$result = $r2;
				}
			}
		}
	}

	/**
	 * Handles boolean operators (&, |, ^)
	 *
	 * @param AFPData &$result
	 */
	protected function doLevelBoolOps( &$result ) {
		$this->doLevelCompares( $result );
		$ops = [ '&', '|', '^' ];
		while ( $this->mCur->type === AFPToken::TOP && in_array( $this->mCur->value, $ops ) ) {
			$op = $this->mCur->value;
			$this->move();
			$r2 = new AFPData( AFPData::DEMPTY );

			// We can go on quickly as either one statement with | is true or one with & is false
			if ( ( $op === '&' && !$result->toBool() ) || ( $op === '|' && $result->toBool() ) ) {
				$orig = $this->mShortCircuit;
				$this->mShortCircuit = $this->mAllowShort;
				$this->doLevelCompares( $r2 );
				$this->mShortCircuit = $orig;
				$result = new AFPData( AFPData::DBOOL, $result->toBool() );
				continue;
			}

			$this->doLevelCompares( $r2 );

			$result = AFPData::boolOp( $result, $r2, $op );
		}
	}

	/**
	 * Handles comparison operators
	 *
	 * @param AFPData &$result
	 */
	protected function doLevelCompares( &$result ) {
		$this->doLevelSumRels( $result );
		$equalityOps = [ '==', '===', '!=', '!==', '=' ];
		$orderOps = [ '<', '>', '<=', '>=' ];
		// Only allow either a single operation, or a combination of a single equalityOps and a single
		// orderOps. This resembles what PHP does, and allows `a < b == c` while rejecting `a < b < c`
		$allowedOps = array_merge( $equalityOps, $orderOps );
		while ( $this->mCur->type === AFPToken::TOP && in_array( $this->mCur->value, $allowedOps ) ) {
			$allowedOps = in_array( $this->mCur->value, $equalityOps ) ?
				array_diff( $allowedOps, $equalityOps ) :
				array_diff( $allowedOps, $orderOps );
			$op = $this->mCur->value;
			$this->move();
			$r2 = new AFPData( AFPData::DEMPTY );
			$this->doLevelSumRels( $r2 );
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				continue;
			}
			$this->raiseCondCount();
			$result = AFPData::compareOp( $result, $r2, $op );
		}
	}

	/**
	 * Handles sum-related operations (+ and -)
	 *
	 * @param AFPData &$result
	 */
	protected function doLevelSumRels( &$result ) {
		$this->doLevelMulRels( $result );
		$ops = [ '+', '-' ];
		while ( $this->mCur->type === AFPToken::TOP && in_array( $this->mCur->value, $ops ) ) {
			$op = $this->mCur->value;
			$this->move();
			$r2 = new AFPData( AFPData::DEMPTY );
			$this->doLevelMulRels( $r2 );
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				continue;
			}
			if ( $op === '+' ) {
				$result = AFPData::sum( $result, $r2 );
			}
			if ( $op === '-' ) {
				$result = AFPData::sub( $result, $r2 );
			}
		}
	}

	/**
	 * Handles multiplication-related operations (*, / and %)
	 *
	 * @param AFPData &$result
	 */
	protected function doLevelMulRels( &$result ) {
		$this->doLevelPow( $result );
		$ops = [ '*', '/', '%' ];
		while ( $this->mCur->type === AFPToken::TOP && in_array( $this->mCur->value, $ops ) ) {
			$op = $this->mCur->value;
			$this->move();
			$r2 = new AFPData( AFPData::DEMPTY );
			$this->doLevelPow( $r2 );
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				continue;
			}
			$result = AFPData::mulRel( $result, $r2, $op, $this->mCur->pos );
		}
	}

	/**
	 * Handles powers (**)
	 *
	 * @param AFPData &$result
	 */
	protected function doLevelPow( &$result ) {
		$this->doLevelBoolInvert( $result );
		while ( $this->mCur->type === AFPToken::TOP && $this->mCur->value === '**' ) {
			$this->move();
			$expanent = new AFPData( AFPData::DEMPTY );
			$this->doLevelBoolInvert( $expanent );
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				continue;
			}
			$result = AFPData::pow( $result, $expanent );
		}
	}

	/**
	 * Handles boolean inversion (!)
	 *
	 * @param AFPData &$result
	 */
	protected function doLevelBoolInvert( &$result ) {
		if ( $this->mCur->type === AFPToken::TOP && $this->mCur->value === '!' ) {
			$this->move();
			$this->doLevelSpecialWords( $result );
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				return;
			}
			$result = AFPData::boolInvert( $result );
		} else {
			$this->doLevelSpecialWords( $result );
		}
	}

	/**
	 * Handles keywords (in, like, rlike, contains, ...)
	 *
	 * @param AFPData &$result
	 */
	protected function doLevelSpecialWords( &$result ) {
		$this->doLevelUnarys( $result );
		$keyword = strtolower( $this->mCur->value );
		if ( $this->mCur->type === AFPToken::TKEYWORD
			&& isset( self::$mKeywords[$keyword] )
		) {
			$func = self::$mKeywords[$keyword];
			$this->move();
			$r2 = new AFPData( AFPData::DEMPTY );
			$this->doLevelUnarys( $r2 );

			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				return;
			}

			if ( $result->getType() === AFPData::DUNDEFINED || $r2->getType() === AFPData::DUNDEFINED ) {
				$result = new AFPData( AFPData::DUNDEFINED );
			} else {
				$this->raiseCondCount();

				// @phan-suppress-next-line PhanParamTooMany Not every function needs the position
				$result = AFPData::$func( $result, $r2, $this->mCur->pos );
			}
		}
	}

	/**
	 * Handles unary plus and minus, like in -5 or -(2 * +2)
	 *
	 * @param AFPData &$result
	 */
	protected function doLevelUnarys( &$result ) {
		$op = $this->mCur->value;
		if ( $this->mCur->type === AFPToken::TOP && ( $op === "+" || $op === "-" ) ) {
			$this->move();
			$this->doLevelArrayElements( $result );
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				return;
			}
			if ( $op === '-' ) {
				$result = AFPData::unaryMinus( $result );
			}
		} else {
			$this->doLevelArrayElements( $result );
		}
	}

	/**
	 * Handles array elements, parsing expressions like array[number]
	 *
	 * @param AFPData &$result
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelArrayElements( &$result ) {
		$this->doLevelBraces( $result );
		while ( $this->mCur->type === AFPToken::TSQUAREBRACKET && $this->mCur->value === '[' ) {
			$idx = new AFPData( AFPData::DEMPTY );
			$this->doLevelSemicolon( $idx );
			if ( !( $this->mCur->type === AFPToken::TSQUAREBRACKET && $this->mCur->value === ']' ) ) {
				throw new AFPUserVisibleException( 'expectednotfound', $this->mCur->pos,
					[ ']', $this->mCur->type, $this->mCur->value ] );
			}
			$idx = $idx->toInt();
			if ( $result->getType() === AFPData::DARRAY ) {
				if ( count( $result->getData() ) <= $idx ) {
					throw new AFPUserVisibleException( 'outofbounds', $this->mCur->pos,
						[ $idx, count( $result->getData() ) ] );
				}
				$result = $result->getData()[$idx];
			} elseif ( $result->getType() === AFPData::DUNDEFINED ) {
				$result = new AFPData( AFPData::DUNDEFINED );
			} else {
				throw new AFPUserVisibleException( 'notarray', $this->mCur->pos, [] );
			}
			$this->move();
		}
	}

	/**
	 * Handles brackets, only ( and )
	 *
	 * @param AFPData &$result
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelBraces( &$result ) {
		if ( $this->mCur->type === AFPToken::TBRACE && $this->mCur->value === '(' ) {
			if ( $this->mShortCircuit ) {
				$this->skipOverBraces();
			} else {
				$this->doLevelSemicolon( $result );
			}
			if ( !( $this->mCur->type === AFPToken::TBRACE && $this->mCur->value === ')' ) ) {
				throw new AFPUserVisibleException(
					'expectednotfound',
					$this->mCur->pos,
					[ ')', $this->mCur->type, $this->mCur->value ]
				);
			}
			$this->move();
		} else {
			$this->doLevelFunction( $result );
		}
	}

	/**
	 * Handles functions
	 *
	 * @param AFPData &$result
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelFunction( &$result ) {
		if ( $this->mCur->type === AFPToken::TID && isset( self::$mFunctions[$this->mCur->value] ) ) {
			$func = self::$mFunctions[$this->mCur->value];
			$this->move();
			if ( $this->mCur->type !== AFPToken::TBRACE || $this->mCur->value !== '(' ) {
				throw new AFPUserVisibleException( 'expectednotfound',
					$this->mCur->pos,
					[
						'(',
						$this->mCur->type,
						$this->mCur->value
					]
				);
			}

			if ( $this->mShortCircuit ) {
				$this->skipOverBraces();
				$this->move();

				// The result doesn't matter.
				return;
			}

			$args = [];
			do {
				$next = $this->getNextToken();
				if ( $next->type !== AFPToken::TBRACE || $next->value !== ')' ) {
					$r = new AFPData( AFPData::DEMPTY );
					$this->doLevelSemicolon( $r );
					$args[] = $r;
				} else {
					$logger = LoggerFactory::getInstance( 'AbuseFilter' );
					$logger->debug( "Found null param for function $func" );
					$this->move();
				}
			} while ( $this->mCur->type === AFPToken::TCOMMA );

			if ( $this->mCur->type !== AFPToken::TBRACE || $this->mCur->value !== ')' ) {
				throw new AFPUserVisibleException( 'expectednotfound',
					$this->mCur->pos,
					[
						')',
						$this->mCur->type,
						$this->mCur->value
					]
				);
			}
			$this->move();

			$funcHash = md5( $func . serialize( $args ) );

			if ( isset( $this->funcCache[$funcHash] ) &&
				!in_array( $func, self::$ActiveFunctions )
			) {
				$result = $this->funcCache[$funcHash];
			} else {
				$this->raiseCondCount();
				$hasUndefinedArg = false;
				foreach ( $args as $arg ) {
					if ( $arg->type === AFPData::DUNDEFINED ) {
						$hasUndefinedArg = true;
						break;
					}
				}
				$result = $this->funcCache[$funcHash] = $hasUndefinedArg
					? new AFPData( AFPData::DUNDEFINED )
					: $this->$func( $args );
			}

			if ( count( $this->funcCache ) > 1000 ) {
				// @codeCoverageIgnoreStart
				$this->clearFuncCache();
				// @codeCoverageIgnoreEnd
			}
		} else {
			$this->doLevelAtom( $result );
		}
	}

	/**
	 * Handles the return value
	 *
	 * @param AFPData &$result
	 * @throws AFPUserVisibleException
	 */
	protected function doLevelAtom( &$result ) {
		$tok = $this->mCur->value;
		switch ( $this->mCur->type ) {
			case AFPToken::TID:
				if ( $this->mShortCircuit ) {
					$result = new AFPData( AFPData::DUNDEFINED );
				} else {
					$var = strtolower( $tok );
					$result = $this->getVarValue( $var );
				}
				break;
			case AFPToken::TSTRING:
				$result = new AFPData( AFPData::DSTRING, $tok );
				break;
			case AFPToken::TFLOAT:
				$result = new AFPData( AFPData::DFLOAT, $tok );
				break;
			case AFPToken::TINT:
				$result = new AFPData( AFPData::DINT, $tok );
				break;
			case AFPToken::TKEYWORD:
				if ( $tok === "true" ) {
					$result = new AFPData( AFPData::DBOOL, true );
				} elseif ( $tok === "false" ) {
					$result = new AFPData( AFPData::DBOOL, false );
				} elseif ( $tok === "null" ) {
					$result = new AFPData( AFPData::DNULL );
				} else {
					throw new AFPUserVisibleException(
						'unrecognisedkeyword',
						$this->mCur->pos,
						[ $tok ]
					);
				}
				break;
			case AFPToken::TNONE:
				// Handled at entry level
				return;
			case AFPToken::TBRACE:
				if ( $this->mCur->value === ')' ) {
					// Handled at the entry level
					return;
				}
			case AFPToken::TSQUAREBRACKET:
				if ( $this->mCur->value === '[' ) {
					$array = [];
					while ( true ) {
						$this->move();
						if ( $this->mCur->type === AFPToken::TSQUAREBRACKET && $this->mCur->value === ']' ) {
							break;
						}
						$item = new AFPData( AFPData::DEMPTY );
						$this->doLevelSet( $item );
						$array[] = $item;
						if ( $this->mCur->type === AFPToken::TSQUAREBRACKET && $this->mCur->value === ']' ) {
							break;
						}
						if ( $this->mCur->type !== AFPToken::TCOMMA ) {
							throw new AFPUserVisibleException(
								'expectednotfound',
								$this->mCur->pos,
								[ ', or ]', $this->mCur->type, $this->mCur->value ]
							);
						}
					}
					$result = new AFPData( AFPData::DARRAY, $array );
					break;
				}
			default:
				throw new AFPUserVisibleException(
					'unexpectedtoken',
					$this->mCur->pos,
					[
						$this->mCur->type,
						$this->mCur->value
					]
				);
		}
		$this->move();
	}

	/* End of levels */

	/**
	 * @param string $var
	 * @return AFPData
	 * @throws AFPUserVisibleException
	 */
	protected function getVarValue( $var ) {
		$var = strtolower( $var );
		$builderValues = AbuseFilter::getBuilderValues();
		$deprecatedVars = AbuseFilter::getDeprecatedVariables();
		if ( array_key_exists( $var, $deprecatedVars ) ) {
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->debug( "AbuseFilter: deprecated variable $var used." );
			$var = $deprecatedVars[$var];
		}
		if ( !( array_key_exists( $var, $builderValues['vars'] )
			|| $this->mVariables->varIsSet( $var ) )
		) {
			$msg = array_key_exists( $var, AbuseFilter::$disabledVars ) ?
				'disabledvar' :
				'unrecognisedvar';
			// If the variable is invalid, throw an exception
			throw new AFPUserVisibleException(
			// Coverage bug
			// @codeCoverageIgnoreStart
				$msg,
				// @codeCoverageIgnoreEnd
				$this->mCur->pos,
				[ $var ]
			);
		} else {
			return $this->mVariables->getVar( $var );
		}
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 * @throws AFPUserVisibleException
	 */
	protected function setUserVariable( $name, $value ) {
		$builderValues = AbuseFilter::getBuilderValues();
		$deprecatedVars = AbuseFilter::getDeprecatedVariables();
		if ( array_key_exists( $name, $builderValues['vars'] ) ||
			array_key_exists( $name, AbuseFilter::$disabledVars ) ||
			array_key_exists( $name, $deprecatedVars )
		) {
			throw new AFPUserVisibleException( 'overridebuiltin', $this->mCur->pos, [ $name ] );
		}
		$this->mVariables->setVar( $name, $value );
	}

	/**
	 * Check that a built-in function has been provided enough arguments
	 *
	 * @param array $args The arguments supplied to the function
	 * @param string $func The function name
	 * @param int $count The amount of needed arguments. If this is 1, then the 'noparams' message
	 *   is used. Otherwise, the message is 'notenoughargs'.
	 * @throws AFPUserVisibleException
	 */
	protected function checkEnoughArguments( $args, $func, $count ) {
		if ( count( $args ) < $count ) {
			throw new AFPUserVisibleException(
				$count === 1 ? 'noparams' : 'notenoughargs',
				$this->mCur->pos,
				[ $func, $count, count( $args ) ]
			);
		}
	}

	// Built-in functions

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcLc( $args ) {
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$this->checkEnoughArguments( $args, 'lc', 1 );
		$s = $args[0]->toString();

		return new AFPData( AFPData::DSTRING, $contLang->lc( $s ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcUc( $args ) {
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$this->checkEnoughArguments( $args, 'uc', 1 );
		$s = $args[0]->toString();

		return new AFPData( AFPData::DSTRING, $contLang->uc( $s ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcLen( $args ) {
		$this->checkEnoughArguments( $args, 'len', 1 );

		if ( $args[0]->type === AFPData::DARRAY ) {
			// Don't use toString on arrays, but count
			$val = count( $args[0]->data );
		} else {
			$val = mb_strlen( $args[0]->toString(), 'utf-8' );
		}

		return new AFPData( AFPData::DINT, $val );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcSpecialRatio( $args ) {
		$this->checkEnoughArguments( $args, 'specialratio', 1 );
		$s = $args[0]->toString();

		if ( !strlen( $s ) ) {
			return new AFPData( AFPData::DFLOAT, 0 );
		}

		$nospecials = $this->rmspecials( $s );

		$val = 1. - ( ( mb_strlen( $nospecials ) / mb_strlen( $s ) ) );

		return new AFPData( AFPData::DFLOAT, $val );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcCount( $args ) {
		$this->checkEnoughArguments( $args, 'count', 1 );

		if ( $args[0]->type === AFPData::DARRAY && count( $args ) === 1 ) {
			return new AFPData( AFPData::DINT, count( $args[0]->data ) );
		}

		if ( count( $args ) === 1 ) {
			$count = count( explode( ',', $args[0]->toString() ) );
		} else {
			$needle = $args[0]->toString();
			$haystack = $args[1]->toString();

			// T62203: Keep empty parameters from causing PHP warnings
			if ( $needle === '' ) {
				$count = 0;
			} else {
				$count = substr_count( $haystack, $needle );
			}
		}

		return new AFPData( AFPData::DINT, $count );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 * @throws AFPUserVisibleException
	 */
	protected function funcRCount( $args ) {
		$this->checkEnoughArguments( $args, 'rcount', 1 );

		if ( count( $args ) === 1 ) {
			$count = count( explode( ',', $args[0]->toString() ) );
		} else {
			$needle = $args[0]->toString();
			$haystack = $args[1]->toString();

			// Munge the regex
			$needle = preg_replace( '!(\\\\\\\\)*(\\\\)?/!', '$1\/', $needle );
			$needle = "/$needle/u";

			// Suppress and restore needed per T177744
			Wikimedia\suppressWarnings();
			$count = preg_match_all( $needle, $haystack );
			Wikimedia\restoreWarnings();

			if ( $count === false ) {
				throw new AFPUserVisibleException(
					'regexfailure',
					$this->mCur->pos,
					[ $needle ]
				);
			}
		}

		return new AFPData( AFPData::DINT, $count );
	}

	/**
	 * Returns an array of matches of needle in the haystack, the first one for the whole regex,
	 * the other ones for every capturing group.
	 *
	 * @param array $args
	 * @return AFPData An array of matches.
	 * @throws AFPUserVisibleException
	 */
	protected function funcGetMatches( $args ) {
		$this->checkEnoughArguments( $args, 'get_matches', 2 );

		$needle = $args[0]->toString();
		$haystack = $args[1]->toString();

		// Count the amount of capturing groups in the submitted pattern.
		// This way we can return a fixed-dimension array, much easier to manage.
		// ToDo: Find a better way to do this.
		// First, strip away escaped parentheses
		$sanitized = preg_replace( '/(\\\\\\\\)*\\\\\(/', '', $needle );
		// Then strip starting parentheses of non-capturing groups, including
		// atomics, lookaheads and so on, even if not every of them is supported.
		$sanitized = str_replace( '(?', '', $sanitized );
		// And also strip "(*", used with backtracking verbs like (*FAIL)
		$sanitized = str_replace( '(*', '', $sanitized );
		// Finally create an array of falses with dimension = # of capturing groups
		$groupscount = substr_count( $sanitized, '(' ) + 1;
		$falsy = array_fill( 0, $groupscount, false );

		// Munge the regex by escaping slashes
		$needle = preg_replace( '!(\\\\\\\\)*(\\\\)?/!', '$1\/', $needle );
		$needle = "/$needle/u";

		// Suppress and restore are here for the same reason as T177744
		Wikimedia\suppressWarnings();
		$check = preg_match( $needle, $haystack, $matches );
		Wikimedia\restoreWarnings();

		if ( $check === false ) {
			throw new AFPUserVisibleException(
				'regexfailure',
				$this->mCur->pos,
				[ $needle ]
			);
		}

		// Returned array has non-empty positions identical to the ones returned
		// by the third parameter of a standard preg_match call ($matches in this case).
		// We want an union with falsy to return a fixed-dimension array.
		return AFPData::newFromPHPVar( $matches + $falsy );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 * @throws AFPUserVisibleException
	 */
	protected function funcIPInRange( $args ) {
		$this->checkEnoughArguments( $args, 'ip_in_range', 2 );

		$ip = $args[0]->toString();
		$range = $args[1]->toString();

		if ( !IP::isValidRange( $range ) ) {
			throw new AFPUserVisibleException(
				'invalidiprange',
				$this->mCur->pos,
				[ $range ]
			);
		}

		$result = IP::isInRange( $ip, $range );

		return new AFPData( AFPData::DBOOL, $result );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcCCNorm( $args ) {
		$this->checkEnoughArguments( $args, 'ccnorm', 1 );
		$s = $args[0]->toString();

		$s = html_entity_decode( $s, ENT_QUOTES, 'UTF-8' );
		$s = $this->ccnorm( $s );

		return new AFPData( AFPData::DSTRING, $s );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcSanitize( $args ) {
		$this->checkEnoughArguments( $args, 'sanitize', 1 );
		$s = $args[0]->toString();

		$s = html_entity_decode( $s, ENT_QUOTES, 'UTF-8' );
		$s = Sanitizer::decodeCharReferences( $s );

		return new AFPData( AFPData::DSTRING, $s );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcContainsAny( $args ) {
		$this->checkEnoughArguments( $args, 'contains_any', 2 );

		$s = array_shift( $args );

		return new AFPData( AFPData::DBOOL, self::contains( $s, $args, true ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcContainsAll( $args ) {
		$this->checkEnoughArguments( $args, 'contains_all', 2 );

		$s = array_shift( $args );

		return new AFPData( AFPData::DBOOL, self::contains( $s, $args, false, false ) );
	}

	/**
	 * Normalize and search a string for multiple substrings in OR mode
	 *
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcCCNormContainsAny( $args ) {
		$this->checkEnoughArguments( $args, 'ccnorm_contains_any', 2 );

		$s = array_shift( $args );

		return new AFPData( AFPData::DBOOL, self::contains( $s, $args, true, true ) );
	}

	/**
	 * Normalize and search a string for multiple substrings in AND mode
	 *
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcCCNormContainsAll( $args ) {
		$this->checkEnoughArguments( $args, 'ccnorm_contains_all', 2 );

		$s = array_shift( $args );

		return new AFPData( AFPData::DBOOL, self::contains( $s, $args, false, true ) );
	}

	/**
	 * Search for substrings in a string
	 *
	 * Use is_any to determine wether to use logic OR (true) or AND (false).
	 *
	 * Use normalize = true to make use of ccnorm and
	 * normalize both sides of the search.
	 *
	 * @param AFPData $string
	 * @param AFPData[] $values
	 * @param bool $is_any
	 * @param bool $normalize
	 *
	 * @return bool
	 */
	protected static function contains( $string, $values, $is_any = true, $normalize = false ) {
		$string = $string->toString();

		if ( $string === '' ) {
			return false;
		}

		if ( $normalize ) {
			$string = self::ccnorm( $string );
		}

		foreach ( $values as $needle ) {
			$needle = $needle->toString();
			if ( $normalize ) {
				$needle = self::ccnorm( $needle );
			}
			if ( $needle === '' ) {
				// T62203: Keep empty parameters from causing PHP warnings
				continue;
			}

			$is_found = strpos( $string, $needle ) !== false;
			if ( $is_found === $is_any ) {
				// If I'm here and it's ANY (OR) => something is found.
				// If I'm here and it's ALL (AND) => nothing is found.
				// In both cases, we've had enough.
				return $is_found;
			}
		}

		// If I'm here and it's ANY (OR) => nothing was found: return false ($is_any is true)
		// If I'm here and it's ALL (AND) => everything was found: return true ($is_any is false)
		return !$is_any;
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcEqualsToAny( $args ) {
		$this->checkEnoughArguments( $args, 'equals_to_any', 2 );

		$s = array_shift( $args );

		return new AFPData( AFPData::DBOOL, self::equalsToAny( $s, $args ) );
	}

	/**
	 * Check if the given string is equals to any of the following strings
	 *
	 * @param AFPData $string
	 * @param AFPData[] $values
	 *
	 * @return bool
	 */
	protected static function equalsToAny( $string, $values ) {
		$string = $string->toString();

		foreach ( $values as $needle ) {
			$needle = $needle->toString();

			if ( $string === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $s
	 * @return mixed
	 */
	protected static function ccnorm( $s ) {
		// Instantiate a single version of the equivset so the data is only loaded once.
		if ( !self::$equivset ) {
			self::$equivset = new Equivset();
		}

		return self::$equivset->normalize( $s );
	}

	/**
	 * @param string $s
	 * @return array|string
	 */
	protected function rmspecials( $s ) {
		return preg_replace( '/[^\p{L}\p{N}]/u', '', $s );
	}

	/**
	 * @param string $s
	 * @return array|string
	 */
	protected function rmdoubles( $s ) {
		return preg_replace( '/(.)\1+/us', '\1', $s );
	}

	/**
	 * @param string $s
	 * @return array|string
	 */
	protected function rmwhitespace( $s ) {
		return preg_replace( '/\s+/u', '', $s );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcRMSpecials( $args ) {
		$this->checkEnoughArguments( $args, 'rmspecials', 1 );
		$s = $args[0]->toString();

		return new AFPData( AFPData::DSTRING, $this->rmspecials( $s ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcRMWhitespace( $args ) {
		$this->checkEnoughArguments( $args, 'rmwhitespace', 1 );
		$s = $args[0]->toString();

		return new AFPData( AFPData::DSTRING, $this->rmwhitespace( $s ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcRMDoubles( $args ) {
		$this->checkEnoughArguments( $args, 'rmdoubles', 1 );
		$s = $args[0]->toString();

		return new AFPData( AFPData::DSTRING, $this->rmdoubles( $s ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcNorm( $args ) {
		$this->checkEnoughArguments( $args, 'norm', 1 );
		$s = $args[0]->toString();

		$s = $this->ccnorm( $s );
		$s = $this->rmdoubles( $s );
		$s = $this->rmspecials( $s );
		$s = $this->rmwhitespace( $s );

		return new AFPData( AFPData::DSTRING, $s );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcSubstr( $args ) {
		$this->checkEnoughArguments( $args, 'substr', 2 );

		$s = $args[0]->toString();
		$offset = $args[1]->toInt();
		$length = isset( $args[2] ) ? $args[2]->toInt() : null;

		$result = mb_substr( $s, $offset, $length );

		return new AFPData( AFPData::DSTRING, $result );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcStrPos( $args ) {
		$this->checkEnoughArguments( $args, 'strpos', 2 );

		$haystack = $args[0]->toString();
		$needle = $args[1]->toString();
		$offset = isset( $args[2] ) ? $args[2]->toInt() : 0;

		// T62203: Keep empty parameters from causing PHP warnings
		if ( $needle === '' ) {
			return new AFPData( AFPData::DINT, -1 );
		}

		$result = mb_strpos( $haystack, $needle, $offset );

		if ( $result === false ) {
			$result = -1;
		}

		return new AFPData( AFPData::DINT, $result );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcStrReplace( $args ) {
		$this->checkEnoughArguments( $args, 'str_replace', 3 );

		$subject = $args[0]->toString();
		$search = $args[1]->toString();
		$replace = $args[2]->toString();

		return new AFPData( AFPData::DSTRING, str_replace( $search, $replace, $subject ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcStrRegexEscape( $args ) {
		$this->checkEnoughArguments( $args, 'rescape', 1 );

		$string = $args[0]->toString();

		// preg_quote does not need the second parameter, since rlike takes
		// care of the delimiter symbol itself
		return new AFPData( AFPData::DSTRING, preg_quote( $string ) );
	}

	/**
	 * @param array $args
	 * @return mixed
	 */
	protected function funcSetVar( $args ) {
		$this->checkEnoughArguments( $args, 'set_var', 2 );

		$varName = $args[0]->toString();
		$value = $args[1];

		$this->setUserVariable( $varName, $value );

		return $value;
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function castString( $args ) {
		$this->checkEnoughArguments( $args, 'string', 1 );
		return AFPData::castTypes( $args[0], AFPData::DSTRING );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function castInt( $args ) {
		$this->checkEnoughArguments( $args, 'int', 1 );
		return AFPData::castTypes( $args[0], AFPData::DINT );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function castFloat( $args ) {
		$this->checkEnoughArguments( $args, 'float', 1 );
		return AFPData::castTypes( $args[0], AFPData::DFLOAT );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function castBool( $args ) {
		$this->checkEnoughArguments( $args, 'bool', 1 );
		return AFPData::castTypes( $args[0], AFPData::DBOOL );
	}
}
