<?php

namespace MediaWiki\Extension\AbuseFilter\Parser;

use BagOStuff;
use Exception;
use IBufferingStatsdDataFactory;
use InvalidArgumentException;
use Language;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\Parser\Exception\ConditionLimitException;
use MediaWiki\Extension\AbuseFilter\Parser\Exception\ExceptionBase;
use MediaWiki\Extension\AbuseFilter\Parser\Exception\InternalException;
use MediaWiki\Extension\AbuseFilter\Parser\Exception\UserVisibleException;
use MediaWiki\Extension\AbuseFilter\Parser\Exception\UserVisibleWarning;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MWException;
use NullStatsdDataFactory;
use Psr\Log\LoggerInterface;
use Sanitizer;
use Wikimedia\AtEase\AtEase;
use Wikimedia\Equivset\Equivset;
use Wikimedia\IPUtils;

/**
 * AbuseFilterCachingParser is the AbuseFilter parser which parses
 * the code into an abstract syntax tree before evaluating it, and caches that
 * tree.
 *
 * @todo Override checkSyntax and make it only try to build the AST. That would mean faster results,
 *   and no need to mess with DUNDEFINED and the like. However, we must first try to reduce the
 *   amount of runtime-only exceptions, and try to detect them in the AFPTreeParser instead.
 *   Otherwise, people may be able to save a broken filter without the syntax check reporting that.
 */
class AbuseFilterCachingParser {
	private const CACHE_VERSION = 1;

	public const FUNCTIONS = [
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
	 */
	public const FUNC_ARG_COUNT = [
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
	public const ACTIVE_FUNCTIONS = [
		'funcSetVar',
	];

	public const KEYWORDS = [
		'in' => 'keywordIn',
		'like' => 'keywordLike',
		'matches' => 'keywordLike',
		'contains' => 'keywordContains',
		'rlike' => 'keywordRegex',
		'irlike' => 'keywordRegexInsensitive',
		'regex' => 'keywordRegex',
	];

	/**
	 * @var bool Are we allowed to use short-circuit evaluation?
	 */
	public $mAllowShort;

	/**
	 * @var VariableHolder
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
	 * @var bool Whether we can allow retrieving _builtin_ variables not included in $this->mVariables
	 */
	protected $allowMissingVariables = false;

	/**
	 * @var BagOStuff Used to cache the AST (in CachingParser) and the tokens
	 */
	protected $cache;
	/**
	 * @var bool Whether the AST was retrieved from cache (CachingParser only)
	 */
	protected $fromCache = false;
	/**
	 * @var LoggerInterface Used for debugging
	 */
	protected $logger;
	/**
	 * @var Language Content language, used for language-dependent functions
	 */
	protected $contLang;
	/**
	 * @var IBufferingStatsdDataFactory
	 */
	protected $statsd;

	/** @var KeywordsManager */
	protected $keywordsManager;

	/** @var VariablesManager */
	protected $varManager;

	/** @var int */
	private $conditionsLimit;

	/** @var UserVisibleWarning[] */
	protected $warnings = [];

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
	 * @param KeywordsManager $keywordsManager
	 * @param VariablesManager $varManager
	 * @param int $conditionsLimit
	 * @param VariableHolder|null $vars
	 */
	public function __construct(
		Language $contLang,
		BagOStuff $cache,
		LoggerInterface $logger,
		KeywordsManager $keywordsManager,
		VariablesManager $varManager,
		int $conditionsLimit,
		VariableHolder $vars = null
	) {
		$this->contLang = $contLang;
		$this->cache = $cache;
		$this->logger = $logger;
		$this->statsd = new NullStatsdDataFactory;
		$this->keywordsManager = $keywordsManager;
		$this->varManager = $varManager;
		$this->conditionsLimit = $conditionsLimit;
		$this->resetState();
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
	 * @param IBufferingStatsdDataFactory $statsd
	 */
	public function setStatsd( IBufferingStatsdDataFactory $statsd ) {
		$this->statsd = $statsd;
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
	 */
	public function toggleConditionLimit( $enable ) {
		$this->condLimitEnabled = $enable;
	}

	/**
	 * @param int $val The amount to increase the conditions count of.
	 * @throws ConditionLimitException
	 */
	protected function raiseCondCount( $val = 1 ) {
		$this->mCondCount += $val;

		if ( $this->condLimitEnabled && $this->mCondCount > $this->conditionsLimit ) {
			throw new ConditionLimitException();
		}
	}

	/**
	 * Clears the array of cached function results
	 */
	public function clearFuncCache() {
		$this->funcCache = [];
	}

	/**
	 * @param VariableHolder $vars
	 */
	public function setVariables( VariableHolder $vars ) {
		$this->mVariables = $vars;
	}

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
			self::CACHE_VERSION,
			AFPTreeParser::CACHE_VERSION,
			AbuseFilterTokenizer::CACHE_VERSION,
			SyntaxChecker::CACHE_VERSION,
			array_keys( self::FUNCTIONS ),
			array_keys( self::KEYWORDS ),
		];
		$version = hash( 'sha256', serialize( $versionKey ) );

		return $version;
	}

	/**
	 * Resets the state of the parser
	 */
	public function resetState() {
		$this->mVariables = new VariableHolder();
		$this->mCondCount = 0;
		$this->mAllowShort = true;
		$this->mFilter = null;
		$this->warnings = [];
	}

	/**
	 * Check the syntax of $filter, throwing an exception if invalid
	 * @param string $filter
	 * @return true When successful
	 * @throws UserVisibleException
	 */
	public function checkSyntaxThrow( string $filter ): bool {
		$this->allowMissingVariables = true;
		$origAS = $this->mAllowShort;
		try {
			$this->mAllowShort = false;
			$this->intEval( $filter );
		} finally {
			$this->mAllowShort = $origAS;
			$this->allowMissingVariables = false;
		}

		return true;
	}

	/**
	 * Check the syntax of $filter, without throwing
	 *
	 * @param string $filter
	 * @return ParserStatus The result indicates whether the syntax is valid
	 */
	public function checkSyntax( string $filter ): ParserStatus {
		try {
			$valid = $this->checkSyntaxThrow( $filter );
		} catch ( UserVisibleException $excep ) {
			$valid = false;
		}
		return new ParserStatus( $valid, $this->fromCache, $excep ?? null, $this->warnings );
	}

	/**
	 * This is the main entry point. It checks the given conditions and returns whether
	 * they match. In case of bad syntax, this is always logged, and $ignoreError can
	 * be used to determine whether this method should throw.
	 *
	 * @param string $conds
	 * @param string|null $filter The ID of the filter being parsed
	 * @return ParserStatus
	 */
	public function checkConditions( string $conds, $filter = null ): ParserStatus {
		$result = $this->parseDetailed( $conds );
		$excep = $result->getException();
		if ( $excep !== null ) {
			if ( $excep instanceof UserVisibleException ) {
				$msg = $excep->getMessageForLogs();
			} else {
				$msg = $excep->getMessage();
			}

			$extraInfo = $filter !== null ? " for filter $filter" : '';
			$this->logger->warning( "AbuseFilter parser error$extraInfo: $msg" );
		}

		return $result;
	}

	/**
	 * @param string $code
	 * @return AFPData
	 */
	public function intEval( $code ): AFPData {
		$startTime = microtime( true );
		$tree = $this->getTree( $code );
		$res = $this->evalTree( $tree );

		if ( $res->getType() === AFPData::DUNDEFINED ) {
			$res = new AFPData( AFPData::DBOOL, false );
		}
		$this->statsd->timing( 'abusefilter_cachingParser_full', microtime( true ) - $startTime );
		return $res;
	}

	/**
	 * @param string $code
	 * @return bool
	 */
	public function parse( $code ) {
		return $this->intEval( $code )->toBool();
	}

	/**
	 * Like self::parse(), but returns an object with additional info
	 * @param string $code
	 * @return ParserStatus
	 */
	public function parseDetailed( string $code ): ParserStatus {
		$excep = null;
		try {
			$res = $this->parse( $code );
		} catch ( ExceptionBase $excep ) {
			$res = false;
		}
		return new ParserStatus( $res, $this->fromCache, $excep, $this->warnings );
	}

	/**
	 * @param string $filter
	 * @return mixed
	 */
	public function evaluateExpression( $filter ) {
		return $this->intEval( $filter )->toNative();
	}

	/**
	 * @param string $code
	 * @return AFPSyntaxTree
	 */
	private function getTree( $code ): AFPSyntaxTree {
		$this->fromCache = true;
		return $this->cache->getWithSetCallback(
			$this->cache->makeGlobalKey(
				__CLASS__,
				self::getCacheVersion(),
				hash( 'sha256', $code )
			),
			BagOStuff::TTL_DAY,
			function () use ( $code ) {
				$this->fromCache = false;
				$parser = new AFPTreeParser( $this->cache, $this->logger, $this->statsd, $this->keywordsManager );
				$parser->setFilter( $this->mFilter );
				$tree = $parser->parse( $code );
				$checker = new SyntaxChecker(
					$tree,
					$this->keywordsManager,
					SyntaxChecker::MCONSERVATIVE,
					false
				);
				$checker->start();
				return $tree;
			}
		);
	}

	/**
	 * @param AFPSyntaxTree $tree
	 * @return AFPData
	 */
	private function evalTree( AFPSyntaxTree $tree ): AFPData {
		$startTime = microtime( true );
		$root = $tree->getRoot();

		if ( !$root ) {
			return new AFPData( AFPData::DNULL );
		}

		$ret = $this->evalNode( $root );
		$this->statsd->timing( 'abusefilter_cachingParser_eval', microtime( true ) - $startTime );
		return $ret;
	}

	/**
	 * Evaluate the value of the specified AST node.
	 *
	 * @param AFPTreeNode $node The node to evaluate.
	 * @return AFPData|AFPTreeNode|string
	 * @throws ExceptionBase
	 * @throws UserVisibleException
	 * @throws MWException
	 */
	private function evalNode( AFPTreeNode $node ) {
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
								return new AFPData( AFPData::DNULL );
						}
					// Fallthrough intended
					default:
						// @codeCoverageIgnoreStart
						throw new InternalException( "Unknown token provided in the ATOM node" );
						// @codeCoverageIgnoreEnd
				}
				// Unreachable line
			case AFPTreeNode::ARRAY_DEFINITION:
				$items = [];
				// Foreach is usually faster than array_map
				// @phan-suppress-next-line PhanTypeSuspiciousNonTraversableForeach children is array here
				foreach ( $node->children as $el ) {
					$items[] = $this->evalNode( $el );
				}
				return new AFPData( AFPData::DARRAY, $items );

			case AFPTreeNode::FUNCTION_CALL:
				$functionName = $node->children[0];
				$args = array_slice( $node->children, 1 );

				$dataArgs = [];
				// Foreach is usually faster than array_map
				foreach ( $args as $arg ) {
					$dataArgs[] = $this->evalNode( $arg );
				}

				return $this->callFunc( $functionName, $dataArgs, $node->position );
			case AFPTreeNode::ARRAY_INDEX:
				list( $array, $offset ) = $node->children;

				$array = $this->evalNode( $array );
				// Note: we MUST evaluate the offset to ensure it is valid, regardless
				// of $array!
				$offset = $this->evalNode( $offset );
				// @todo If $array has no elements we could already throw an outofbounds. We don't
				// know what the index is, though.
				if ( $offset->getType() === AFPData::DUNDEFINED ) {
					return new AFPData( AFPData::DUNDEFINED );
				}
				$offset = $offset->toInt();

				if ( $array->getType() === AFPData::DUNDEFINED ) {
					return new AFPData( AFPData::DUNDEFINED );
				}

				if ( $array->getType() !== AFPData::DARRAY ) {
					throw new UserVisibleException( 'notarray', $node->position, [] );
				}

				$array = $array->toArray();
				if ( count( $array ) <= $offset ) {
					throw new UserVisibleException( 'outofbounds', $node->position,
						[ $offset, count( $array ) ] );
				} elseif ( $offset < 0 ) {
					throw new UserVisibleException( 'negativeindex', $node->position, [ $offset ] );
				}

				return $array[$offset];

			case AFPTreeNode::UNARY:
				list( $operation, $argument ) = $node->children;
				$argument = $this->evalNode( $argument );
				if ( $operation === '-' ) {
					return $argument->unaryMinus();
				}
				return $argument;

			case AFPTreeNode::KEYWORD_OPERATOR:
				list( $keyword, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );

				return $this->callKeyword( $keyword, $leftOperand, $rightOperand, $node->position );
			case AFPTreeNode::BOOL_INVERT:
				list( $argument ) = $node->children;
				$argument = $this->evalNode( $argument );
				return $argument->boolInvert();

			case AFPTreeNode::POW:
				list( $base, $exponent ) = $node->children;
				$base = $this->evalNode( $base );
				$exponent = $this->evalNode( $exponent );
				return $base->pow( $exponent );

			case AFPTreeNode::MUL_REL:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				return $leftOperand->mulRel( $rightOperand, $op, $node->position );

			case AFPTreeNode::SUM_REL:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				switch ( $op ) {
					case '+':
						return $leftOperand->sum( $rightOperand );
					case '-':
						return $leftOperand->sub( $rightOperand );
					default:
						// @codeCoverageIgnoreStart
						throw new InternalException( "Unknown sum-related operator: {$op}" );
						// @codeCoverageIgnoreEnd
				}
				// Unreachable line
			case AFPTreeNode::COMPARE:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$rightOperand = $this->evalNode( $rightOperand );
				$this->raiseCondCount();
				return $leftOperand->compareOp( $rightOperand, $op );

			case AFPTreeNode::LOGIC:
				list( $op, $leftOperand, $rightOperand ) = $node->children;
				$leftOperand = $this->evalNode( $leftOperand );
				$value = $leftOperand->getType() === AFPData::DUNDEFINED ? false : $leftOperand->toBool();
				// Short-circuit.
				if ( ( !$value && $op === '&' ) || ( $value && $op === '|' ) ) {
					if ( $rightOperand instanceof AFPTreeNode ) {
						$this->maybeDiscardNode( $rightOperand );
					}
					return $leftOperand;
				}
				$rightOperand = $this->evalNode( $rightOperand );
				return $leftOperand->boolOp( $rightOperand, $op );

			case AFPTreeNode::CONDITIONAL:
				list( $condition, $valueIfTrue, $valueIfFalse ) = $node->children;
				$condition = $this->evalNode( $condition );
				$isTrue = $condition->getType() === AFPData::DUNDEFINED ? false : $condition->toBool();
				if ( $isTrue ) {
					if ( $valueIfFalse !== null ) {
						$this->maybeDiscardNode( $valueIfFalse );
					}
					return $this->evalNode( $valueIfTrue );
				} else {
					$this->maybeDiscardNode( $valueIfTrue );
					return $valueIfFalse !== null
						? $this->evalNode( $valueIfFalse )
						// We assume null as default if the else is missing
						: new AFPData( AFPData::DNULL );
				}

			case AFPTreeNode::ASSIGNMENT:
				list( $varName, $value ) = $node->children;
				$value = $this->evalNode( $value );
				$this->setUserVariable( $varName, $value );
				return $value;

			case AFPTreeNode::INDEX_ASSIGNMENT:
				list( $varName, $offset, $value ) = $node->children;

				$array = $this->getVarValue( $varName );

				if ( $array->getType() !== AFPData::DARRAY && $array->getType() !== AFPData::DUNDEFINED ) {
					throw new UserVisibleException( 'notarray', $node->position, [] );
				}

				$offset = $this->evalNode( $offset );
				// @todo If $array has no elements we could already throw an outofbounds. We don'tan
				// know what the index is, though.

				if ( $array->getType() !== AFPData::DUNDEFINED ) {
					// If it's a DUNDEFINED, leave it as is
					if ( $offset->getType() !== AFPData::DUNDEFINED ) {
						$offset = $offset->toInt();
						$array = $array->toArray();
						if ( count( $array ) <= $offset ) {
							throw new UserVisibleException( 'outofbounds', $node->position,
								[ $offset, count( $array ) ] );
						} elseif ( $offset < 0 ) {
							throw new UserVisibleException( 'negativeindex', $node->position, [ $offset ] );
						}

						$value = $this->evalNode( $value );
						$array[$offset] = $value;
						$array = new AFPData( AFPData::DARRAY, $array );
					} else {
						$value = $this->evalNode( $value );
						$array = new AFPData( AFPData::DUNDEFINED );
					}
					$this->setUserVariable( $varName, $array );
				} else {
					$value = $this->evalNode( $value );
				}

				return $value;

			case AFPTreeNode::ARRAY_APPEND:
				list( $varName, $value ) = $node->children;

				$array = $this->getVarValue( $varName );
				$value = $this->evalNode( $value );
				if ( $array->getType() !== AFPData::DUNDEFINED ) {
					// If it's a DUNDEFINED, leave it as is
					if ( $array->getType() !== AFPData::DARRAY ) {
						throw new UserVisibleException( 'notarray', $node->position, [] );
					}

					$array = $array->toArray();
					$array[] = $value;
					$this->setUserVariable( $varName, new AFPData( AFPData::DARRAY, $array ) );
				}
				return $value;

			case AFPTreeNode::SEMICOLON:
				$lastValue = null;
				// @phan-suppress-next-line PhanTypeSuspiciousNonTraversableForeach children is array here
				foreach ( $node->children as $statement ) {
					$lastValue = $this->evalNode( $statement );
				}

				// @phan-suppress-next-next-line PhanTypeMismatchReturnNullable Can never be null because
				// empty statements are discarded in AFPTreeParser
				return $lastValue;
			default:
				// @codeCoverageIgnoreStart
				throw new InternalException( "Unknown node type passed: {$node->type}" );
				// @codeCoverageIgnoreEnd
		}
	}

	/**
	 * Helper to call a built-in function.
	 *
	 * @param string $fname The name of the function as found in the filter code
	 * @param AFPData[] $args Arguments for the function
	 * @param int $position
	 * @return AFPData The return value of the function
	 * @throws InvalidArgumentException if given an invalid func
	 */
	protected function callFunc( $fname, array $args, int $position ): AFPData {
		if ( !array_key_exists( $fname, self::FUNCTIONS ) ) {
			// @codeCoverageIgnoreStart
			throw new InvalidArgumentException( "$fname is not a valid function." );
			// @codeCoverageIgnoreEnd
		}

		$funcHandler = self::FUNCTIONS[$fname];
		$funcHash = md5( $funcHandler . serialize( $args ) );

		if ( isset( $this->funcCache[$funcHash] ) &&
			!in_array( $funcHandler, self::ACTIVE_FUNCTIONS )
		) {
			$result = $this->funcCache[$funcHash];
		} else {
			$this->raiseCondCount();

			// Any undefined argument should be special-cased by the function, but that would be too
			// much overhead. We also cannot skip calling the handler in case it's making further
			// validation (T234339). So temporarily replace the DUNDEFINED with a DNULL.
			// @todo This is subpar.
			$hasUndefinedArg = false;
			foreach ( $args as $i => $arg ) {
				if ( $arg->hasUndefined() ) {
					$args[$i] = $arg->cloneAsUndefinedReplacedWithNull();
					$hasUndefinedArg = true;
				}
			}
			if ( $hasUndefinedArg ) {
				// @phan-suppress-next-line PhanParamTooMany Not every function needs the position
				$this->$funcHandler( $args, $position );
				$result = new AFPData( AFPData::DUNDEFINED );
			} else {
				// @phan-suppress-next-line PhanParamTooMany Not every function needs the position
				$result = $this->$funcHandler( $args, $position );
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

	/**
	 * Helper to invoke a built-in keyword. Note that this assumes that $kname is
	 * a valid keyword name.
	 *
	 * @param string $kname
	 * @param AFPData $lhs
	 * @param AFPData $rhs
	 * @param int $position
	 * @return AFPData
	 */
	protected function callKeyword( $kname, AFPData $lhs, AFPData $rhs, int $position ): AFPData {
		$func = self::KEYWORDS[$kname];
		$this->raiseCondCount();

		$hasUndefinedOperand = false;
		if ( $lhs->hasUndefined() ) {
			$lhs = $lhs->cloneAsUndefinedReplacedWithNull();
			$hasUndefinedOperand = true;
		}
		if ( $rhs->hasUndefined() ) {
			$rhs = $rhs->cloneAsUndefinedReplacedWithNull();
			$hasUndefinedOperand = true;
		}
		if ( $hasUndefinedOperand ) {
			// We need to run the handler with bogus args, see the comment in self::callFunc (T234339)
			// @todo Likewise, this is subpar.
			// @phan-suppress-next-line PhanParamTooMany Not every function needs the position
			$this->$func( $lhs, $rhs, $position );
			$result = new AFPData( AFPData::DUNDEFINED );
		} else {
			// @phan-suppress-next-line PhanParamTooMany Not every function needs the position
			$result = $this->$func( $lhs, $rhs, $position );
		}
		return $result;
	}

	/**
	 * Check whether a variable exists, being either built-in or user-defined. Doesn't include
	 * disabled variables.
	 *
	 * @param string $varname
	 * @return bool
	 */
	protected function varExists( $varname ) {
		return $this->keywordsManager->isVarInUse( $varname ) ||
			$this->mVariables->varIsSet( $varname );
	}

	/**
	 * @param string $var
	 * @return AFPData
	 * @throws UserVisibleException
	 */
	protected function getVarValue( $var ) {
		$var = strtolower( $var );
		$deprecatedVars = $this->keywordsManager->getDeprecatedVariables();

		if ( array_key_exists( $var, $deprecatedVars ) ) {
			$var = $deprecatedVars[ $var ];
		}
		// With check syntax, all unbound variables will be caught
		// already. So we do not error unbound variables at runtime,
		// allowing it to result in DUNDEFINED.
		$allowMissingVariables = !$this->varExists( $var ) || $this->allowMissingVariables;

		// It's a built-in, non-disabled variable (either set or unset), or a set custom variable
		$flags = $allowMissingVariables
			? VariablesManager::GET_LAX
			// TODO: This should be GET_STRICT, but that's going to be very hard (see T230256)
			: VariablesManager::GET_BC;
		return $this->varManager->getVar( $this->mVariables, $var, $flags, $this->mFilter );
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 * @throws UserVisibleException
	 */
	protected function setUserVariable( $name, $value ) {
		$this->mVariables->setVar( $name, $value );
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
	 * @param int $position
	 * @return AFPData
	 * @throws UserVisibleException
	 */
	protected function funcRCount( $args, int $position ) {
		if ( count( $args ) === 1 ) {
			$count = count( explode( ',', $args[0]->toString() ) );
		} else {
			$needle = $args[0]->toString();
			$haystack = $args[1]->toString();

			$needle = $this->mungeRegexp( $needle );

			// Suppress and restore needed per T177744
			AtEase::suppressWarnings();
			$this->checkRegexMatchesEmpty( $args[0], $needle, $position );
			$count = preg_match_all( $needle, $haystack );
			AtEase::restoreWarnings();

			if ( $count === false ) {
				throw new UserVisibleException(
					'regexfailure',
					$position,
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
	 * @param int $position
	 * @return AFPData An array of matches.
	 * @throws UserVisibleException
	 */
	protected function funcGetMatches( $args, int $position ) {
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

		$needle = $this->mungeRegexp( $needle );

		// Suppress and restore are here for the same reason as T177744
		AtEase::suppressWarnings();
		$this->checkRegexMatchesEmpty( $args[0], $needle, $position );
		$check = preg_match( $needle, $haystack, $matches );
		AtEase::restoreWarnings();

		if ( $check === false ) {
			throw new UserVisibleException(
				'regexfailure',
				$position,
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
	 * @param int $position
	 * @return AFPData
	 * @throws UserVisibleException
	 */
	protected function funcIPInRange( $args, int $position ) {
		$ip = $args[0]->toString();
		$range = $args[1]->toString();

		if ( !IPUtils::isValidRange( $range ) && !IPUtils::isIPAddress( $range ) ) {
			throw new UserVisibleException(
				'invalidiprange',
				$position,
				[ $range ]
			);
		}

		$result = IPUtils::isInRange( $ip, $range );

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
		foreach ( $values as $needle ) {
			if ( $string->equals( $needle, true ) ) {
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
		// Special handling for when the offset is not contained in $haystack. PHP can emit a warning
		// or throw an error depending on the version (T285978). TODO Should we also throw?
		if ( $offset > mb_strlen( $haystack ) ) {
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
		$pattern = '#^' . strtr( preg_quote( $pattern->toString(), '#' ), AFPData::WILDCARD_MAP ) . '$#u';
		AtEase::suppressWarnings();
		$result = preg_match( $pattern, $str );
		AtEase::restoreWarnings();

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

		$pattern = $this->mungeRegexp( $pattern );

		if ( $insensitive ) {
			$pattern .= 'i';
		}

		AtEase::suppressWarnings();
		$this->checkRegexMatchesEmpty( $regex, $pattern, $pos );
		$result = preg_match( $pattern, $str );
		AtEase::restoreWarnings();
		if ( $result === false ) {
			throw new UserVisibleException(
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
	 * Given a node that we don't need to evaluate, decide what to do with it.
	 * The nodes passed in will usually be discarded by short-circuit
	 * evaluation. If we don't allow it, we fully evaluate the node.
	 *
	 * @param AFPTreeNode $node
	 */
	private function maybeDiscardNode( AFPTreeNode $node ) {
		if ( !$this->mAllowShort ) {
			$this->evalNode( $node );
		}
	}

	/**
	 * Given a regexp in the AF syntax, make it PCRE-compliant (i.e. we need to escape slashes, add
	 * delimiters and modifiers).
	 *
	 * @param string $rawRegexp
	 * @return string
	 */
	private function mungeRegexp( string $rawRegexp ): string {
		$needle = preg_replace( '!(\\\\\\\\)*(\\\\)?/!', '$1\/', $rawRegexp );
		return "/$needle/u";
	}

	/**
	 * Check whether the provided regex matches the empty string.
	 * @note This method can generate a PHP notice if the regex is invalid
	 *
	 * @param AFPData $regex TODO Can we avoid passing this in?
	 * @param string $pattern Already munged
	 * @param int $position
	 */
	protected function checkRegexMatchesEmpty( AFPData $regex, string $pattern, int $position ): void {
		if ( $regex->getType() === AFPData::DUNDEFINED ) {
			// We can't tell, and toString() would return the empty string (T273809)
			return;
		}
		// @phan-suppress-next-line PhanParamSuspiciousOrder
		if ( preg_match( $pattern, '' ) === 1 ) {
			$this->warnings[] = new UserVisibleWarning(
				'match-empty-regex',
				$position,
				[]
			);
		}
	}
}
