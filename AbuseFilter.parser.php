<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();
/**
Abuse filter parser.
Copyright (C) Victor Vasiliev, 2008. Based on ideas by Andrew Garrett Distributed under GNU GPL v2 terms.
 
Types of token:
* T_NONE - special-purpose token
* T_BRACE  - ( or )
* T_COMMA - ,
* T_OP - operator like + or ^
* T_NUMBER - number
* T_STRING - string, in "" or ''
* T_KEYWORD - keyword
* T_ID - identifier
 
Levels of parsing:
* Set (S) - ==, +=, etc.
* BoolOps (BO) - &, |, ^
* CompOps (CO) - ==, !=, ===, !==, >, <, >=, <=
* SumRel (SR) - +, -
* MulRel (MR) - *, /, %
* Pow (P) - **
* BoolNeg (BN) - ! operation
* SpecialOperators (SO) - in and like
* Unarys (U) - plus and minus in cases like -5 or -(2 * +2)
* Braces (B) - ( and )
* Functions (F)
* Atom (A) - return value
*/
 
class AFPToken {
        //Types of tken
        const TNone = 'T_NONE';
        const TID = 'T_ID';
        const TKeyword = 'T_KEYWORD';
        const TString = 'T_STRING';
        const TNumber = 'T_NUMBER';
        const TOp = 'T_OP';
        const TBrace = 'T_BRACE';
        const TComma = 'T_COMMA';
 
        var $type;
        var $value;
        var $pos;
 
        public function __construct( $type = self::TNone, $value = null, $pos = 0 ) {
                $this->type = $type;
                $this->value = $value;
                $this->pos = $pos;
        }
}
 
class AFPData {
        //Datatypes
        const DNumber = 'number';      //any integer or double
        const DString = 'string';
        const DNull   = 'null';
        const DBool   = 'bool';
 
        var $type;
        var $data;
 
        public function __construct( $type = self::DNull, $val = null ) {
                $this->type = $type;
                $this->data = $val;
        }
 
        public static function newFromPHPVar( $var ) {
                if( is_string( $var ) )
                        return new AFPData( self::DString, $var );
                elseif( is_int( $var ) || is_float( $var ) )
                        return new AFPData( self::DNumber, $var );
                elseif( is_bool( $var ) )
                        return new AFPData( self::DBool, $var );
                elseif( is_null( $var ) )
                        return new AFPData();
                else
                        throw new AFPException( "Data type " . gettype( $var ) . " is not supported by AbuseFilter" );
        }
 
        public function dup() {
                return new AFPData( $this->type, $this->data );
        }
 
        public static function castTypes( $orig, $target ) {
                if( $orig->type == $target ) 
                        return $orig->dup();
                if( $target == self::DNull ) {
                        return new AFPData();
                }
                if( $target == self::DBool ) {
                        return new AFPData( self::DBool, (bool)$orig->data );
                }
                if( $target == self::DNumber ) {
                        return new AFPData( self::DNumber, doubleval( $orig->data ) );
                }
                if( $target == self::DString ) {
                        return new AFPData( self::DString, strval( $orig->data ) );
                }
        }
 
        public static function boolInvert( $value ) {
                return new AFPData( self::DBool, !$value->toBool() );
        }
 
        public static function pow( $base, $exponent ) {
                return new AFPData( self::DNumber, pow( $base->toNumber(), $exponent->toNumber() ) );
        }
 
        public static function keywordIn( $a, $b ) {
                $a = $a->toString();
                $b = $b->toString();

		if ($a == '' || $b == '') {
			return new AFPData( self::DBool, false );
		}

                return new AFPData( self::DBool, in_string( $a, $b ) );
        }
 
        public static function keywordLike( $str, $regex ) {
                $str = $str->toString();
                $regex = $regex->toString() . 'u';     //Append unicode modifier
                wfSuppressWarnings();
                $result = preg_match( $regex, $str );
                wfRestoreWarnings();
                return new AFPData( self::DBool, (bool)$result );
        }
 
        public static function unaryMinus( $data ) {
                return new AFPData( self::DNumber, $data->toNumber() );
        }
 
        public static function boolOp( $a, $b, $op ) {
                $a = $a->toBool();
                $b = $b->toBool();
                if( $op == '|' )
                        return new AFPData( self::DBool, $a || $b );
                if( $op == '&' )
                        return new AFPData( self::DBool, $a && $b );
                if( $op == '^' )
                        return new AFPData( self::DBool, $a xor $b );
                throw new AFPException( "Invalid boolean operation: {$op}" );
        }
 
        public static function compareOp( $a, $b, $op ) {
                if( $op == '==' )
                        return new AFPData( self::DBool, $a->toString() === $b->toString() );
                if( $op == '!=' )
                        return new AFPData( self::DBool, $a->toString() !== $b->toString() );
                if( $op == '===' )
                        return new AFPData( self::DBool, $a->data === $b->data && $a->type == $b->type );
                if( $op == '!==' )
                        return new AFPData( self::DBool, $a->data !== $b->data || $a->type != $b->type );
                $a = $a->toString();
                $b = $b->toString();
                if( $op == '>' )
                        return new AFPData( self::DBool, $a > $b );
                if( $op == '<' )
                        return new AFPData( self::DBool, $a < $b );
                if( $op == '>=' )
                        return new AFPData( self::DBool, $a >= $b );
                if( $op == '<=' )
                        return new AFPData( self::DBool, $a <= $b );
                throw new AFPException( "Invalid comprasion operation: {$op}" );
        }
 
        public static function mulRel( $a, $b, $op ) {
                $a = $a->toNumber();
                $b = $b->toNumber();
                if( $op == '*' ) 
                        return new AFPData( self::DNumber, $a * $b );
                if( $op == '/' )
                        return new AFPData( self::DNumber, $a / $b );
                if( $op == '%' )
                        return new AFPData( self::DNumber, $a % $b );
                throw new AFPException( "Invalid multiplication-related operation: {$op}" );
        }
 
        public static function sum( $a, $b ) {
                if( $a->type == self::DString || $b->type == self::DString ) 
                        return new AFPData( self::DString, $a->toString() . $b->toString() );
                else
                        return new AFPData( self::DNumber, $a->toNumber() + $b->toNumber() );
        }
 
        public static function sub( $a, $b ) {
                return new AFPData( self::DNumber, $a->toNumber() - $b->toNumber() );
        }
 
        /** Convert shorteners */
        public function toBool() {
                return self::castTypes( $this, self::DBool )->data;
        }
 
        public function toString() {
                return self::castTypes( $this, self::DString )->data;
        }
 
        public function toNumber() {
                return self::castTypes( $this, self::DNumber )->data;
        }
}
 
class AFPException extends MWException {}
 
class AbuseFilterParser {
        var $mParams, $mVars, $mCode, $mTokens, $mPos, $mCur;
 
	// length,lcase,ccnorm,rmdoubles,specialratio,rmspecials,norm,count
        static $mFunctions = array(
                'lcase' => 'funcLc',
                'length' => 'funcLen',
//                 'norm' => 'funcNorm',
//                 'ccnorm' => 'funcSimpleNorm',
//                 'specialratio' => 'funcSpecialRatio',
//                 'rmspecials' => 'funcRmSpecials',
//                 'count' => 'funcCount'
        );
        static $mOps = array(
                '!', '*', '**', '/', '+', '-', '%', '&', '|', '^',
                '<', '>', '>=', '<=', '==', '!=', '=',  '===', '!==',
        );
        static $mKeywords = array(
                'in', 'like', 'true', 'false', 'null',
        );
        
        static $parserCache = array();

	static $funcCache = array();
 
        public function __construct() {
                $this->resetState();
        }
 
        public function resetState() {
                $this->mParams = array();
                $this->mCode = '';
                $this->mTokens = array();
                $this->mVars = array();
                $this->mPos = 0;
        }
        
        public function checkSyntax( $filter ) {
        	try {
        		$this->parse($filter);
        	} catch (AFPException excep) {
        		return excep->getMessage();
        	}
        	return true;
        }
 
        public function setVar( $name, $var ) {
                $this->mVars[$name] = AFPData::newFromPHPVar( $var );
        }
 
        public function setVars( $vars ) {
        	wfProfileIn( __METHOD__ );
                foreach( $vars as $name => $var ) {
                        $this->setVar( $name, $var );
                }
                wfProfileOut( __METHOD__ );
        }
 
        protected function move( $shift = +1 ) {
                $old = $this->mPos;
                $this->mPos += $shift;
                if( $this->mPos >= 0 && $this->mPos < count( $this->mTokens ) ) {
                        $this->mCur = $this->mTokens[$this->mPos];
                        return true;
                }
                else {
                        $this->mPos = $old;
                        return false;
                }
        }
 
        public function parse( $code ) {
        	wfProfileIn( __METHOD__ );
                $this->mCode = $code;
                $this->mTokens = self::parseTokens( $code );
                $this->mPos = 0;
                $this->mCur = $this->mTokens[0];
                $result = new AFPData();
                $this->doLevelEntry( $result );
                wfProfileOut( __METHOD__ );
                return $result->toBool();
        }
 
        /* Levels */
 
        /** Handles unexpected characters after the expression */
        protected function doLevelEntry( &$result ) {
                $this->doLevelSet( $result );
                if( $this->mCur->type != AFPToken::TNone ) {
                        throw new AFPException( "Unexpected {$this->mCur->type} at char {$this->mCur->pos}" );
                }
        }
 
        /** Handles "=" operator */
        protected function doLevelSet( &$result ) {
		wfProfileIn( __METHOD__ );
                if( $this->mCur->type == AFPToken::TID ) {
                        $varname = $this->mCur->value;
                        $this->move();
                        if( $this->mCur->type == AFPToken::TOp && $this->mCur->value == '=' ) {
                                $this->move();
                                $this->doLevelSet( $result );
                                $this->mVars[$varname] = $result->dup();
                                return;
                        }
                        $this->move( -1 );
                }
		wfProfileOut( __METHOD__ );
                $this->doLevelBoolOps( $result );
        }
 
        protected function doLevelBoolOps( &$result ) {
                $this->doLevelCompares( $result );
                $ops = array( '&', '|', '^' );
                while( $this->mCur->type == AFPToken::TOp && in_array( $this->mCur->value, $ops ) ) {
                        $op = $this->mCur->value;
                        $this->move();
                        $r2 = new AFPData();
                        $this->doLevelCompares( $r2 );
			wfProfileIn( __METHOD__ );
                        $result = AFPData::boolOp( $result, $r2, $op );
			wfProfileOut( __METHOD__ );
                }
        }
 
        protected function doLevelCompares( &$result ) {
                $this->doLevelMulRels( $result );
                $ops = array( '==', '===', '!=', '!==', '<', '>', '<=', '>=' );
                while( $this->mCur->type == AFPToken::TOp && in_array( $this->mCur->value, $ops ) ) {
                        $op = $this->mCur->value;
                        $this->move();
                        $r2 = new AFPData();
                        $this->doLevelMulRels( $r2 );
			wfProfileIn( __METHOD__ );
                        $result = AFPData::compareOp( $result, $r2, $op );
			wfProfileOut( __METHOD__ );
                }
        }
 
        protected function doLevelMulRels( &$result ) {
                $this->doLevelSumRels( $result );
		wfProfileIn( __METHOD__ );
                $ops = array( '*', '/', '%' );
                while( $this->mCur->type == AFPToken::TOp && in_array( $this->mCur->value, $ops ) ) {
                        $op = $this->mCur->value;
                        $this->move();
                        $r2 = new AFPData();
                        $this->doLevelSumRels( $r2 );
                        $result = AFPData::mulRel( $result, $r2, $op );
                }
		wfProfileOut( __METHOD__ );
        }
 
        protected function doLevelSumRels( &$result ) {
                $this->doLevelPow( $result );
		wfProfileIn( __METHOD__ );
                $ops = array( '+', '-' );
                while( $this->mCur->type == AFPToken::TOp && in_array( $this->mCur->value, $ops ) ) {
                        $op = $this->mCur->value;
                        $this->move();
                        $r2 = new AFPData();
                        $this->doLevelPow( $r2 );
                        if( $op == '+' )
                                $result = AFPData::sum( $result, $r2 );
                        if( $op == '-' )
                                $result = AFPData::sub( $result, $r2 );
                }
		wfProfileOut( __METHOD__ );
        }
 
        protected function doLevelPow( &$result ) {
                $this->doLevelBoolInvert( $result );
		wfProfileIn( __METHOD__ );
                while( $this->mCur->type == AFPToken::TOp && $this->mCur->value == '**' ) {
                        $this->move();
                        $expanent = new AFPData();
                        $this->doLevelBoolInvert( $expanent );
                        $result = AFPData::pow( $result, $expanent );
                }
		wfProfileOut( __METHOD__ );
        }
 
        protected function doLevelBoolInvert( &$result ) {
                if( $this->mCur->type == AFPToken::TOp && $this->mCur->value == '!' ) {
                        $this->move();
                        $this->doLevelSpecialWords( $result );
			wfProfileIn( __METHOD__ );
                        $result = AFPData::boolInvert( $result );
			wfProfileOut( __METHOD__ );
                } else {
                        $this->doLevelSpecialWords( $result );
                }
        }
 
        protected function doLevelSpecialWords( &$result ) {
                $this->doLevelUnarys( $result );
                $specwords = array( 'in', 'like' );
                if( $this->mCur->type == AFPToken::TKeyword && in_array( $this->mCur->value, $specwords ) ) {
                        $func = 'keyword' . ucfirst( $this->mCur->value );
                        $this->move();
                        $r2 = new AFPData();
                        $this->doLevelUnarys( $r2 );
			wfProfileIn( __METHOD__ );
			wfProfileIn( __METHOD__."-$func" );
                        $result = AFPData::$func( $result, $r2 );
			wfProfileOut( __METHOD__."-$func" );
			wfProfileOut( __METHOD__ );
                }
        }
 
        protected function doLevelUnarys( &$result ) {
                $op = $this->mCur->value;
                if( $this->mCur->type == AFPToken::TOp && ( $op == "+" || $op == "-" ) ) {
                        $this->move();
                        $this->doLevelBraces( $result );
			wfProfileIn( __METHOD__ );
                        if( $op == '-' ) {
                                $result = AFPData::unaryMinus( $result );
                        }
			wfProfileOut( __METHOD__ );
                } else {
                        $this->doLevelBraces( $result );
                }
        }
 
        protected function doLevelBraces( &$result ) {
                if( $this->mCur->type == AFPToken::TBrace && $this->mCur->value == '(' ) {
                        $this->move();
                        $this->doLevelSet( $result );
                        if( !($this->mCur->type == AFPToken::TBrace && $this->mCur->value == ')') ) 
                                throw new AFPException( "Expected ) at char {$this->mCur->pos}" );
                        $this->move();
                } else {
                        $this->doLevelFunction( $result );
                }
        }
 
        protected function doLevelFunction( &$result ) {
                if( $this->mCur->type == AFPToken::TID && isset( self::$mFunctions[$this->mCur->value] ) ) {
			wfProfileIn( __METHOD__ );
                        $func = self::$mFunctions[$this->mCur->value];
                        $this->move();
                        if( $this->mCur->type != AFPToken::TBrace || $this->mCur->value != '(' ) 
                                throw new AFPEexception( "Expected ( at char {$this->mCur->value}" );
			wfProfileIn( __METHOD__."-loadargs" );
                        $args = array();
                        if( $this->mCur->type != AFPToken::TBrace || $this->mCur->value != ')' )
                                do {
                                        $this->move();
                                        $r = new AFPData();
					try {
						$this->doLevelAtom( $r );
					} catch (AFPException $e) {
						$this->move( -1 );
	                                        $this->doLevelSet( $r );
					}
                                        $args[] = $r;
                                } while( $this->mCur->type == AFPToken::TComma );
                        if( $this->mCur->type != AFPToken::TBrace || $this->mCur->value != ')' ) {
                                throw new AFPException( "Expected ) at char {$this->mCur->pos}" );
                        }
			wfProfileOut( __METHOD__."-loadargs" );

			wfProfileIn( __METHOD__."-$func" );

			$funcHash = md5($func.serialize($args));

			if (isset(self::$funcCache[$funcHash])) {
				$result = self::$funcCache[$funcHash];
			} else {
				$result = self::$funcCache[$funcHash] = $this->$func( $args );
			}

			if (count(self::$funcCache) > 1000) {
				self::$funcCache = array();
			}

			wfProfileOut( __METHOD__."-$func" );

                        $this->move();
			wfProfileOut( __METHOD__ );
                } else {
                        $this->doLevelAtom( $result );
                }
        }
 
        protected function doLevelAtom( &$result ) {
		wfProfileIn( __METHOD__ );
                $tok = $this->mCur->value;
                switch( $this->mCur->type ) {
                        case AFPToken::TID:
                                if( isset( $this->mVars[$tok] ) ) {
                                        $result = $this->mVars[$tok];
                                } else {
                                        $result = new AFPData();
                                }
                                break;
                        case AFPToken::TString:
                                $result = new AFPData( AFPData::DString, $tok );
                                break;
                        case AFPToken::TNumber:
                                $result = new AFPData( AFPData::DNumber, $tok );
                                break;
                        case AFPToken::TKeyword:
                                if( $tok == "true" )
                                        $result = new AFPData( AFPData::DBool, true );
                                elseif( $tok == "false" )
                                        $result = new AFPData( AFPData::DBool, false );
                                elseif( $tok == "null" )
                                        $result = new AFPData();
                                else
                                        throw new AFPException( "Unexpected {$this->mCur->type} at char {$this->mCur->pos}" );
                                break;
                        case AFPToken::TBrace:
                                if( $this->mCur->value == ')' )
                                        return;        // Handled at the entry level
                        default: 
                                throw new AFPException( "Unexpected {$this->mCur->type} at char {$this->mCur->pos}" );
                }
                $this->move();
		wfProfileOut( __METHOD__ );
        }
 
        /* End of levels */
 
        public static function parseTokens( $code ) {
                $r = array();
                $len = strlen( $code );
                $hash = md5(trim($code));
                
                if (isset(self::$parserCache[$hash])) {
                	return self::$parserCache[$hash];
                }
                
                while( $tok = self::nextToken( $code, $len ) ) {
                        list( $val, $type, $code, $pos ) = $tok;
                        $r[] = new AFPToken( $type, $val, $pos );
                        if( $type == AFPToken::TNone )
                                break;
                }
                return self::$parserCache[$hash] = $r;
        }
 
        protected static function nextToken( $code, $len ) {
                $tok = '';
                if( strlen( $code ) == 0 ) return array( '', AFPToken::TNone, $code, $len );
                while( ctype_space( $code[0] ) )
                        $code = substr( $code, 1 );
                $pos = $len - strlen( $code );
                if( strlen( $code ) == 0 ) return array( '', AFPToken::TNone, $code, $pos );
                if( $code[0] == ',' )
                        return array( ',', AFPToken::TComma, substr( $code, 1 ), $pos );
                if( $code[0] == '(' or $code[0] == ')' )
                        return array( $code[0], AFPToken::TBrace, substr( $code, 1 ), $pos );
                if( $code[0] == '"' || $code[0] == "'" ) {
                        $type = $code[0];
                        $code = substr( $code, 1 );
                        while( strlen( $code ) != 0 ) {
                                if( $code[0] == $type ) {
                                        return array( $tok, AFPToken::TString, substr( $code, 1 ), $pos );
                                }
                                if( $code[0] == '\\' ) {
                                        if( $code[1] == '\\' )
                                                $tok .= '\\';
                                        elseif( $code[1] == $type )
                                                $tok .= $type;
                                        elseif( $code[1] == 'n' )
                                                $tok .= "\n";
                                        elseif( $code[1] == 'r' )
                                                $tok .= "\r";
                                        elseif( $code[1] == 't' )
                                                $tok .= "\t";
                                        else 
                                                $tok .= $code[1];
                                        $code = substr( $code, 2 );
                                } else {
                                        $tok .= $code[0];
                                        $code = substr( $code, 1 );
                                }
                        }
                        throw new AFPException( "Unclosed string begining at char $pos" );
                }
                if( ctype_punct( $code[0] ) ) {
                        $tok .= $code[0];
                        $code = substr( $code, 1 );
                        while( strlen( $code ) != 0 && ctype_punct( $code[0] ) ) {
                                $tok .= $code[0];
                                $code = substr( $code, 1 );
                        }
                        if( !in_array( $tok, self::$mOps ) )
                                throw new AFPException( "Invalid operator: {$tok} (at char $pos)" );
                        return array( $tok, AFPToken::TOp, $code, $pos );
                }
                if( ctype_digit( $code[0] ) ) {
                        $tok .= $code[0];
                        $code = substr( $code, 1 );
                        while( strlen( $code ) != 0 && self::isDigitOrDot( $code[0] ) ) {
                                $tok .= $code[0];
                                $code = substr( $code, 1 );
                        }
                        return array( in_string( '.', $tok ) ? doubleval( $tok ) : intval( $tok ), AFPToken::TNumber, $code, $pos );
                }
                if( self::isValidIdSymbol( $code[0] ) ) {
                        while( strlen( $code ) != 0 && self::isValidIdSymbol( $code[0] ) ) {
                                $tok .= $code[0];
                                $code = substr( $code, 1 );
                        }
                        $type = in_array( $tok, self::$mKeywords ) ? AFPToken::TKeyword : AFPToken::TID;
                                return array( $tok, $type, $code, $pos );
                }
                throw new AFPException( "Unrecognized token \"{$code[0]}\" at char $pos" );
        }
 
        protected static function isDigitOrDot( $chr ) {
                return ctype_digit( $chr ) || $chr == '.';
        }
 
        protected static function isValidIdSymbol( $chr ) {
                return ctype_alnum( $chr ) || $chr == '_';
        }
 
        //Built-in functions
        protected function funcLc( $args ) {
                global $wgContLang;
                if( count( $args ) < 1 )
                        throw new AFPExpection( "No params passed to lc()" );
                $s = $args[0]->toString();
                return new AFPData( AFPData::DString, $wgContLang->lc( $s ) );
        }
 
        protected function funcLen( $args ) {
                if( count( $args ) < 1 )
                        throw new AFPExpection( "No params passed to len()" );
                $s = $args[0]->toString();
                return new AFPData( AFPData::DNumber, mb_strlen( $s, 'utf-8' ) );
        }
        
        protected function funcNorm( $args ) {
                if( count( $args ) < 1 )
                        throw new AFPExpection( "No params passed to norm()" );
                $s = $args[0]->toString();
                return new AFPData( AFPData::DString, AbuseFilter::normalise( $s ) );
        }

	protected function funcSimpleNorm( $args ) {
                if( count( $args ) < 1 )
                        throw new AFPExpection( "No params passed to simplenorm()" );
                $s = $args[0]->toString();

		$s = preg_replace( '/[\d\W]+/', '', $s );
		$s = strtolower( $value );
                return new AFPData( AFPData::DString, $s );
	}
	
	protected function funcSpecialRatio( $args ) {
                if( count( $args ) < 1 )
                        throw new AFPExpection( "No params passed to simplenorm()" );
                $s = $args[0]->toString();
                
                if (!strlen($s)) {
                	return new AFPData( AFPData::DNumber, 0 );
                }
                
		$specialsonly = preg_replace('/\w/', '', $s );
		$val = (strlen($specialsonly) / strlen($s));
                
                return new AFPData( AFPData::DNumber, $val );
	}
}
