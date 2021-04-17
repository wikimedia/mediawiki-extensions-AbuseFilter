<?php

namespace MediaWiki\Extension\AbuseFilter\Parser;

use InvalidArgumentException;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;

/**
 * @todo Remove this class
 * @internal This is a temporary class until things are settled down
 * @property KeywordsManager $keywordsManager
 */
abstract class AFPTransitionBase {
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

	/**
	 * @var int The position of the current token
	 */
	protected $mPos;

	/**
	 * Check that a built-in function has been provided the right amount of arguments
	 *
	 * @param array $args The arguments supplied to the function
	 * @param string $func The function name
	 * @throws AFPUserVisibleException
	 */
	protected function checkArgCount( $args, $func ) {
		if ( !array_key_exists( $func, self::FUNC_ARG_COUNT ) ) {
			// @codeCoverageIgnoreStart
			throw new InvalidArgumentException( "$func is not a valid function." );
			// @codeCoverageIgnoreEnd
		}
		list( $min, $max ) = self::FUNC_ARG_COUNT[ $func ];
		if ( count( $args ) < $min ) {
			throw new AFPUserVisibleException(
				$min === 1 ? 'noparams' : 'notenoughargs',
				$this->mPos,
				[ $func, $min, count( $args ) ]
			);
		} elseif ( count( $args ) > $max ) {
			throw new AFPUserVisibleException(
				'toomanyargs',
				$this->mPos,
				[ $func, $max, count( $args ) ]
			);
		}
	}

	/**
	 * Check whether the given name is a reserved identifier, e.g. the name of a built-in variable,
	 * function, or keyword.
	 *
	 * @param string $name
	 * @return bool
	 */
	protected function isReservedIdentifier( $name ) {
		return $this->keywordsManager->varExists( $name ) ||
			array_key_exists( $name, self::FUNCTIONS ) ||
			// We need to check for true, false, if/then/else etc. because, even if they have a different
			// AFPToken type, they may be used inside set/set_var()
			in_array( $name, AbuseFilterTokenizer::KEYWORDS, true );
	}

	/**
	 * @param string $fname
	 * @return bool
	 */
	protected function functionIsVariadic( $fname ) {
		if ( !array_key_exists( $fname, self::FUNC_ARG_COUNT ) ) {
			// @codeCoverageIgnoreStart
			throw new InvalidArgumentException( "Function $fname is not valid" );
			// @codeCoverageIgnoreEnd
		}
		return self::FUNC_ARG_COUNT[$fname][1] === INF;
	}
}
