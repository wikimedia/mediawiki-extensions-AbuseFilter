<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 *
 * @license GPL-2.0-or-later
 * @author Marius Hoch < hoo@online.de >
 */

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterParser
 *
 * @covers AbuseFilterCachingParser
 * @covers AFPTreeParser
 * @covers AFPTreeNode
 * @covers AFPParserState
 * @covers AbuseFilterParser
 * @covers AbuseFilterTokenizer
 * @covers AFPToken
 * @covers AFPUserVisibleException
 * @covers AFPException
 * @covers AFPData
 * @covers AbuseFilterVariableHolder
 * @covers AFComputedVariable
 */
class AbuseFilterParserTest extends AbuseFilterParserTestCase {
	/**
	 * @param string $rule The rule to parse
	 * @dataProvider readTests
	 */
	public function testParser( $rule ) {
		foreach ( self::getParsers() as $parser ) {
			$this->assertTrue( $parser->parse( $rule ), 'Parser used: ' . get_class( $parser ) );
		}
	}

	/**
	 * @return Generator|array
	 */
	public function readTests() {
		$testPath = __DIR__ . "/../parserTests";
		$testFiles = glob( $testPath . "/*.t" );

		foreach ( $testFiles as $testFile ) {
			$testName = basename( substr( $testFile, 0, -2 ) );
			$rule = trim( file_get_contents( $testFile ) );

			yield $testName => [ $rule ];
		}
	}

	/**
	 * Test expression evaluation
	 *
	 * @param string $expr The expression to evaluate
	 * @param string $expected The expected result
	 * @dataProvider provideExpressions
	 */
	public function testEvaluateExpression( $expr, $expected ) {
		foreach ( self::getParsers() as $parser ) {
			$actual = $parser->evaluateExpression( $expr );
			$this->assertEquals( $expected, $actual );
		}
	}

	/**
	 * Data provider for testEvaluateExpression
	 *
	 * @return array
	 */
	public function provideExpressions() {
		return [
			[ '1 === 1', true ],
			[ 'rescape( "abc* (def)" )', 'abc\* \(def\)' ],
			[ 'str_replace( "foobarbaz", "bar", "-" )', 'foo-baz' ],
			[ 'rmdoubles( "foobybboo" )', 'fobybo' ],
			[ 'lcase("FÁmí")', 'fámí' ],
			[ 'substr( "foobar", 0, 3 )', 'foo' ]
		];
	}

	/**
	 * Test empty (or almost empty) syntax and ensure it doesn't match
	 *
	 * @param string $code
	 * @dataProvider provideEmptySyntax
	 */
	public function testEmptySyntax( $code ) {
		foreach ( self::getParsers() as $parser ) {
			$this->assertFalse( $parser->parse( $code ) );
		}
	}

	/**
	 * Data provider for testEmptySyntax
	 *
	 * @return array
	 */
	public function provideEmptySyntax() {
		return [
			[ '' ],
			[ '()' ],
			[ ';;;;' ]
		];
	}

	/**
	 * Ensure that AbuseFilterTokenizer::OPERATOR_RE matches the contents
	 * and order of AbuseFilterTokenizer::$operators.
	 */
	public function testOperatorRe() {
		$quotedOps = array_map(
			function ( $op ) {
				return preg_quote( $op, '/' );
			},
			AbuseFilterTokenizer::$operators
		);
		$operatorRe = '/(' . implode( '|', $quotedOps ) . ')/A';
		$this->assertEquals( $operatorRe, AbuseFilterTokenizer::OPERATOR_RE );
	}

	/**
	 * Ensure that AbuseFilterTokenizer::RADIX_RE matches the contents
	 * and order of AbuseFilterTokenizer::$bases.
	 */
	public function testRadixRe() {
		$baseClass = implode( '', array_keys( AbuseFilterTokenizer::$bases ) );
		$radixRe = "/([0-9A-Fa-f]+(?:\.\d*)?|\.\d+)([$baseClass])?(?![a-z])/Au";
		$this->assertEquals( $radixRe, AbuseFilterTokenizer::RADIX_RE );
	}

	/**
	 * Ensure the number of conditions counted for given expressions is right.
	 *
	 * @param string $rule The rule to parse
	 * @param int $expected The expected amount of used conditions
	 * @dataProvider condCountCases
	 */
	public function testCondCount( $rule, $expected ) {
		foreach ( self::getParsers() as $parser ) {
			$parserClass = get_class( $parser );
			$countBefore = $parser->getCondCount();
			$parser->parse( $rule );
			$countAfter = $parser->getCondCount();
			$actual = $countAfter - $countBefore;
			$this->assertEquals( $expected, $actual, "Wrong condition count for $rule with $parserClass" );
			// Reset cache or it would compromise conditions count
			$parser::$funcCache = [];
		}
	}

	/**
	 * Data provider for testCondCount method.
	 * @return array
	 */
	public function condCountCases() {
		return [
			[ '((("a" == "b")))', 1 ],
			[ 'contains_any("a", "b", "c")', 1 ],
			[ '"a" == "b" & "b" == "c"', 1 ],
			[ '"a" == "b" | "b" == "c"', 2 ],
			[ '"a" in "b" + "c" in "d" + "e" in "f"', 3 ],
			[ 'true', 0 ],
			[ '"a" == "a" | "c" == "d"', 1 ],
			[ '"a" == "b" & "c" == "d"', 1 ],
			[ '1 = 0 & 2 * 3 * 4 <= 560 & "a" = "b"', 1 ],
			[ '1 = 1 & 2 * 3 * 4 <= 560 & "a" = "b"', 3 ],
			[ '1 = 1 | 2 * 3 * 4 <= 560 | "a" = "b"', 1 ],
			[ '1 = 0 | 2 * 3 * 4 <= 560 | "a" = "b"', 2 ],
		];
	}

	/**
	 * Test for T204841
	 */
	public function testArrayShortcircuit() {
		$code = 'a := [false, false]; b := [false, false]; c := 42; d := [0,1];' .
			'a[0] != false & b[1] != false & (b[5**2/(5*(4+1))] !== a[43-c] | a[d[0]] === b[d[c-41]])';
		foreach ( self::getParsers() as $parser ) {
			$this->assertFalse( $parser->parse( $code ), 'Parser: ' . get_class( $parser ) );
		}
	}

	/**
	 * Test the 'expectednotfound' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @dataProvider expectedNotFound
	 */
	public function testExpectedNotFoundException( $expr, $caller ) {
		$this->exceptionTest( 'expectednotfound', $expr, $caller );
	}

	/**
	 * Data provider for testExpectedNotFoundException.
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function expectedNotFound() {
		return [
			[ 'a:= [1,2,3]; a[1 = 4', 'doLevelSet' ],
			[ "if 1 = 1 'foo'", 'doLevelConditions' ],
			[ "if 1 = 1 then 'foo'", 'doLevelConditions' ],
			[ "if 1 = 1 then 'foo' else 'bar'", 'doLevelConditions' ],
			[ "a := 1 = 1 ? 'foo'", 'doLevelConditions' ],
			[ '(1 = 1', 'doLevelBraces' ],
			[ 'lcase = 3', 'doLevelFunction' ],
			[ 'lcase( 3 = 1', 'doLevelFunction' ],
			[ 'a := [1,2', 'doLevelAtom' ],
			[ '1 = 1 | (', 'skipOverBraces' ],
			[ 'a := [1,2,3]; 3 = a[5', 'doLevelArrayElements' ],
		];
	}

	/**
	 * Test the 'unexpectedatend' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @dataProvider unexpectedAtEnd
	 */
	public function testUnexpectedAtEndException( $expr, $caller ) {
		$this->exceptionTest( 'unexpectedatend', $expr, $caller );
	}

	/**
	 * Data provider for testUnexpectedAtEndException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function unexpectedAtEnd() {
		return [
			[ "'a' = 1 )", 'doLevelEntry' ],
		];
	}

	/**
	 * Test the 'unrecognisedvar' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @dataProvider unrecognisedVar
	 */
	public function testUnrecognisedVarException( $expr, $caller ) {
		$this->exceptionTest( 'unrecognisedvar', $expr, $caller );
	}

	/**
	 * Data provider for testUnrecognisedVarException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function unrecognisedVar() {
		return [
			[ 'a[1] := 5', 'doLevelSet' ],
			[ 'a = 5', 'getVarValue' ],
		];
	}

	/**
	 * Test the 'notarray' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @dataProvider notArray
	 */
	public function testNotArrayException( $expr, $caller ) {
		$this->exceptionTest( 'notarray', $expr, $caller );
	}

	/**
	 * Data provider for testNotArrayException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function notArray() {
		return [
			[ 'a := 5; a[1] = 5', 'doLevelSet' ],
			[ 'a := 1; 3 = a[5]', 'doLevelArrayElements' ],
			[ 'a := 2; a[] := 2', '[different callers]' ],
			[ 'a := 3; a[3] := 5', '[different callers]' ]
		];
	}

	/**
	 * Test the 'outofbounds' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @dataProvider outOfBounds
	 */
	public function testOutOfBoundsException( $expr, $caller ) {
		$this->exceptionTest( 'outofbounds', $expr, $caller );
	}

	/**
	 * Data provider for testOutOfBoundsException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function outOfBounds() {
		return [
			[ 'a := [2]; a[5] = 9', 'doLevelSet' ],
			[ 'a := [1,2,3]; 3 = a[5]', 'doLevelArrayElements' ],
			[ 'a := [1]; a[15] := 5', '[different callers]' ]
		];
	}

	/**
	 * Test the 'unrecognisedkeyword' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @dataProvider unrecognisedKeyword
	 */
	public function testUnrecognisedKeywordException( $expr, $caller ) {
		$this->exceptionTest( 'unrecognisedkeyword', $expr, $caller );
	}

	/**
	 * Data provider for testUnrecognisedKeywordException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function unrecognisedKeyword() {
		return [
			[ '5 = rlike', 'doLevelAtom' ],
		];
	}

	/**
	 * Test the 'unexpectedtoken' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @dataProvider unexpectedToken
	 */
	public function testUnexpectedTokenException( $expr, $caller ) {
		$this->exceptionTest( 'unexpectedtoken', $expr, $caller );
	}

	/**
	 * Data provider for testUnexpectedTokenException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function unexpectedToken() {
		return [
			[ '1 =? 1', 'doLevelAtom' ],
		];
	}

	/**
	 * Test the 'disabledvar' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @dataProvider disabledVar
	 */
	public function testDisabledVarException( $expr, $caller ) {
		$this->exceptionTest( 'disabledvar', $expr, $caller );
	}

	/**
	 * Data provider for testDisabledVarException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function disabledVar() {
		return [
			[ 'old_text = 1', 'getVarValue' ],
		];
	}

	/**
	 * Test the 'overridebuiltin' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @dataProvider overrideBuiltin
	 */
	public function testOverrideBuiltinException( $expr, $caller ) {
		$this->exceptionTest( 'overridebuiltin', $expr, $caller );
	}

	/**
	 * Data provider for testOverrideBuiltinException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function overrideBuiltin() {
		return [
			[ 'added_lines := 1', 'setUserVariable' ],
		];
	}

	/**
	 * Test the 'regexfailure' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @dataProvider regexFailure
	 */
	public function testRegexFailureException( $expr, $caller ) {
		$this->exceptionTest( 'regexfailure', $expr, $caller );
	}

	/**
	 * Data provider for testRegexFailureException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function regexFailure() {
		return [
			[ "rcount('(','a')", 'funcRCount' ],
			[ "get_matches('this (should fail', 'any haystack')", 'funcGetMatches' ],
		];
	}

	/**
	 * Test the 'invalidiprange' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @dataProvider invalidIPRange
	 */
	public function testInvalidIPRangeException( $expr, $caller ) {
		$this->exceptionTest( 'invalidiprange', $expr, $caller );
	}

	/**
	 * Data provider for testInvalidIPRangeException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function invalidIPRange() {
		return [
			[ "ip_in_range('0.0.0.0', 'lol')", 'funcIPInRange' ],
		];
	}

	/**
	 * Test functions which take exactly one parameters calling them
	 *   without 0 params. They should throw a 'noparams' exception.
	 *
	 * @param string $func The function to test
	 * @dataProvider oneParamFuncs
	 */
	public function testNoParamsException( $func ) {
		foreach ( self::getParsers() as $parser ) {
			$this->setExpectedException(
				AFPUserVisibleException::class,
				'No parameters given to function'
			);
			$parser->parse( "$func()" );
		}
	}

	/**
	 * Data provider for testNoParamsException, returns a list of
	 * functions taking a single parameter
	 *
	 * @return array
	 */
	public function oneParamFuncs() {
		return [
			[ 'lcase' ],
			[ 'ucase' ],
			[ 'length' ],
			[ 'strlen' ],
			[ 'specialratio' ],
			[ 'count' ],
			[ 'rcount' ],
			[ 'ccnorm' ],
			[ 'sanitize' ],
			[ 'rmspecials' ],
			[ 'rmwhitespace' ],
			[ 'rmdoubles' ],
			[ 'norm' ],
			[ 'rescape' ],
			[ 'string' ],
			[ 'int' ],
			[ 'float' ],
			[ 'bool' ],
		];
	}

	/**
	 * Test functions taking two parameters by providing only one.
	 *   They should throw a 'notenoughargs' exception.
	 *
	 * @param string $func The function to test
	 * @dataProvider twoParamsFuncs
	 */
	public function testNotEnoughArgsExceptionTwo( $func ) {
		foreach ( self::getParsers() as $parser ) {
			// Nevermind if the argument can't be string since we check the amount
			// of parameters before anything else.
			$code = "$func('foo')";
			$length = strlen( $code );
			$this->setExpectedException(
				AFPUserVisibleException::class,
				"Not enough arguments to function $func called at character $length.\n" .
				'Expected 2 arguments, got 1'
			);
			$parser->parse( $code );
		}
	}

	/**
	 * Data provider for testNotEnoughArgsExceptionTwo, returns the list of
	 * functions taking two parameters.
	 *
	 * @return array
	 */
	public function twoParamsFuncs() {
		return [
			[ 'get_matches' ],
			[ 'ip_in_range' ],
			[ 'contains_any' ],
			[ 'contains_all' ],
			[ 'ccnorm_contains_any' ],
			[ 'ccnorm_contains_all' ],
			[ 'equals_to_any' ],
			[ 'substr' ],
			[ 'strpos' ],
			[ 'set_var' ],
		];
	}

	/**
	 * Test functions taking three parameters by providing only two.
	 *   They should throw a 'notenoughargs' exception.
	 *
	 * @param string $func The function to test
	 * @dataProvider threeParamsFuncs
	 */
	public function testNotEnoughArgsExceptionThree( $func ) {
		foreach ( self::getParsers() as $parser ) {
			$this->setExpectedException(
				AFPUserVisibleException::class,
				"Not enough arguments to function $func called at character 25.\n" .
				'Expected 3 arguments, got 2'
			);
			// Nevermind if the argument can't be string since we check the amount
			// of parameters before anything else.
			$parser->parse( "$func('foo', 'bar')" );
		}
	}

	/**
	 * Data provider for testNotEnoughArgsExceptionThree, returns the list of
	 * functions taking three parameters.
	 *
	 * @return array
	 */
	public function threeParamsFuncs() {
		return [
			[ 'str_replace' ],
		];
	}

	/**
	 * Check that deprecated variables are correctly translated to the new ones with a debug notice
	 *
	 * @param string $old The old name of the variable
	 * @param string $new The new name of the variable
	 * @dataProvider provideDeprecatedVars
	 */
	public function testDeprecatedVars( $old, $new ) {
		$loggerMock = new TestLogger();
		$loggerMock->setCollect( true );
		$this->setLogger( 'AbuseFilter', $loggerMock );

		foreach ( self::getParsers() as $parser ) {
			$pname = get_class( $parser );
			$actual = $parser->parse( "$old === $new" );

			$loggerBuffer = $loggerMock->getBuffer();
			// Check that the use has been logged
			$found = false;
			foreach ( $loggerBuffer as $entry ) {
				$check = preg_match( '/AbuseFilter: deprecated variable/', $entry[1] );
				if ( $check ) {
					$found = true;
					break;
				}
			}
			if ( !$found ) {
				$this->fail( "The use of the deprecated variable $old was not logged. Parser: $pname" );
			}

			$this->assertTrue( $actual, "Parser: $pname" );
		}
	}

	/**
	 * Data provider for testDeprecatedVars
	 * @return Generator|array
	 */
	public function provideDeprecatedVars() {
		$deprecated = AbuseFilter::$deprecatedVars;
		foreach ( $deprecated as $old => $new ) {
			yield $old => [ $old, $new ];
		}
	}

	/**
	 * Ensure that things like `'a' === 'b' === 'c'` or `1 < 2 < 3` are rejected, while `1 < 2 == 3`
	 * and `1 == 2 < 3` are not. (T218906)
	 * @param string $code Code to parse
	 * @param bool $valid Whether $code is valid (or should throw an exception)
	 * @dataProvider provideConsecutiveComparisons
	 */
	public function testDisallowConsecutiveComparisons( $code, $valid ) {
		foreach ( self::getParsers() as $parser ) {
			$pname = get_class( $parser );
			$actuallyValid = true;
			try {
				$parser->parse( $code );
			} catch ( AFPUserVisibleException $e ) {
				$actuallyValid = false;
			}

			$this->assertSame(
				$valid,
				$actuallyValid,
				'The code should' . ( $valid ? ' ' : ' NOT ' ) . "be parsed correctly. Parser: $pname"
			);
		}
	}

	/**
	 * Data provider for testDisallowConsecutiveComparisons
	 *
	 * @return Generator
	 */
	public function provideConsecutiveComparisons() {
		// Same as AbuseFilterParser::doLevelCompares
		$eqOps = [ '==', '===', '!=', '!==', '=' ];
		$ordOps = [ '<', '>', '<=', '>=' ];
		$ops = array_merge( $eqOps, $ordOps );
		foreach ( $ops as $op1 ) {
			foreach ( $ops as $op2 ) {
				$testStr = "1 $op1 3.14 $op2 -1";
				$valid = ( in_array( $op1, $eqOps ) && in_array( $op2, $ordOps ) ) ||
					( in_array( $op1, $ordOps ) && in_array( $op2, $eqOps ) );
				yield $testStr => [ $testStr, $valid ];
			}
		}
		// Some more cases with more than 2 comparisons
		$extra = [
			'1 === 1 < 3 === 0',
			'1 === 1 < 3 === 0 < 555',
			'1 < 3 === 0 < 555',
			'1 < 3 === 0 < 555 !== 444',
			'1 != 0 < 3 == 1 > 0 != 0'
		];
		foreach ( $extra as $case ) {
			yield $case => [ $case, false ];
		}
	}
}
