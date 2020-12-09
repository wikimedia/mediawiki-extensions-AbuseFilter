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

use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterCachingParser;
use MediaWiki\Extension\AbuseFilter\Parser\AbuseFilterParser;
use MediaWiki\Extension\AbuseFilter\Parser\AFPUserVisibleException;
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
		$keywordsManager = new KeywordsManager( $this->createMock( AbuseFilterHookRunner::class ) );

		$parser = new AbuseFilterParser( $contLang, $cache, $logger, $keywordsManager );
		$parser->toggleConditionLimit( false );
		$cachingParser = new AbuseFilterCachingParser( $contLang, $cache, $logger, $keywordsManager );
		$cachingParser->toggleConditionLimit( false );
		return [ $parser, $cachingParser ];
	}

	/**
	 * @param string $excep
	 * @param string $expr
	 * @param string $caller
	 * @param bool $skippedBlock Whether we're testing code inside a short-circuited block
	 */
	private function exceptionTestInternal( $excep, $expr, $caller, $skippedBlock ) {
		foreach ( $this->getParsers() as $parser ) {
			$pname = get_class( $parser );
			$msg = "Exception $excep not thrown in $caller";
			if ( $skippedBlock ) {
				$msg .= " inside a short-circuited block";
			}
			$msg .= ". Parser: $pname.";
			try {
				if ( $skippedBlock ) {
					// Skipped blocks are, well, skipped when actually parsing.
					$parser->checkSyntaxThrow( $expr );
				} else {
					$parser->parse( $expr );
				}
			} catch ( AFPUserVisibleException $e ) {
				$this->assertEquals( $excep, $e->mExceptionID, $msg . " Got instead:\n$e" );
				continue;
			}

			$this->fail( $msg );
		}
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
		$this->exceptionTestInternal( $excep, $expr, $caller, false );
	}

	/**
	 * Same as self::exceptionTest, but wraps the given code in a block that will be short-circuited.
	 * Note that this is executed using Parser::checkSyntax, as errors inside a skipped branch won't
	 * ever be reported at runtime.
	 *
	 * @param string $excep
	 * @param string $expr
	 * @param string $caller
	 */
	protected function exceptionTestInSkippedBlock( $excep, $expr, $caller ) {
		$expr = "false & ( $expr )";
		$this->exceptionTestInternal( $excep, $expr, $caller, true );
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
