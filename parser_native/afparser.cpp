#include "afparser.h"
#include <ctype.h>

struct ParseStatus {
	AFPToken newToken;
	string code;
	unsigned int len;
	
	ParseStatus() {code = ""; len = 0;}
	
	ParseStatus( AFPToken nt, string sc, int l ) {
		newToken = nt;
		code = sc;
		len = l;
	}
};

bool af_nextToken( ParseStatus* ps );

vector<AFPToken> af_parse( string code ) {
	vector<AFPToken> ret;
	AFPToken curTok;
	ParseStatus ps;
	int pos = 0;
	
	ps.len = code.size();
	ps.code = code;
	
	while ( af_nextToken( &ps ) ) {
		ret.push_back( curTok = ps.newToken );
		
//  		printf( "New token. Type %d, value %s\n", curTok.type, curTok.value.c_str() );
		
		if (curTok.type == T_NONE) {
			break;
		}
	}
	
	return ret;
}

bool af_nextToken( ParseStatus* ps ) {
	AFPToken tok;
	unsigned int pos = 0;
	string code = ps->code;
	unsigned int len = ps->len;
	
	if (code.size() == 0) {
		 tok = AFPToken( T_NONE, "", len );
		 *ps = ParseStatus( tok, code, len );
		 return true;
	}
	
	while (isspace( code[0] )) {
		code = code.substr(1);
	}
	
	if (code.size() == 0) {
		tok = AFPToken( T_NONE, "", len );
		*ps = ParseStatus( tok, code, len );
		return true;
	}
	
	pos = len - code.size();
	
	// Comma
	if (code[0] == ',') {
		tok = AFPToken( T_COMMA, code.substr(0,1), pos );
		code = code.substr(1);
		*ps = ParseStatus( tok, code, len );
		return true;
	}
	
	// Parens
	if (code[0] == '(' || code[0] == ')') {
		tok = AFPToken( T_BRACE, code.substr(0,1), pos );
		code = code.substr(1);
		*ps = ParseStatus( tok, code, len );
		return true;
	}
	
	// Strings
	if ( code[0] == '"' || code[0] == '\'' ) {
		char type = code[0];
		code = code.substr(1);
		string s = "";
		
		while (code.size() > 0) {
			if ( code[0] == type ) {
				code = code.substr(1);
				tok = AFPToken( T_STRING, s, pos );
				*ps = ParseStatus( tok, code, len );
				return true;
			}
			
			if ( code[0] == '\\' ) {
				if (code[1] == '\\') {
					s.append( 1, '\\' );
				} else if (code[1] == type) {
					s.append( 1, type );
				} else if (code[1] == 'n' ) {
					s.append( 1, '\n' );
				} else if (code[1] == 'r' ) {
					s.append( 1, '\r' );
				} else if (code[1] == 't' ) {
					s.append( 1, '\t' );
				} else {
					s.append( 1, code[1] );
				}
				code = code.substr(2);
			} else {
				s.append( 1, code[0] );
				code = code.substr(1);
			}
		}
		
		throw AFPException( "Unclosed string" );
	}
	
	// Operators
	if (ispunct( code[0] ) ) {
		string s = "";
		
		while ( code.length() > 0 && ispunct(code[0]) ) {
			s.append( 1, code[0] );
			code = code.substr(1);
		}
		
		if (!isValidOp( s )) {
			throw AFPException( "Invalid operator %s", s );
		}
		
		tok = AFPToken( T_OP, s, pos );
		*ps = ParseStatus( tok, code, len );
		return true;
	}
	
	// Raw numbers
	if ( isdigit( code[0] ) ) {
		string s = "";
		
		while ( code.length() > 0 && isDigitOrDot( code[0] ) ) {
			s.append( 1, code[0] );
			code = code.substr(1);
		}
		
		tok = AFPToken( T_NUMBER, s, pos );
		*ps = ParseStatus( tok, code, len );
		return true;
	}
	
	if ( isValidIdSymbol( code[0] ) ) {
		string op = "";
		
		while (code.length() > 0 && isValidIdSymbol( code[0]) ) {
			op.append( 1, code[0] );
			code = code.substr(1);
		}
		
		int type = T_ID;
		if (isKeyword(op)) {
			type = T_KEYWORD;
		}
		
		tok = AFPToken( type, op, pos );
		*ps = ParseStatus( tok, code, len );
		return true;
	}
	
	throw AFPException( "Unrecognised token" );
}

bool isDigitOrDot( char chr ) {
	return isdigit(chr) || chr == '.';
}

bool isValidIdSymbol( char chr ) {
	return isalnum(chr) || chr == '_';
}

bool isValidOp( string op ) {
	vector<string> validOps = getValidOps();
	
	return isInVector( op, validOps );
}

bool isKeyword( string id ) {
	vector<string> keywords = getKeywords();
	
	return isInVector( id, keywords );
}

vector<string> getValidOps() {
	static vector<string> validOps;
	
	if (validOps.empty()) {
		validOps.push_back( "!" );
		validOps.push_back( "*" );
		validOps.push_back( "**" );
		validOps.push_back( "/" );
		validOps.push_back( "+" );
		validOps.push_back( "-" );
		validOps.push_back( "%" );
		validOps.push_back( "&" );
		validOps.push_back( "|" );
		validOps.push_back( "^" );
		validOps.push_back( "<" );
		validOps.push_back( ">" );
		validOps.push_back( ">=" );
		validOps.push_back( "<=" );
		validOps.push_back( "==" );
		validOps.push_back( "!=" );
		validOps.push_back( "=" );
		validOps.push_back( "===" );
		validOps.push_back( "!==" );
	}
	
	return validOps;
}

vector<string> getKeywords() {
	static vector<string> keywords;
	
	if (keywords.size() == 0) {
		keywords.push_back( "in" );
		keywords.push_back( "like" );
		keywords.push_back( "true" );
		keywords.push_back( "false" );
		keywords.push_back( "null" );
	}
	
	return keywords;
}
