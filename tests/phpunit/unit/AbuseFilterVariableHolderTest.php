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
use MediaWiki\Extension\AbuseFilter\LazyVariableComputer;
use MediaWiki\Extension\AbuseFilter\Parser\AFPData;
use Psr\Log\NullLogger;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterParser
 */
class AbuseFilterVariableHolderTest extends MediaWikiUnitTestCase {
	/**
	 * Convenience wrapper
	 * @return AbuseFilterVariableHolder
	 */
	private function getVariableHolder() : AbuseFilterVariableHolder {
		$keywordsManager = new KeywordsManager( $this->createMock( AbuseFilterHookRunner::class ) );
		return new AbuseFilterVariableHolder( $keywordsManager );
	}

	/**
	 * @covers AbuseFilterVariableHolder::__construct
	 * @covers AbuseFilterVariableHolder::setLogger
	 */
	public function testLogger() {
		$vars = $this->getVariableHolder();
		$tw = TestingAccessWrapper::newFromObject( $vars );
		$this->assertInstanceOf( NullLogger::class, $tw->logger, 'Default logger is NullLogger' );

		$loggerClass = TestLogger::class;
		$logger = $this->createMock( $loggerClass );
		$tw->setLogger( $logger );
		$this->assertInstanceOf( $loggerClass, $tw->logger, 'Logger can be changed' );
	}

	/**
	 * @covers AbuseFilterVariableHolder::newFromArray
	 */
	public function testNewFromArray() {
		$vars = [
			'foo' => 12,
			'bar' => [ 'x', 'y' ],
			'baz' => false
		];
		$keywordsManager = new KeywordsManager( $this->createMock( AbuseFilterHookRunner::class ) );
		$actual = AbuseFilterVariableHolder::newFromArray( $vars, $keywordsManager );
		$expected = $this->getVariableHolder();
		foreach ( $vars as $var => $value ) {
			$expected->setVar( $var, $value );
		}

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * @covers AbuseFilterVariableHolder::setVar
	 */
	public function testVarsAreLowercased() {
		$vars = $this->getVariableHolder();
		$this->assertCount( 0, $vars->getVars(), 'precondition' );
		$vars->setVar( 'FOO', 42 );
		$this->assertCount( 1, $vars->getVars(), 'variable should be set' );
		$this->assertArrayHasKey( 'foo', $vars->getVars(), 'var should be lowercase' );
	}

	/**
	 * @covers AbuseFilterVariableHolder::translateDeprecatedVars
	 */
	public function testTranslateDeprecatedVars() {
		$varsMap = [
			'timestamp' => new AFPData( AFPData::DSTRING, '123' ),
			'added_lines' => new AFPData( AFPData::DSTRING, 'foobar' ),
			'article_text' => new AFPData( AFPData::DSTRING, 'FOO' ),
			'article_articleid' => new AFPData( AFPData::DINT, 42 )
		];
		$translatedVarsMap = [
			'timestamp' => $varsMap['timestamp'],
			'added_lines' => $varsMap['added_lines'],
			'page_title' => $varsMap['article_text'],
			'page_id' => $varsMap['article_articleid']
		];
		$keywordsManager = new KeywordsManager( $this->createMock( AbuseFilterHookRunner::class ) );
		$holder = AbuseFilterVariableHolder::newFromArray( $varsMap, $keywordsManager );
		$holder->translateDeprecatedVars();
		$this->assertEquals( $translatedVarsMap, $holder->getVars() );
	}

	/**
	 * @param string $name
	 * @param mixed $val
	 * @param mixed $expected
	 *
	 * @dataProvider provideSetVar
	 *
	 * @covers AbuseFilterVariableHolder::setVar
	 */
	public function testSetVar( $name, $val, $expected ) {
		$vars = $this->getVariableHolder();
		$vars->setVar( $name, $val );
		$this->assertEquals( $expected, $vars->getVars()[$name] );
	}

	public function provideSetVar() {
		yield 'native' => [ 'foo', 12, new AFPData( AFPData::DINT, 12 ) ];

		$afpdata = new AFPData( AFPData::DSTRING, 'foobar' );
		yield 'AFPData' => [ 'foo', $afpdata, $afpdata ];

		$afcompvar = new AFComputedVariable( 'foo', [] );
		yield 'AFComputedVariable' => [ 'foo', $afcompvar, $afcompvar ];
	}

	/**
	 * @covers AbuseFilterVariableHolder::getVars
	 */
	public function testGetVars() {
		$vars = $this->getVariableHolder();
		$this->assertSame( [], $vars->getVars(), 'precondition' );

		$vars->setVar( 'foo', [ true ] );
		$vars->setVar( 'bar', 'bar' );
		$exp = [
			'foo' => new AFPData( AFPData::DARRAY, [ new AFPData( AFPData::DBOOL, true ) ] ),
			'bar' => new AFPData( AFPData::DSTRING, 'bar' )
		];

		$this->assertEquals( $exp, $vars->getVars() );
	}

	/**
	 * @param AbuseFilterVariableHolder $vars
	 * @param string $name
	 * @param int $flags
	 * @param AFPData $expected
	 * @covers AbuseFilterVariableHolder::getVar
	 *
	 * @dataProvider provideGetVar
	 */
	public function testGetVar( AbuseFilterVariableHolder $vars, $name, $flags, AFPData $expected ) {
		$this->assertEquals( $expected, $vars->getVar( $name, $flags ) );
	}

	/**
	 * @return Generator|array
	 */
	public function provideGetVar() {
		$vars = $this->getVariableHolder();

		$name = 'foo';
		$expected = new AFPData( AFPData::DSTRING, 'foobarbaz' );
		$computer = $this->createMock( LazyVariableComputer::class );
		$computer->method( 'compute' )->willReturn( $expected );
		$afcv = new AFComputedVariable( '', [] );
		$vars->setLazyComputer( $computer );
		$vars->setVar( $name, $afcv );
		yield 'set, AFComputedVariable' => [ $vars, $name, 0, $expected ];

		$name = 'afpd';
		$afpd = new AFPData( AFPData::DINT, 42 );
		$vars->setVar( $name, $afpd );
		yield 'set, AFPData' => [ $vars, $name, 0, $afpd ];

		$name = 'not-set';
		$expected = new AFPData( AFPData::DUNDEFINED );
		yield 'unset, lax' => [ $vars, $name, AbuseFilterVariableHolder::GET_LAX, $expected ];
		// For now, strict is the same as lax.
		yield 'unset, strict' => [ $vars, $name, AbuseFilterVariableHolder::GET_STRICT, $expected ];
		yield 'unset, bc' => [ $vars, $name, AbuseFilterVariableHolder::GET_BC, new AFPData( AFPData::DNULL ) ];
	}

	/**
	 * @covers AbuseFilterVariableHolder::getVar
	 */
	public function testGetVarInvalidType() {
		$vars = $this->getVariableHolder();
		$tw = TestingAccessWrapper::newFromObject( $vars );
		$name = 'foo';
		$tw->mVars = [ $name => 'INVALID TYPE' ];

		$this->expectException( UnexpectedValueException::class );
		$tw->getVar( $name );
	}

	/**
	 * @param array $expected
	 * @param AbuseFilterVariableHolder ...$holders
	 * @dataProvider provideHoldersForAddition
	 *
	 * @covers AbuseFilterVariableHolder::addHolders
	 */
	public function testAddHolders( $expected, AbuseFilterVariableHolder ...$holders ) {
		$actual = $this->getVariableHolder();
		$actual->addHolders( ...$holders );

		$this->assertEquals( $expected, $actual->getVars() );
	}

	public function provideHoldersForAddition() {
		$keywordsManager = new KeywordsManager( $this->createMock( AbuseFilterHookRunner::class ) );
		$v1 = AbuseFilterVariableHolder::newFromArray( [ 'a' => 1, 'b' => 2 ], $keywordsManager );
		$v2 = AbuseFilterVariableHolder::newFromArray( [ 'b' => 3, 'c' => 4 ], $keywordsManager );
		$v3 = AbuseFilterVariableHolder::newFromArray( [ 'c' => 5, 'd' => 6 ], $keywordsManager );

		$expected = [
			'a' => new AFPData( AFPData::DINT, 1 ),
			'b' => new AFPData( AFPData::DINT, 3 ),
			'c' => new AFPData( AFPData::DINT, 5 ),
			'd' => new AFPData( AFPData::DINT, 6 )
		];

		return [ [ $expected, $v1, $v2, $v3 ] ];
	}

	/**
	 * @covers AbuseFilterVariableHolder::varIsSet
	 */
	public function testVarIsSet() {
		$vars = $this->getVariableHolder();
		$vars->setVar( 'foo', null );
		$this->assertTrue( $vars->varIsSet( 'foo' ), 'Set variable should be set' );
		$this->assertFalse( $vars->varIsSet( 'foobarbaz' ), 'Unset variable should be unset' );
	}

	/**
	 * @covers AbuseFilterVariableHolder::setLazyLoadVar
	 * @covers AbuseFilterVariableHolder::getLazyLoader
	 */
	public function testLazyLoader() {
		$var = 'foobar';
		$method = 'compute-foo';
		$params = [ 'baz', 1 ];
		$exp = new AFComputedVariable( $method, $params );

		$vars = $this->getVariableHolder();
		$vars->setLazyLoadVar( $var, $method, $params );
		$this->assertEquals( $exp, $vars->getVars()[$var] );
	}

	/**
	 * @covers AbuseFilterVariableHolder::exportAllVars
	 */
	public function testExportAllVars() {
		$pairs = [
			'foo' => 42,
			'bar' => [ 'bar', 'baz' ],
			'var' => false,
			'boo' => null
		];
		$keywordsManager = new KeywordsManager( $this->createMock( AbuseFilterHookRunner::class ) );
		$vars = AbuseFilterVariableHolder::newFromArray( $pairs, $keywordsManager );

		$this->assertSame( $pairs, $vars->exportAllVars() );
	}

	/**
	 * @covers AbuseFilterVariableHolder::exportNonLazyVars
	 */
	public function testExportNonLazyVars() {
		$keywordsManager = new KeywordsManager( $this->createMock( AbuseFilterHookRunner::class ) );
		$afcv = $this->createMock( AFComputedVariable::class );
		$pairs = [
			'lazy1' => $afcv,
			'lazy2' => $afcv,
			'native1' => 42,
			'native2' => 'foo',
			'native3' => null,
			'afpd' => new AFPData( AFPData::DSTRING, 'hey' ),
		];
		$vars = AbuseFilterVariableHolder::newFromArray( $pairs, $keywordsManager );

		$nonLazy = [
			'native1' => '42',
			'native2' => 'foo',
			'native3' => '',
			'afpd' => 'hey'
		];

		$this->assertSame( $nonLazy, $vars->exportNonLazyVars() );
	}

	/**
	 * @param AbuseFilterVariableHolder $vars
	 * @param array|bool $compute
	 * @param bool $includeUser
	 * @param array $expected
	 * @dataProvider provideDumpAllVars
	 *
	 * @covers AbuseFilterVariableHolder::dumpAllVars
	 */
	public function testDumpAllVars( $vars, $compute, $includeUser, $expected ) {
		$this->assertEquals( $expected, $vars->dumpAllVars( $compute, $includeUser ) );
	}

	/**
	 * @return Generator|array
	 */
	public function provideDumpAllVars() {
		$titleVal = 'title';
		$preftitle = new AFComputedVariable( 'preftitle', [] );

		$linesVal = 'lines';
		$lines = new AFComputedVariable( 'lines', [] );

		$computer = $this->createMock( LazyVariableComputer::class );
		$computer->method( 'compute' )->willReturnCallback(
			function ( AFComputedVariable $var ) use ( $titleVal, $linesVal ) {
				switch ( $var->mMethod ) {
					case 'preftitle':
						return new AFPData( AFPData::DSTRING, $titleVal );
					case 'lines':
						return new AFPData( AFPData::DSTRING, $linesVal );
					default:
						throw new LogicException( 'Unrecognized value!' );
				}
			}
		);

		$pairs = [
			'page_title' => 'foo',
			'page_prefixedtitle' => $preftitle,
			'added_lines' => $lines,
			'user_name' => 'bar',
			'custom-var' => 'foo'
		];
		$keywordsManager = new KeywordsManager( $this->createMock( AbuseFilterHookRunner::class ) );
		$vars = AbuseFilterVariableHolder::newFromArray( $pairs, $keywordsManager );
		$vars->setLazyComputer( $computer );

		$nonLazy = array_fill_keys( [ 'page_title', 'user_name', 'custom-var' ], 1 );
		$nonLazyExpect = array_intersect_key( $pairs, $nonLazy );
		yield 'lazy-loaded vars are excluded if not computed' => [
			clone $vars,
			[],
			true,
			$nonLazyExpect
		];

		$nonUserExpect = array_diff_key( $nonLazyExpect, [ 'custom-var' => 1 ] );
		yield 'user-set vars are excluded' => [ clone $vars, [], false, $nonUserExpect ];

		$allExpect = $pairs;
		$allExpect['page_prefixedtitle'] = $titleVal;
		$allExpect['added_lines'] = $linesVal;
		yield 'all vars computed' => [ clone $vars, true, true, $allExpect ];

		$titleOnlyComputed = array_merge( $nonLazyExpect, [ 'page_prefixedtitle' => $titleVal ] );
		yield 'Only a specific var computed' => [
			clone $vars,
			[ 'page_prefixedtitle' ],
			true,
			$titleOnlyComputed
		];
	}

	/**
	 * @covers AbuseFilterVariableHolder::computeDBVars
	 */
	public function testComputeDBVars() {
		$nonDBMet = [ 'unknown', 'certainly-not-db' ];
		$dbMet = [ 'page-age', 'simple-user-accessor', 'load-recent-authors' ];
		$methods = array_merge( $nonDBMet, $dbMet );
		$objs = [];
		foreach ( $methods as $method ) {
			$cur = new AFComputedVariable( $method, [] );
			$objs[ $method ] = $cur;
		}

		$keywordsManager = new KeywordsManager( $this->createMock( AbuseFilterHookRunner::class ) );
		$vars = AbuseFilterVariableHolder::newFromArray( $objs, $keywordsManager );
		$computer = $this->createMock( LazyVariableComputer::class );
		$computer->method( 'compute' )->willReturnCallback(
			function ( AFComputedVariable $var ) {
				return $var->mMethod;
			}
		);
		$vars->setLazyComputer( $computer );
		$vars->computeDBVars();

		$expAFCV = array_intersect_key( $vars->getVars(), array_fill_keys( $nonDBMet, 1 ) );
		$this->assertContainsOnlyInstancesOf(
			AFComputedVariable::class,
			$expAFCV,
			"non-DB methods shouldn't have been computed"
		);

		$expComputed = array_intersect_key( $vars->getVars(), array_fill_keys( $dbMet, 1 ) );
		$this->assertContainsOnlyInstancesOf(
			AFPData::class,
			$expComputed,
			'DB methods should have been computed'
		);
	}
}
