<?php
/**
 * Tests for the AFPData class
 *
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
 */

/**
 * @group Test
 * @group AbuseFilter
 *
 * @covers AFPData
 * @covers AbuseFilterTokenizer
 * @covers AFPToken
 * @covers AFPUserVisibleException
 * @covers AFPException
 * @covers AbuseFilterParser
 */
class AFPDataTest extends MediaWikiTestCase {
	/**
	 * @return AbuseFilterParser
	 */
	public static function getParser() {
		static $parser = null;
		if ( !$parser ) {
			$parser = new AbuseFilterParser();
		} else {
			$parser->resetState();
		}
		return $parser;
	}

	/**
	 * Base method for testing exceptions
	 *
	 * @param string $excep Identifier of the exception (e.g. 'unexpectedtoken')
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 */
	private function exceptionTest( $excep, $expr, $caller ) {
		$parser = self::getParser();
		try {
			$parser->parse( $expr );
		} catch ( AFPUserVisibleException $e ) {
			$this->assertEquals(
				$excep,
				$e->mExceptionID,
				"Exception $excep not thrown in AFPData::$caller"
			);
			return;
		}

		$this->fail( "Exception $excep not thrown in AFPData::$caller" );
	}

	/**
	 * Test the 'regexfailure' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AFPData::keywordRegex
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
			[ "'a' rlike '('", 'keywordRegex' ],
		];
	}

	/**
	 * Test the 'dividebyzero' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AFPData::mulRel
	 * @dataProvider divideByZero
	 */
	public function testDivideByZeroException( $expr, $caller ) {
		$this->exceptionTest( 'dividebyzero', $expr, $caller );
	}

	/**
	 * Data provider for testRegexFailureException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function divideByZero() {
		return [
			[ '1/0', 'mulRel' ],
		];
	}
}
