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

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use AbuseFilterVariableHolder;
use AFComputedVariable;
use Generator;
use MediaWiki\Extension\AbuseFilter\Parser\AFPData;
use MediaWiki\Extension\AbuseFilter\UnsetVariableException;
use MediaWikiUnitTestCase;

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterParser
 */
class VariableHolderTest extends MediaWikiUnitTestCase {
	/**
	 * @covers AbuseFilterVariableHolder::newFromArray
	 */
	public function testNewFromArray() {
		$vars = [
			'foo' => 12,
			'bar' => [ 'x', 'y' ],
			'baz' => false
		];
		$actual = AbuseFilterVariableHolder::newFromArray( $vars );
		$expected = new AbuseFilterVariableHolder();
		foreach ( $vars as $var => $value ) {
			$expected->setVar( $var, $value );
		}

		$this->assertEquals( $expected, $actual );
	}

	/**
	 * @covers AbuseFilterVariableHolder::setVar
	 */
	public function testVarsAreLowercased() {
		$vars = new AbuseFilterVariableHolder();
		$this->assertCount( 0, $vars->getVars(), 'precondition' );
		$vars->setVar( 'FOO', 42 );
		$this->assertCount( 1, $vars->getVars(), 'variable should be set' );
		$this->assertArrayHasKey( 'foo', $vars->getVars(), 'var should be lowercase' );
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
	public function testSetVar( string $name, $val, $expected ) {
		$vars = new AbuseFilterVariableHolder();
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
		$vars = new AbuseFilterVariableHolder();
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
	 * @param AFPData|AFComputedVariable $expected
	 * @covers AbuseFilterVariableHolder::getVarThrow
	 *
	 * @dataProvider provideGetVarThrow
	 */
	public function testGetVarThrow( AbuseFilterVariableHolder $vars, string $name, $expected ) {
		$this->assertEquals( $expected, $vars->getVarThrow( $name ) );
	}

	/**
	 * @return Generator|array
	 */
	public function provideGetVarThrow() {
		$vars = new AbuseFilterVariableHolder();

		$name = 'foo';
		$afcv = new AFComputedVariable( 'method', [ 'param' ] );
		$vars->setVar( $name, $afcv );
		yield 'set, AFComputedVariable' => [ $vars, $name, $afcv ];

		$name = 'afpd';
		$afpd = new AFPData( AFPData::DINT, 42 );
		$vars->setVar( $name, $afpd );
		yield 'set, AFPData' => [ $vars, $name, $afpd ];
	}

	/**
	 * @covers AbuseFilterVariableHolder::getVarThrow
	 */
	public function testGetVarThrow_unset() {
		$vars = new AbuseFilterVariableHolder();
		$this->expectException( UnsetVariableException::class );
		$vars->getVarThrow( 'unset-variable' );
	}

	/**
	 * @param array $expected
	 * @param AbuseFilterVariableHolder ...$holders
	 * @dataProvider provideHoldersForAddition
	 *
	 * @covers AbuseFilterVariableHolder::addHolders
	 */
	public function testAddHolders( array $expected, AbuseFilterVariableHolder ...$holders ) {
		$actual = new AbuseFilterVariableHolder();
		$actual->addHolders( ...$holders );

		$this->assertEquals( $expected, $actual->getVars() );
	}

	public function provideHoldersForAddition() {
		$v1 = AbuseFilterVariableHolder::newFromArray( [ 'a' => 1, 'b' => 2 ] );
		$v2 = AbuseFilterVariableHolder::newFromArray( [ 'b' => 3, 'c' => 4 ] );
		$v3 = AbuseFilterVariableHolder::newFromArray( [ 'c' => 5, 'd' => 6 ] );

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
		$vars = new AbuseFilterVariableHolder();
		$vars->setVar( 'foo', null );
		$this->assertTrue( $vars->varIsSet( 'foo' ), 'Set variable should be set' );
		$this->assertFalse( $vars->varIsSet( 'foobarbaz' ), 'Unset variable should be unset' );
	}

	/**
	 * @covers AbuseFilterVariableHolder::setLazyLoadVar
	 */
	public function testLazyLoader() {
		$var = 'foobar';
		$method = 'compute-foo';
		$params = [ 'baz', 1 ];
		$exp = new AFComputedVariable( $method, $params );

		$vars = new AbuseFilterVariableHolder();
		$vars->setLazyLoadVar( $var, $method, $params );
		$this->assertEquals( $exp, $vars->getVars()[$var] );
	}

	/**
	 * @covers AbuseFilterVariableHolder::removeVar
	 */
	public function testRemoveVar() {
		$vars = new AbuseFilterVariableHolder();
		$varName = 'foo';
		$vars->setVar( $varName, 'foobar' );
		$this->assertInstanceOf( AFPData::class, $vars->getVarThrow( $varName ) );
		$vars->removeVar( $varName );
		$this->expectException( UnsetVariableException::class );
		$vars->getVarThrow( $varName );
	}
}
