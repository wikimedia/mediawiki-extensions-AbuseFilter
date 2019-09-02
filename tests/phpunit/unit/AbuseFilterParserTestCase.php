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
 */

use PHPUnit\Framework\MockObject\MockObject;

/**
 * Helper for parser-related tests
 */
abstract class AbuseFilterParserTestCase extends MediaWikiUnitTestCase {
	/**
	 * @return AbuseFilterParser[]
	 */
	protected function getParsers() {
		// We're not interested in caching or logging; tests should call respectively setCache
		// and setLogger if they want to test any of those.
		$contLang = $this->getLanguageMock();
		$cache = new EmptyBagOStuff();
		$logger = new \Psr\Log\NullLogger();

		$parser = new AbuseFilterParser( $contLang, $cache, $logger );
		$parser->toggleConditionLimit( false );
		$cachingParser = new AbuseFilterCachingParser( $contLang, $cache, $logger );
		$cachingParser->toggleConditionLimit( false );
		return [ $parser, $cachingParser ];
	}

	/**
	 * Base method for testing exceptions
	 *
	 * @param string $excep Identifier of the exception (e.g. 'unexpectedtoken')
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown, if available
	 *  The method may be different in Parser and CachingParser, but this parameter is
	 *  just used for debugging purposes.
	 */
	protected function exceptionTest( $excep, $expr, $caller ) {
		foreach ( $this->getParsers() as $parser ) {
			$pname = get_class( $parser );
			try {
				$parser->parse( $expr );
			} catch ( AFPUserVisibleException $e ) {
				$this->assertEquals(
					$excep,
					$e->mExceptionID,
					"Exception $excep not thrown in $caller. Parser: $pname."
				);
				continue;
			}

			$this->fail( "Exception $excep not thrown in $caller. Parser: $pname." );
		}
	}

	/**
	 * Get a mock of LanguageEn with only the methods we need in the parser
	 *
	 * @return Language|MockObject
	 */
	protected function getLanguageMock() {
		$lang = $this->getMockBuilder( LanguageEn::class )
			->disableOriginalConstructor()
			->getMock();
		$lang->expects( $this->any() )
			->method( 'uc' )
			->willReturnCallback( function ( $x ) {
				return mb_strtoupper( $x );
			} );
		$lang->expects( $this->any() )
			->method( 'lc' )
			->willReturnCallback( function ( $x ) {
				return mb_strtolower( $x );
			} );
		return $lang;
	}
}
