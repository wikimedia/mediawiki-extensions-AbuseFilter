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
 * @covers AbuseFilterTokenizer
 * @covers AFPToken
 * @covers AbuseFilterParser
 * @covers AFPUserVisibleException
 * @covers AFPException
 */
class AbuseFilterTokenizerTest extends MediaWikiTestCase {
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
				"Exception $excep not thrown in AbuseFilterTokenizer::$caller"
			);
			return;
		}

		$this->fail( "Exception $excep not thrown in AbuseFilterTokenizer::$caller" );
	}

	/**
	 * Test the 'unclosedcomment' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterTokenizer::nextToken
	 * @dataProvider unclosedComment
	 */
	public function testUnclosedCommentException( $expr, $caller ) {
		$this->exceptionTest( 'unclosedcomment', $expr, $caller );
	}

	/**
	 * Data provider for testUnclosedCommentException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function unclosedComment() {
		return [
			[ '     /****    /  *  /', 'nextToken' ],
		];
	}

	/**
	 * Test the 'unrecognisedtoken' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterTokenizer::nextToken
	 * @dataProvider unrecognisedToken
	 */
	public function testUnrecognisedTokenException( $expr, $caller ) {
		$this->exceptionTest( 'unrecognisedtoken', $expr, $caller );
	}

	/**
	 * Data provider for testUnrecognisedTokenException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function unrecognisedToken() {
		return [
			[ '#', 'nextToken' ],
		];
	}

	/**
	 * Test the 'unclosedstring' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterTokenizer::readStringLiteral
	 * @dataProvider unclosedString
	 */
	public function testUnclosedStringException( $expr, $caller ) {
		$this->exceptionTest( 'unclosedstring', $expr, $caller );
	}

	/**
	 * Data provider for testUnclosedStringException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function unclosedString() {
		return [
			[ '"', 'readStringLiteral' ],
		];
	}
}
