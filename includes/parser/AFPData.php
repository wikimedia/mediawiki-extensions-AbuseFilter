<?php

class AFPData {
	// Datatypes
	const DINT = 'int';
	const DSTRING = 'string';
	const DNULL = 'null';
	const DBOOL = 'bool';
	const DFLOAT = 'float';
	const DLIST = 'list';

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
	 * @param null $val
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

			return new AFPData( self::DLIST, $result );
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

		if ( $orig->type == self::DLIST ) {
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
		if ( $target == self::DLIST ) {
			return new AFPData( self::DLIST, [ $orig ] );
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
		return new AFPData( self::DFLOAT, pow( $base->toFloat(), $exponent->toFloat() ) );
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
	 * @param string $value
	 * @param mixed $list
	 * @return bool
	 */
	public static function listContains( $value, $list ) {
		// Should use built-in PHP function somehow
		foreach ( $list->data as $item ) {
			if ( self::equals( $value, $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param AFPData $d1
	 * @param AFPData $d2
	 * @return bool
	 */
	public static function equals( $d1, $d2 ) {
		return $d1->type != self::DLIST && $d2->type != self::DLIST &&
		$d1->toString() === $d2->toString();
	}

	/**
	 * @param AFPData $str
	 * @param AFPData $pattern
	 * @return AFPData
	 */
	public static function keywordLike( $str, $pattern ) {
		$str = $str->toString();
		$pattern = '#^' . strtr( preg_quote( $pattern->toString(), '#' ), self::$wildcardMap ) . '$#u';
		MediaWiki\suppressWarnings();
		$result = preg_match( $pattern, $str );
		MediaWiki\restoreWarnings();

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

		$result = preg_match( $pattern, $str );
		if ( $result === false ) {
			throw new AFPUserVisibleException(
				'regexfailure',
				$pos,
				[ 'unspecified error in preg_match()', $pattern ]
			);
		}

		return new AFPData( self::DBOOL, (bool)$result );
	}

	/**
	 * @param string $str
	 * @param string $regex
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
		throw new AFPException( "Invalid boolean operation: {$op}" ); // Should never happen.
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
			return new AFPData( self::DBOOL, $a->type == $b->type && self::equals( $a, $b ) );
		}
		if ( $op == '!==' ) {
			return new AFPData( self::DBOOL, $a->type != $b->type || !self::equals( $a, $b ) );
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
		throw new AFPException( "Invalid comparison operation: {$op}" ); // Should never happen
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
		// Figure out the type.
		if ( $a->type == self::DFLOAT || $b->type == self::DFLOAT ||
			$a->toFloat() != $a->toString() || $b->toFloat() != $b->toString()
		) {
			$type = self::DFLOAT;
			$a = $a->toFloat();
			$b = $b->toFloat();
		} else {
			$type = self::DINT;
			$a = $a->toInt();
			$b = $b->toInt();
		}

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

		if ( $type == self::DINT ) {
			$data = intval( $data );
		} else {
			$data = floatval( $data );
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
		} elseif ( $a->type == self::DLIST && $b->type == self::DLIST ) {
			return new AFPData( self::DLIST, array_merge( $a->toList(), $b->toList() ) );
		} else {
			return new AFPData( self::DFLOAT, $a->toFloat() + $b->toFloat() );
		}
	}

	/**
	 * @param AFPData $a
	 * @param AFPData $b
	 * @return AFPData
	 */
	public static function sub( $a, $b ) {
		return new AFPData( self::DFLOAT, $a->toFloat() - $b->toFloat() );
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
			case self::DLIST:
				$input = $this->toList();
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

	public function toList() {
		return self::castTypes( $this, self::DLIST )->data;
	}
}
