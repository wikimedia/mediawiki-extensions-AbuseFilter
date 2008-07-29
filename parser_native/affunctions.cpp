#include "affunctions.h"
#include <algorithm>
#include <fstream>
#include <sstream>
#include <ios>
#include <iostream>
#include <ctype.h>

#define EQUIVSET_LOC "equivset.txt"

map<string,AFPFunction> af_functions;

AFPData af_length( vector<AFPData> args );
AFPData af_lcase( vector<AFPData> args );
AFPData af_ccnorm( vector<AFPData> args );
AFPData af_rmdoubles( vector<AFPData> args );
AFPData af_specialratio( vector<AFPData> args );

void af_registerfunction( string name, AFPFunction method ) {
	af_functions[name] = method;
}

void registerBuiltinFunctions() {
	af_registerfunction( "length", (AFPFunction) &af_length);
	af_registerfunction( "lcase", (AFPFunction) &af_lcase );
	af_registerfunction( "ccnorm", (AFPFunction) &af_ccnorm );
	af_registerfunction( "rmdoubles", (AFPFunction) &af_rmdoubles );
	af_registerfunction( "specialratio", (AFPFunction) &af_specialratio );
}

AFPData af_specialratio( vector<AFPData> args ) {
	if (!args.size()) {
		throw new AFPException( "Not enough arguments to specialratio" );
	}
	
	string orig = args[0].toString();
	string::const_iterator p, charStart, end;
	int chr,lastchr = 0;
	int specialcount = 0;
	
	p = orig.begin();
	end = orig.end();
	while (chr = next_utf8_char( p, charStart, end )) {
		if (!isalnum(chr)) {
			specialcount++;
		}
	}
	
	double ratio = (float)(specialcount) / (float)(orig.size());
		
	return AFPData(ratio);	
}

AFPData af_ccnorm( vector<AFPData> args ) {
	if (!args.size()) {
		throw new AFPException( "Not enough arguments to ccnorm" );
	}
	
	return AFPData( confusable_character_normalise( args[0].toString() ) );
}

AFPData af_rmdoubles( vector<AFPData> args ) {
	if (!args.size()) {
		throw new AFPException( "Not enough arguments to rmdoubles" );
	}
	
	string orig = args[0].toString();
	string::const_iterator p, charStart, end;
	int chr,lastchr = 0;
	string result;
	
	p = orig.begin();
	end = orig.end();
	while (chr = next_utf8_char( p, charStart, end )) {
		if (chr != lastchr) {
			result.append(codepointToUtf8(chr));
		}
		
		lastchr = chr;
	}
	
	return result;
}

AFPData af_length( vector<AFPData> args ) {
	if (!args.size()) {
		throw new AFPException( "Not enough arguments to lcase" );
	}
	
	return AFPData( (long int)args[0].toString().size() );
}

AFPData af_lcase( vector<AFPData> args ) {
	if (!args.size()) {
		throw new AFPException( "Not enough arguments to lcase" );
	}
	
	string s = args[0].toString();
	
	transform( s.begin(), s.end(), s.begin(), (int(*)(int)) tolower );
	
	return AFPData(s);
}

string confusable_character_normalise( string orig ) {
	string::const_iterator p, charStart, end;
	int chr;
	map<int,int> equivSet = getEquivSet();
	string result;
	
	p = orig.begin();
	end = orig.end();
	
	while (chr = next_utf8_char( p, charStart, end )) {
		if (equivSet.find(chr) != equivSet.end()) {
			chr = equivSet[chr];
		}
		
		result.append(codepointToUtf8(chr));
	}
	
	return result;
}

AFPData callFunction( string name, vector<AFPData> args ) {
	if ( af_functions.find( name ) != af_functions.end() ) {
		// Found the function
		AFPFunction func = af_functions[name];
		return func(args);
	}
}

bool isFunction( string name ) {
	return af_functions.find(name) != af_functions.end();
}

map<int,int> getEquivSet() {
	static map<int,int> equivSet;
	// Map of codepoint:codepoint
	
	if (equivSet.empty()) {
		ifstream eqsFile( EQUIVSET_LOC );
		
		if (!eqsFile) {
			throw new AFPException( "Unable to open equivalence sets!" );
		}
		
		string line;
		
		while (!! getline(eqsFile,line)) {			
			size_t pos = line.find_first_of( ":", 0 );
			
			if (pos != line.npos) {
				// We have a codepoint:codepoint thing.
				int actual = 0;
				int canonical = 0;
				
				istringstream actual_buffer(line.substr(0,pos));
				istringstream canon_buffer( line.substr(pos+1));
				actual_buffer >> actual;
				canon_buffer  >> canonical;
				
				if (actual != 0 && canonical != 0) {
					equivSet[actual] = canonical;
				}
			}
		}
		
		eqsFile.close();
	}
	
	return equivSet;
}

// Weak UTF-8 decoder
// Will return garbage on invalid input (overshort sequences, overlong sequences, etc.)
// Stolen from wikidiff2 extension by Tim Starling (no point in reinventing the wheel)
int next_utf8_char(std::string::const_iterator & p, std::string::const_iterator & charStart, 
		std::string::const_iterator end)
{
	int c;
	unsigned char byte;
	int bytes = 0;
	charStart = p;
	if (p == end) {
		return 0;
	}
	do {
		byte = (unsigned char)*p;
		if (byte < 0x80) {
			c = byte;
			bytes = 0;
		} else if (byte >= 0xc0) {
			// Start of UTF-8 character
			// If this is unexpected, due to an overshort sequence, we ignore the invalid
			// sequence and resynchronise here
		   	if (byte < 0xe0) {
				bytes = 1;
				c = byte & 0x1f;
			} else if (byte < 0xf0) {
				bytes = 2;
				c = byte & 0x0f;
			} else {
				bytes = 3;
				c = byte & 7;
			}
		} else if (bytes) {
			c <<= 6;
			c |= byte & 0x3f;
			--bytes;
		} else {
			// Unexpected continuation, ignore
		}
		++p;
	} while (bytes && p != end);
	return c;
}

// Ported from MediaWiki core function in PHP.
string codepointToUtf8( int codepoint ) {
	string ret;
	
	if(codepoint < 0x80) {
		ret.append(1, codepoint);
		return ret;
	}
	
	if(codepoint < 0x800) {
		ret.append(1, codepoint >> 6 & 0x3f | 0xc0);
		ret.append(1, codepoint & 0x3f | 0x80);
		return ret;
	}
	
	if(codepoint <  0x10000) {
		ret.append(1, codepoint >> 12 & 0x0f | 0xe0);
		ret.append(1, codepoint >> 6 & 0x3f | 0x80);
		ret.append(1, codepoint & 0x3f | 0x80);
		return ret;
	}
	
	if(codepoint < 0x110000) {
		ret.append(1, codepoint >> 18 & 0x07 | 0xf0);
		ret.append(1, codepoint >> 12 & 0x3f | 0x80);
		ret.append(1, codepoint >> 6 & 0x3f | 0x80);
		ret.append(1, codepoint & 0x3f | 0x80);
		return ret;
	}

	throw new AFPException("Asked for code outside of range ($codepoint)\n");
}
