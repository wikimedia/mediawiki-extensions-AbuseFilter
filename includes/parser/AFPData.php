<?php

class AFPData {
	// Datatypes
	const DINT = 'int';
	const DSTRING = 'string';
	const DNULL = 'null';
	const DBOOL = 'bool';
	const DFLOAT = 'float';
	const DARRAY = 'array';

	// Translation table mapping shell-style wildcards to PCRE equivalents.
	// Derived from <http://www.php.net/manual/en/function.fnmatch.php#100207>
	private static $wildcardMap = [
		'\*' => '.*',
		'\+' => '\+',
		'\-' => '\-',
		'\.' => '\.',
		'\?' => '.',
		'\[' => '[',
		'\[\!' => '[^',
		'\\' => '\\\\',
		'\]' => ']',
	];

	public $type;
	public $data;

	/**
	 * @param string $type
	 * @param mixed|null $val
	 */
	public function __construct( $type = self::DNULL, $val = null ) {
		$this->type = $type;
		$this->data = $val;
	}

	/**
	 * @param mixed $var
	 * @return AFPData
	 * @throws AFPException
	 */
	public static function newFromPHPVar( $var ) {
		if ( is_string( $var ) ) {
			return new AFPData( self::DSTRING, $var );
		} elseif ( is_int( $var ) ) {
			return new AFPData( self::DINT, $var );
		} elseif ( is_float( $var ) ) {
			return new AFPData( self::DFLOAT, $var );
		} elseif ( is_bool( $var ) ) {
			return new AFPData( self::DBOOL, $var );
		} elseif ( is_array( $var ) ) {
			$result = [];
			foreach ( $var as $item ) {
				$result[] = self::newFromPHPVar( $item );
			}

			return new AFPData( self::DARRAY, $result );
		} elseif ( is_null( $var ) ) {
			return new AFPData();
		} else {
			throw new AFPException(
				'Data type ' . gettype( $var ) . ' is not supported by AbuseFilter'
			);
		}
	}

	/**
	 * @return AFPData
	 */
	public function dup() {
		return new AFPData( $this->type, $this->data );
	}

	/**
	 * @param AFPData $orig
	 * @param string $target
	 * @return AFPData
	 */
	public static function castTypes( $orig, $target ) {
		if ( $orig->type == $target ) {
			return $orig->dup();
		}
		if ( $target == self::DNULL ) {
			return new AFPData();
		}

		if ( $orig->type == self::DARRAY ) {
			if ( $target == self::DBOOL ) {
				return new AFPData( self::DBOOL, (bool)count( $orig->data ) );
			}
			if ( $target == self::DFLOAT ) {
				return new AFPData( self::DFLOAT, floatval( count( $orig->data ) ) );
			}
			if ( $target == self::DINT ) {
				return new AFPData( self::DINT, intval( count( $orig->data ) ) );
			}
			if ( $target == self::DSTRING ) {
				$s = '';
				foreach ( $orig->data as $item ) {
					$s .= $item->toString() . "\n";
				}

				return new AFPData( self::DSTRING, $s );
			}
		}

		if ( $target == self::DBOOL ) {
			return new AFPData( self::DBOOL, (bool)$orig->data );
		}
		if ( $target == self::DFLOAT ) {
			return new AFPData( self::DFLOAT, floatval( $orig->data ) );
		}
		if ( $target == self::DINT ) {
			return new AFPData( self::DINT, intval( $orig->data ) );
		}
		if ( $target == self::DSTRING ) {
			return new AFPData( self::DSTRING, strval( $orig->data ) );
		}
		if ( $target == self::DARRAY ) {
			return new AFPData( self::DARRAY, [ $orig ] );
		}
	}

	/**
	 * @param AFPData $value
	 * @return AFPData
	 */
	public static function boolInvert( $value ) {
		return new AFPData( self::DBOOL, !$value->toBool() );
	}

	/**
	 * @param AFPData $base
	 * @param AFPData $exponent
	 * @return AFPData
	 */
	public static function pow( $base, $exponent ) {
		$res = pow( $base->toNumber(), $exponent->toNumber() );
		if ( $res === (int)$res ) {
			return new AFPData( self::DINT, $res );
		} else {
			return new AFPData( self::DFLOAT, $res );
		}
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	public static function keywordIn( $a, $b ) {
		$a = $a->toString();
		$b = $b->toString();

		if ( $a == '' || $b == '' ) {
			return new AFPData( self::DBOOL, false );
		}

		return new AFPData( self::DBOOL, strpos( $b, $a ) !== false );
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	public static function keywordContains( $a, $b ) {
		$a = $a->toString();
		$b = $b->toString();

		if ( $a == '' || $b == '' ) {
			return new AFPData( self::DBOOL, false );
		}

		return new AFPData( self::DBOOL, strpos( $a, $b ) !== false );
	}

	/**
	 * @param AFPData $d1
	 * @param AFPData $d2
	 * @param bool $strict whether to also check types
	 * @return bool
	 */
	public static function equals( $d1, $d2, $strict = false ) {
		if ( $d1->type != self::DARRAY && $d2->type != self::DARRAY ) {
			$typecheck = $d1->type == $d2->type || !$strict;
			return $typecheck && $d1->toString() === $d2->toString();
		} elseif ( $d1->type == self::DARRAY && $d2->type == self::DARRAY ) {
			$data1 = $d1->data;
			$data2 = $d2->data;
			if ( count( $data1 ) !== count( $data2 ) ) {
				return false;
			}
			$length = count( $data1 );
			for ( $i = 0; $i < $length; $i++ ) {
				$result = self::equals( $data1[$i], $data2[$i], $strict );
				if ( $result === false ) {
					return false;
				}
			}
			return true;
		} else {
			// Trying to compare an array to something else
			if ( $strict ) {
				return false;
			}
			if ( $d1->type == self::DARRAY && count( $d1->data ) === 0 ) {
				return ( $d2->type == self::DBOOL && $d2->toBool() == false ) || $d2->type == self::DNULL;
			} elseif ( $d2->type == self::DARRAY && count( $d2->data ) === 0 ) {
				return ( $d1->type == self::DBOOL && $d1->toBool() == false ) || $d1->type == self::DNULL;
			} else {
				return false;
			}
		}
	}

	/**
	 * @param AFPData $str
	 * @param AFPData $pattern
	 * @return AFPData
	 */
	public static function keywordLike( $str, $pattern ) {
		$str = $str->toString();
		$pattern = '#^' . strtr( preg_quote( $pattern->toString(), '#' ), self::$wildcardMap ) . '$#u';
		Wikimedia\suppressWarnings();
		$result = preg_match( $pattern, $str );
		Wikimedia\restoreWarnings();

		return new AFPData( self::DBOOL, (bool)$result );
	}

	/**
	 * @param AFPData $str
	 * @param AFPData $regex
	 * @param int $pos
	 * @param bool $insensitive
	 * @return AFPData
	 * @throws Exception
	 */
	public static function keywordRegex( $str, $regex, $pos, $insensitive = false ) {
		$str = $str->toString();
		$pattern = $regex->toString();

		$pattern = preg_replace( '!(\\\\\\\\)*(\\\\)?/!', '$1\/', $pattern );
		$pattern = "/$pattern/u";

		if ( $insensitive ) {
			$pattern .= 'i';
		}

		Wikimedia\suppressWarnings();
		$result = preg_match( $pattern, $str );
		Wikimedia\restoreWarnings();
		if ( $result === false ) {
			throw new AFPUserVisibleException(
				'regexfailure',
				$pos,
				[ $pattern ]
			);
		}

		return new AFPData( self::DBOOL, (bool)$result );
	}

	/**
	 * @param AFPData $str
	 * @param AFPData $regex
	 * @param int $pos
	 * @return AFPData
	 */
	public static function keywordRegexInsensitive( $str, $regex, $pos ) {
		return self::keywordRegex( $str, $regex, $pos, true );
	}

	/**
	 * @param AFPData $data
	 * @return AFPData
	 */
	public static function unaryMinus( $data ) {
		if ( $data->type == self::DINT ) {
			return new AFPData( $data->type, -$data->toInt() );
		} else {
			return new AFPData( $data->type, -$data->toFloat() );
		}
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @param string $op
	 * @return AFPData
	 * @throws AFPException
	 */
	public static function boolOp( $a, $b, $op ) {
		$a = $a->toBool();
		$b = $b->toBool();
		if ( $op == '|' ) {
			return new AFPData( self::DBOOL, $a || $b );
		}
		if ( $op == '&' ) {
			return new AFPData( self::DBOOL, $a && $b );
		}
		if ( $op == '^' ) {
			return new AFPData( self::DBOOL, $a xor $b );
		}
		// Should never happen.
		throw new AFPException( "Invalid boolean operation: {$op}" );
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @param string $op
	 * @return AFPData
	 * @throws AFPException
	 */
	public static function compareOp( $a, $b, $op ) {
		if ( $op == '==' || $op == '=' ) {
			return new AFPData( self::DBOOL, self::equals( $a, $b ) );
		}
		if ( $op == '!=' ) {
			return new AFPData( self::DBOOL, !self::equals( $a, $b ) );
		}
		if ( $op == '===' ) {
			return new AFPData( self::DBOOL, self::equals( $a, $b, true ) );
		}
		if ( $op == '!==' ) {
			return new AFPData( self::DBOOL, !self::equals( $a, $b, true ) );
		}
		$a = $a->toString();
		$b = $b->toString();
		if ( $op == '>' ) {
			return new AFPData( self::DBOOL, $a > $b );
		}
		if ( $op == '<' ) {
			return new AFPData( self::DBOOL, $a < $b );
		}
		if ( $op == '>=' ) {
			return new AFPData( self::DBOOL, $a >= $b );
		}
		if ( $op == '<=' ) {
			return new AFPData( self::DBOOL, $a <= $b );
		}
		// Should never happen
		throw new AFPException( "Invalid comparison operation: {$op}" );
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @param string $op
	 * @param int $pos
	 * @return AFPData
	 * @throws AFPUserVisibleException
	 * @throws AFPException
	 */
	public static function mulRel( $a, $b, $op, $pos ) {
		$a = $a->toNumber();
		$b = $b->toNumber();

		if ( $op != '*' && $b == 0 ) {
			throw new AFPUserVisibleException( 'dividebyzero', $pos, [ $a ] );
		}

		if ( $op == '*' ) {
			$data = $a * $b;
		} elseif ( $op == '/' ) {
			$data = $a / $b;
		} elseif ( $op == '%' ) {
			$data = $a % $b;
		} else {
			// Should never happen
			throw new AFPException( "Invalid multiplication-related operation: {$op}" );
		}

		if ( $data === (int)$data ) {
			$data = intval( $data );
			$type = self::DINT;
		} else {
			$data = floatval( $data );
			$type = self::DFLOAT;
		}

		return new AFPData( $type, $data );
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	public static function sum( $a, $b ) {
		if ( $a->type == self::DSTRING || $b->type == self::DSTRING ) {
			return new AFPData( self::DSTRING, $a->toString() . $b->toString() );
		} elseif ( $a->type == self::DARRAY && $b->type == self::DARRAY ) {
			return new AFPData( self::DARRAY, array_merge( $a->toArray(), $b->toArray() ) );
		} else {
			$res = $a->toNumber() + $b->toNumber();
			if ( $res === (int)$res ) {
				return new AFPData( self::DINT, $res );
			} else {
				return new AFPData( self::DFLOAT, $res );
			}
		}
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	public static function sub( $a, $b ) {
		$res = $a->toNumber() - $b->toNumber();
		if ( $res === (int)$res ) {
			return new AFPData( self::DINT, $res );
		} else {
			return new AFPData( self::DFLOAT, $res );
		}
	}

	/** Convert shorteners */

	/**
	 * @throws MWException
	 * @return mixed
	 */
	public function toNative() {
		switch ( $this->type ) {
			case self::DBOOL:
				return $this->toBool();
			case self::DSTRING:
				return $this->toString();
			case self::DFLOAT:
				return $this->toFloat();
			case self::DINT:
				return $this->toInt();
			case self::DARRAY:
				$input = $this->toArray();
				$output = [];
				foreach ( $input as $item ) {
					$output[] = $item->toNative();
				}

				return $output;
			case self::DNULL:
				return null;
			default:
				throw new MWException( "Unknown type" );
		}
	}

	/**
	 * @return bool
	 */
	public function toBool() {
		return self::castTypes( $this, self::DBOOL )->data;
	}

	/**
	 * @return string
	 */
	public function toString() {
		return self::castTypes( $this, self::DSTRING )->data;
	}

	/**
	 * @return float
	 */
	public function toFloat() {
		return self::castTypes( $this, self::DFLOAT )->data;
	}

	/**
	 * @return int
	 */
	public function toInt() {
		return self::castTypes( $this, self::DINT )->data;
	}

	/**
	 * @return int|float
	 */
	public function toNumber() {
		return $this->type == self::DINT ? $this->toInt() : $this->toFloat();
	}

	/**
	 * @return array
	 */
	public function toArray() {
		return self::castTypes( $this, self::DARRAY )->data;
	}
}
