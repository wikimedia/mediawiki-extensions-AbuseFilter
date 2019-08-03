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

/**
 * @group Test
 * @group AbuseFilter
 * @group AbuseFilterParser
 *
 * @covers AFPData
 * @covers AbuseFilterTokenizer
 * @covers AFPToken
 * @covers AFPUserVisibleException
 * @covers AFPException
 * @covers AbuseFilterParser
 * @covers AbuseFilterCachingParser
 * @covers AFPTreeParser
 * @covers AFPTreeNode
 */
class AFPDataTest extends AbuseFilterParserTestCase {
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
			[ '1/0.0', 'mulRel' ],
		];
	}

	/**
	 * @param mixed $raw
	 * @param AFPData|null $expected If null, we expect an exception due to unsupported data type
	 * @covers AFPData::newFromPHPVar
	 * @dataProvider providePHPVars
	 */
	public function testNewFromPHPVar( $raw, $expected ) {
		if ( $expected === null ) {
			$this->setExpectedException( AFPException::class );
		}
		$this->assertEquals( $expected, AFPData::newFromPHPVar( $raw ) );
	}

	/**
	 * Data provider for testNewFromPHPVar
	 *
	 * @return array
	 */
	public function providePHPVars() {
		return [
			[ 15, new AFPData( AFPData::DINT, 15 ) ],
			[ '42', new AFPData( AFPData::DSTRING, '42' ) ],
			[ 0.123, new AFPData( AFPData::DFLOAT, 0.123 ) ],
			[ false, new AFPData( AFPData::DBOOL, false ) ],
			[ true, new AFPData( AFPData::DBOOL, true ) ],
			[ null, new AFPData( AFPData::DNULL ) ],
			[
				[ 1, 'foo', [], [ null ], false ],
				new AFPData( AFPData::DARRAY, [
					new AFPData( AFPData::DINT, 1 ),
					new AFPData( AFPData::DSTRING, 'foo' ),
					new AFPData( AFPData::DARRAY, [] ),
					new AFPData( AFPData::DARRAY, [ new AFPData( AFPData::DNULL ) ] ),
					new AFPData( AFPData::DBOOL, false )
				] )
			],
			// Invalid data types
			[ new stdClass, null ],
			[ new AFPData( AFPData::DUNDEFINED ), null ]
		];
	}

	/**
	 * Test casts to null and to arrays, for which we don't expose any method for use in actual
	 * filters. Other casts are already covered in parserTests.
	 *
	 * @param AFPData $orig
	 * @param string $newType One of the AFPData::D* constants
	 * @param AFPData|null $expected If null, we expect an exception due to unsupported data type
	 * @covers AFPData::castTypes
	 * @dataProvider provideMissingCastTypes
	 */
	public function testMissingCastTypes( $orig, $newType, $expected ) {
		if ( $expected === null ) {
			$this->setExpectedException( AFPException::class );
		}
		$this->assertEquals( $expected, AFPData::castTypes( $orig, $newType ) );
	}

	/**
	 * Data provider for testMissingCastTypes
	 *
	 * @return array
	 */
	public function provideMissingCastTypes() {
		return [
			[ new AFPData( AFPData::DINT, 1 ), AFPData::DNULL, new AFPData( AFPData::DNULL ) ],
			[ new AFPData( AFPData::DBOOL, false ), AFPData::DNULL, new AFPData( AFPData::DNULL ) ],
			[ new AFPData( AFPData::DSTRING, 'foo' ), AFPData::DNULL, new AFPData( AFPData::DNULL ) ],
			[ new AFPData( AFPData::DFLOAT, 3.14 ), AFPData::DNULL, new AFPData( AFPData::DNULL ) ],
			[
				new AFPData( AFPData::DARRAY, [
					new AFPData( AFPData::DSTRING, 'foo' ),
					new AFPData( AFPData::DNULL )
				] ),
				AFPData::DNULL,
				new AFPData( AFPData::DNULL )
			],
			[
				new AFPData( AFPData::DINT, 1 ),
				AFPData::DARRAY,
				new AFPData( AFPData::DARRAY, [ new AFPData( AFPData::DINT, 1 ) ] )
			],
			[
				new AFPData( AFPData::DBOOL, false ),
				AFPData::DARRAY,
				new AFPData( AFPData::DARRAY, [ new AFPData( AFPData::DBOOL, false ) ] )
			],
			[
				new AFPData( AFPData::DSTRING, 'foo' ),
				AFPData::DARRAY,
				new AFPData( AFPData::DARRAY, [ new AFPData( AFPData::DSTRING, 'foo' ) ] )
			],
			[
				new AFPData( AFPData::DFLOAT, 3.14 ),
				AFPData::DARRAY,
				new AFPData( AFPData::DARRAY, [ new AFPData( AFPData::DFLOAT, 3.14 ) ] )
			],
			[
				new AFPData( AFPData::DNULL ),
				AFPData::DARRAY,
				new AFPData( AFPData::DARRAY, [ new AFPData( AFPData::DNULL ) ] )
			],
			[ new AFPData( AFPData::DSTRING, 'foo' ), 'foobaz', null ],
			[ new AFPData( AFPData::DNULL ), null, null ]
		];
	}

	/**
	 * Test a couple of toNative cases which aren't already covered in other tests.
	 *
	 * @param AFPData $orig
	 * @param mixed $expected
	 * @covers AFPData::toNative
	 * @dataProvider provideMissingToNative
	 */
	public function testMissingToNative( $orig, $expected ) {
		$this->assertEquals( $expected, $orig->toNative() );
	}

	/**
	 * Data provider for testMissingToNative
	 *
	 * @return array
	 */
	public function provideMissingToNative() {
		return [
			[ new AFPData( AFPData::DFLOAT, 1.2345 ), 1.2345 ],
			[ new AFPData( AFPData::DFLOAT, 0.1 ), 0.1 ],
			[ new AFPData( AFPData::DUNDEFINED ), null ],
			[ new AFPData( AFPData::DNULL, null ), null ],
		];
	}

	/**
	 * Ensure that we don't allow DUNDEFINED in AFPData::equals
	 *
	 * @param AFPData $lhs
	 * @param AFPData $lhs
	 * @dataProvider provideDUNDEFINEDEquals
	 */
	public function testNoDUNDEFINEDEquals( $lhs, $rhs ) {
		$this->expectException( AFPException::class );
		AFPData::equals( $lhs, $rhs );
	}

	/**
	 * Data provider for testNoDUNDEFINEDEquals
	 *
	 * @return array
	 */
	public function provideDUNDEFINEDEquals() {
		$undefined = new AFPData( AFPData::DUNDEFINED );
		$nonempty = new AFPData( AFPData::DSTRING, 'foo' );
		return [
			'left' => [ $undefined, $nonempty ],
			'right' => [ $nonempty, $undefined ],
			'both' => [ $undefined, $undefined ]
		];
	}

	/**
	 * Test that DUNDEFINED can only have null value
	 */
	public function testDUNDEFINEDRequiresNullValue() {
		$this->expectException( InvalidArgumentException::class );
		new AFPData( AFPData::DUNDEFINED, 'non-null' );
	}
}
