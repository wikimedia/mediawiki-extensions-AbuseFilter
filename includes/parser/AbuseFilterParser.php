<?php

use Psr\Log\LoggerInterface;
use Wikimedia\Equivset\Equivset;

class AbuseFilterParser {
	/**
	 * @var array[] Contains the AFPTokens for the code being parsed
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

	/**
	 * @var string|null The ID of the filter being parsed, if available. Can also be "global-$ID"
	 */
	protected $mFilter;

	/**
	 * @var BagOStuff Used to cache the AST (in CachingParser) and the tokens
	 */
	protected $cache;
	/**
	 * @var LoggerInterface Used for debugging
	 */
	protected $logger;
	/**
	 * @var Language Content language, used for language-dependent functions
	 */
	protected $contLang;

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

	/**
	 * The minimum and maximum amount of arguments required by each function.
	 * @var int[][]
	 * @todo Make constant once we drop HHVM support
	 */
	public static $funcArgCount = [
		'lcase' => [ 1, 1 ],
		'ucase' => [ 1, 1 ],
		'length' => [ 1, 1 ],
		'string' => [ 1, 1 ],
		'int' => [ 1, 1 ],
		'float' => [ 1, 1 ],
		'bool' => [ 1, 1 ],
		'norm' => [ 1, 1 ],
		'ccnorm' => [ 1, 1 ],
		'ccnorm_contains_any' => [ 2, INF ],
		'ccnorm_contains_all' => [ 2, INF ],
		'specialratio' => [ 1, 1 ],
		'rmspecials' => [ 1, 1 ],
		'rmdoubles' => [ 1, 1 ],
		'rmwhitespace' => [ 1, 1 ],
		'count' => [ 1, 2 ],
		'rcount' => [ 1, 2 ],
		'get_matches' => [ 2, 2 ],
		'ip_in_range' => [ 2, 2 ],
		'contains_any' => [ 2, INF ],
		'contains_all' => [ 2, INF ],
		'equals_to_any' => [ 2, INF ],
		'substr' => [ 2, 3 ],
		'strlen' => [ 1, 1 ],
		'strpos' => [ 2, 3 ],
		'str_replace' => [ 3, 3 ],
		'rescape' => [ 1, 1 ],
		'set' => [ 2, 2 ],
		'set_var' => [ 2, 2 ],
		'sanitize' => [ 1, 1 ],
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
	 * @param Language $contLang Content language, used for language-dependent function
	 * @param BagOStuff $cache Used to cache the AST (in CachingParser) and the tokens
	 * @param LoggerInterface $logger Used for debugging
	 * @param AbuseFilterVariableHolder|null $vars
	 */
	public function __construct(
		Language $contLang,
		BagOStuff $cache,
		LoggerInterface $logger,
		AbuseFilterVariableHolder $vars = null
	) {
		$this->resetState();
		$this->contLang = $contLang;
		$this->cache = $cache;
		$this->logger = $logger;
		if ( $vars ) {
			$this->mVariables = $vars;
		}
	}

	/**
	 * @param string $filter
	 */
	public function setFilter( $filter ) {
		$this->mFilter = $filter;
	}

	/**
	 * @param BagOStuff $cache
	 */
	public function setCache( BagOStuff $cache ) {
		$this->cache = $cache;
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
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
		$this->mFilter = null;
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
			return [ $excep->getMessageObj()->text(), $excep->mPosition ];
		} finally {
			$this->mAllowShort = $origAS;
		}

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
				// T214674, define non-existing variables. @see docs of
				// AbuseFilterCachingParser::discardWithHoisting for a detailed explanation of this branch
				$next = $this->getNextToken();
				if (
					in_array( $this->mCur->value, [ 'set', 'set_var' ] ) &&
					$next->type === AFPToken::TBRACE && $next->value === '('
				) {
					// This is for setter functions.
					$this->move();
					$braces++;
					$next = $this->getNextToken();
					if ( $next->type === AFPToken::TSTRING ) {
						if ( !$this->mVariables->varIsSet( $next->value ) ) {
							$this->setUserVariable( $next->value, new AFPData( AFPData::DUNDEFINED ) );
						}
					}
				} else {
					// Simple assignment with :=
					$varname = $this->mCur->value;
					$next = $this->getNextToken();
					if ( $next->type === AFPToken::TOP && $next->value === ':=' ) {
						if ( !$this->mVariables->varIsSet( $varname ) ) {
							$this->setUserVariable( $varname, new AFPData( AFPData::DUNDEFINED ) );
						}
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
		$tokenizer = new AbuseFilterTokenizer( $this->cache, $this->logger );
		$this->mTokens = $tokenizer->getTokens( $code );
		$this->mPos = 0;
		$this->mShortCircuit = false;

		$result = new AFPData( AFPData::DEMPTY );
		$this->doLevelEntry( $result );

		if ( $result->getType() === AFPData::DUNDEFINED ) {
			$result = new AFPData( AFPData::DBOOL, false );
		}

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
				$checkEmpty = $result->getType() === AFPData::DEMPTY;
				$this->doLevelSet( $result );
				if ( $checkEmpty && $result->getType() === AFPData::DEMPTY ) {
					$this->logEmptyOperand( 'var assignment', __METHOD__ );
				}
				$this->setUserVariable( $varname, $result );

				return;
			} elseif ( $this->mCur->type === AFPToken::TSQUAREBRACKET && $this->mCur->value === '[' ) {
				// We allow builtin variables to both check for override (e.g. added_lines[] :='x')
				// and for T198531
				if ( !$this->varExists( $varname ) ) {
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
								[ $idx, count( $array->getData() ) ] );
						}
					}
				}
				$this->move();
				if ( $this->mCur->type === AFPToken::TOP && $this->mCur->value === ':=' ) {
					if ( $this->isBuiltinVar( $varname ) ) {
						// Ideally we should've aborted before trying to parse the index
						throw new AFPUserVisibleException( 'overridebuiltin', $this->mCur->pos, [ $varname ] );
					}
					$this->move();
					if ( $this->mCur->type === AFPToken::TNONE ) {
						$this->logEmptyOperand( 'array assignment', __METHOD__ );
					}
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
			$checkEmpty = $result->getType() === AFPData::DEMPTY;
			$this->doLevelBoolOps( $result );
			if ( $checkEmpty && $result->getType() === AFPData::DEMPTY ) {
				$this->logEmptyOperand( 'if condition', __METHOD__ );
			}

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
				$scOrig = wfSetVar( $this->mShortCircuit, $this->mAllowShort, true );
			}
			$this->doLevelConditions( $r1 );
			if ( $r1->getType() === AFPData::DEMPTY ) {
				$this->logEmptyOperand( 'if body', __METHOD__ );
			}
			if ( !$isTrue ) {
				$this->mShortCircuit = $scOrig;
			}

			if ( $this->mCur->type === AFPToken::TKEYWORD && $this->mCur->value === 'else' ) {
				$this->move();

				if ( $isTrue ) {
					$scOrig = wfSetVar( $this->mShortCircuit, $this->mAllowShort, true );
				}
				$this->doLevelConditions( $r2 );
				if ( $r2->getType() === AFPData::DEMPTY ) {
					$this->logEmptyOperand( 'else body', __METHOD__ );
				}
				if ( $isTrue ) {
					$this->mShortCircuit = $scOrig;
				}
			} else {
				// DNULL is assumed as default in case of a missing else
				$r2 = new AFPData( AFPData::DNULL );
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
					$scOrig = wfSetVar( $this->mShortCircuit, $this->mAllowShort, true );
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
					$scOrig = wfSetVar( $this->mShortCircuit, $this->mAllowShort, true );
				}
				$this->doLevelConditions( $r2 );
				if ( $r2->getType() === AFPData::DEMPTY ) {
					$this->logEmptyOperand( 'ternary else', __METHOD__ );
				}
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
			$curVal = $result->getType() === AFPData::DUNDEFINED ? false : $result->toBool();

			// We can go on quickly as either one statement with | is true or one with & is false
			if ( ( $op === '&' && !$curVal ) || ( $op === '|' && $curVal ) ) {
				$scOrig = wfSetVar( $this->mShortCircuit, $this->mAllowShort, true );
				$this->doLevelCompares( $r2 );
				if ( $r2->getType() === AFPData::DEMPTY ) {
					$this->logEmptyOperand( 'bool operand', __METHOD__ );
				}
				$this->mShortCircuit = $scOrig;
				$result = new AFPData( AFPData::DBOOL, $curVal );
				continue;
			}

			$this->doLevelCompares( $r2 );
			if ( $r2->getType() === AFPData::DEMPTY ) {
				$this->logEmptyOperand( 'bool operand', __METHOD__ );
			}
			$result = $result->boolOp( $r2, $op );
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
			if ( $r2->getType() === AFPData::DEMPTY ) {
				$this->logEmptyOperand( 'compare operand', __METHOD__ );
			}
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				continue;
			}
			$this->raiseCondCount();
			$result = $result->compareOp( $r2, $op );
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
			if ( $r2->getType() === AFPData::DEMPTY ) {
				$this->logEmptyOperand( 'sum operand', __METHOD__ );
			}
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				continue;
			}
			if ( $op === '+' ) {
				$result = $result->sum( $r2 );
			}
			if ( $op === '-' ) {
				$result = $result->sub( $r2 );
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
			if ( $r2->getType() === AFPData::DEMPTY ) {
				$this->logEmptyOperand( 'multiplication operand', __METHOD__ );
			}
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				continue;
			}
			$result = $result->mulRel( $r2, $op, $this->mCur->pos );
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
			if ( $expanent->getType() === AFPData::DEMPTY ) {
				$this->logEmptyOperand( 'power operand', __METHOD__ );
			}
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				continue;
			}
			$result = $result->pow( $expanent );
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
			$checkEmpty = $result->getType() === AFPData::DEMPTY;
			$this->doLevelSpecialWords( $result );
			if ( $checkEmpty && $result->getType() === AFPData::DEMPTY ) {
				$this->logEmptyOperand( 'bool inversion', __METHOD__ );
			}
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				return;
			}
			$result = $result->boolInvert();
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

			if ( $r2->getType() === AFPData::DEMPTY ) {
				$this->logEmptyOperand( 'keyword operand', __METHOD__ );
			}

			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				return;
			}

			if ( $result->getType() === AFPData::DUNDEFINED || $r2->getType() === AFPData::DUNDEFINED ) {
				$result = new AFPData( AFPData::DUNDEFINED );
			} else {
				$this->raiseCondCount();

				// @phan-suppress-next-line PhanParamTooMany Not every function needs the position
				$result = $this->$func( $result, $r2, $this->mCur->pos );
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
			$checkEmpty = $result->getType() === AFPData::DEMPTY;
			$this->doLevelArrayElements( $result );
			if ( $checkEmpty && $result->getType() === AFPData::DEMPTY ) {
				$this->logEmptyOperand( 'unary operand', __METHOD__ );
			}
			if ( $this->mShortCircuit ) {
				// The result doesn't matter.
				return;
			}
			if ( $op === '-' ) {
				$result = $result->unaryMinus();
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
			$next = $this->getNextToken();
			if ( $next->type === AFPToken::TBRACE && $next->value === ')' ) {
				// We don't need DUNDEFINED here
				$this->move();
				$this->move();
			} else {
				if ( $this->mShortCircuit ) {
					$result = new AFPData( AFPData::DUNDEFINED );
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
			}
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
			$fname = $this->mCur->value;
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
				$result = new AFPData( AFPData::DUNDEFINED );
				$this->skipOverBraces();
				$this->move();

				// The result doesn't matter.
				return;
			}

			$args = [];
			$next = $this->getNextToken();
			if ( $next->type !== AFPToken::TBRACE || $next->value !== ')' ) {
				if ( ( $fname === 'set' || $fname === 'set_var' ) ) {
					$state = $this->getState();
					$this->move();
					$next = $this->getNextToken();
					if (
						$this->mCur->type !== AFPToken::TSTRING ||
						(
							$next->type !== AFPToken::TCOMMA &&
							// Let this fail later, when checking parameters count
							!( $next->type === AFPToken::TBRACE && $next->value === ')' )
						)
					) {
						throw new AFPUserVisibleException( 'variablevariable', $this->mCur->pos, [] );
					} else {
						$this->setState( $state );
					}
				}
				do {
					$r = new AFPData( AFPData::DEMPTY );
					$this->doLevelSemicolon( $r );
					if ( $r->getType() === AFPData::DEMPTY ) {
						$this->logEmptyOperand( 'function argument', __METHOD__ );
					}
					$args[] = $r;
				} while ( $this->mCur->type === AFPToken::TCOMMA );
			} else {
				$this->move();
			}

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

			$result = $this->callFunc( $fname, $args );
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
	 * Check whether a variable exists, being either built-in or user-defined
	 *
	 * @param string $varname
	 * @return bool
	 */
	protected function varExists( $varname ) {
		$builderValues = AbuseFilter::getBuilderValues();

		return array_key_exists( $varname, $builderValues['vars'] ) ||
			$this->mVariables->varIsSet( $varname );
	}

	/**
	 * @param string $var
	 * @return AFPData
	 * @throws AFPUserVisibleException
	 */
	protected function getVarValue( $var ) {
		$var = strtolower( $var );
		$deprecatedVars = AbuseFilter::getDeprecatedVariables();

		if ( array_key_exists( $var, $deprecatedVars ) ) {
			$this->logger->debug( "AbuseFilter: deprecated variable $var used." );
			$var = $deprecatedVars[$var];
		}
		if ( !$this->varExists( $var ) ) {
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
	 * Check whether the given name refers to a built-in variable, including
	 * deprecated and disabled variables.
	 *
	 * @param string $varname
	 * @return bool
	 */
	protected function isBuiltinVar( $varname ) {
		$builderValues = AbuseFilter::getBuilderValues();
		$deprecatedVars = AbuseFilter::getDeprecatedVariables();

		return array_key_exists( $varname, $builderValues['vars'] ) ||
			array_key_exists( $varname, AbuseFilter::$disabledVars ) ||
			array_key_exists( $varname, $deprecatedVars );
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 * @throws AFPUserVisibleException
	 */
	protected function setUserVariable( $name, $value ) {
		if ( $this->isBuiltinVar( $name ) ) {
			throw new AFPUserVisibleException( 'overridebuiltin', $this->mCur->pos, [ $name ] );
		}
		$this->mVariables->setVar( $name, $value );
	}

	/**
	 * Check that a built-in function has been provided the right amount of arguments
	 *
	 * @param array $args The arguments supplied to the function
	 * @param string $func The function name
	 * @throws AFPUserVisibleException
	 */
	protected function checkArgCount( $args, $func ) {
		if ( !array_key_exists( $func, self::$funcArgCount ) ) {
			throw new InvalidArgumentException( "$func is not a valid function." );
		}
		list( $min, $max ) = self::$funcArgCount[ $func ];
		if ( count( $args ) < $min ) {
			throw new AFPUserVisibleException(
				$min === 1 ? 'noparams' : 'notenoughargs',
				$this->mCur->pos,
				[ $func, $min, count( $args ) ]
			);
		} elseif ( count( $args ) > $max ) {
			$this->logger->warning(
				"Too many params to $func for filter: " . ( $this->mFilter ?? 'unavailable' )
			);
			/*
			 @todo Uncomment after fixing filters in WMF production
			throw new AFPUserVisibleException(
				'toomanyargs',
				$this->mCur->pos,
				[ $func, $max, count( $args ) ]
			);
			*/
		}
	}

	/**
	 * Helper to call a built-in function.
	 *
	 * @param string $fname The name of the function as found in the filter code
	 * @param AFPData[] $args Arguments for the function
	 * @return AFPData The return value of the function
	 * @throws InvalidArgumentException if given an invalid func
	 */
	protected function callFunc( $fname, array $args ) : AFPData {
		if ( !array_key_exists( $fname, self::$mFunctions ) ) {
			throw new InvalidArgumentException( "$fname is not a valid function." );
		}

		$funcHandler = self::$mFunctions[$fname];
		$funcHash = md5( $funcHandler . serialize( $args ) );

		if ( isset( $this->funcCache[$funcHash] ) &&
			!in_array( $funcHandler, self::$ActiveFunctions )
		) {
			$result = $this->funcCache[$funcHash];
		} else {
			$this->checkArgCount( $args, $fname );
			$this->raiseCondCount();
			$hasUndefinedArg = false;
			foreach ( $args as $arg ) {
				if ( $arg->type === AFPData::DUNDEFINED ) {
					$hasUndefinedArg = true;
					break;
				}
			}
			if ( $hasUndefinedArg ) {
				$result = new AFPData( AFPData::DUNDEFINED );
			} else {
				$result = $this->$funcHandler( $args );
			}
			$this->funcCache[$funcHash] = $result;
		}

		if ( count( $this->funcCache ) > 1000 ) {
			// @codeCoverageIgnoreStart
			$this->clearFuncCache();
			// @codeCoverageIgnoreEnd
		}
		return $result;
	}

	// Built-in functions

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcLc( $args ) {
		$s = $args[0]->toString();

		return new AFPData( AFPData::DSTRING, $this->contLang->lc( $s ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcUc( $args ) {
		$s = $args[0]->toString();

		return new AFPData( AFPData::DSTRING, $this->contLang->uc( $s ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcLen( $args ) {
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
		$s = array_shift( $args );

		return new AFPData( AFPData::DBOOL, self::contains( $s, $args, true ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcContainsAll( $args ) {
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
		$s = $args[0]->toString();

		return new AFPData( AFPData::DSTRING, $this->rmspecials( $s ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcRMWhitespace( $args ) {
		$s = $args[0]->toString();

		return new AFPData( AFPData::DSTRING, $this->rmwhitespace( $s ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcRMDoubles( $args ) {
		$s = $args[0]->toString();

		return new AFPData( AFPData::DSTRING, $this->rmdoubles( $s ) );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function funcNorm( $args ) {
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
		$varName = $args[0]->toString();
		$value = $args[1];

		$this->setUserVariable( $varName, $value );

		return $value;
	}

	/**
	 * Checks if $a contains $b
	 *
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	protected function containmentKeyword( AFPData $a, AFPData $b ) {
		$a = $a->toString();
		$b = $b->toString();

		if ( $a === '' || $b === '' ) {
			return new AFPData( AFPData::DBOOL, false );
		}

		return new AFPData( AFPData::DBOOL, strpos( $a, $b ) !== false );
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	protected function keywordIn( AFPData $a, AFPData $b ) {
		return $this->containmentKeyword( $b, $a );
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	protected function keywordContains( AFPData $a, AFPData $b ) {
		return $this->containmentKeyword( $a, $b );
	}

	/**
	 * @param AFPData $str
	 * @param AFPData $pattern
	 * @return AFPData
	 */
	protected function keywordLike( AFPData $str, AFPData $pattern ) {
		$str = $str->toString();
		$pattern = '#^' . strtr( preg_quote( $pattern->toString(), '#' ), AFPData::$wildcardMap ) . '$#u';
		Wikimedia\suppressWarnings();
		$result = preg_match( $pattern, $str );
		Wikimedia\restoreWarnings();

		return new AFPData( AFPData::DBOOL, (bool)$result );
	}

	/**
	 * @param AFPData $str
	 * @param AFPData $regex
	 * @param int $pos
	 * @param bool $insensitive
	 * @return AFPData
	 * @throws Exception
	 */
	protected function keywordRegex( AFPData $str, AFPData $regex, $pos, $insensitive = false ) {
		$str = $str->toString();
		$pattern = $regex->toString();

		$pattern = preg_replace( '!(\\\\\\\\)*(\\\\)?/!', '$1\/', $pattern );
		$pattern = "/$pattern/u";

		if ( $insensitive ) {
			$pattern .= 'i';
		}

		Wikimedia\suppressWarnings();
		$result = preg_match( $pattern, $str );
		Wikimedia\restoreWarnings();
		if ( $result === false ) {
			throw new AFPUserVisibleException(
				'regexfailure',
				// Coverage bug
				// @codeCoverageIgnoreStart
				$pos,
				// @codeCoverageIgnoreEnd
				[ $pattern ]
			);
		}

		return new AFPData( AFPData::DBOOL, (bool)$result );
	}

	/**
	 * @param AFPData $str
	 * @param AFPData $regex
	 * @param int $pos
	 * @return AFPData
	 */
	protected function keywordRegexInsensitive( AFPData $str, AFPData $regex, $pos ) {
		return $this->keywordRegex( $str, $regex, $pos, true );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function castString( $args ) {
		return AFPData::castTypes( $args[0], AFPData::DSTRING );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function castInt( $args ) {
		return AFPData::castTypes( $args[0], AFPData::DINT );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function castFloat( $args ) {
		return AFPData::castTypes( $args[0], AFPData::DFLOAT );
	}

	/**
	 * @param array $args
	 * @return AFPData
	 */
	protected function castBool( $args ) {
		return AFPData::castTypes( $args[0], AFPData::DBOOL );
	}

	/**
	 * Log empty operands for T156096
	 *
	 * @param string $type Type of the empty operand
	 * @param string $fname Method where the empty operand is found
	 */
	protected function logEmptyOperand( $type, $fname ) {
		$this->logger->info(
			"Empty operand of type {type} at method {fname}. Filter: {filter}",
			[
				'type' => $type,
				'fname' => $fname,
				'filter' => $this->mFilter ?? 'unavailable'
			]
		);
	}
}
