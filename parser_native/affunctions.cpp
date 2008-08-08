#include "affunctions.h"
#include <algorithm>
#include <fstream>
#include <sstream>
#include <ios>
#include <iostream>
#include <ctype.h>

#include <unicode/utf8.h>
#include <unicode/ustring.h>

#define EQUIVSET_LOC "equivset.txt"

AFPData af_count( vector<AFPData> args ) {
	if (!args.size()) {
		throw AFPException( "Not enough arguments to count" );
	}
	
	string needle,haystack;
	
	if (args.size() < 2) {
		needle = ",";
		haystack = args[0].toString();
	} else {
		needle = args[0].toString();
		haystack = args[1].toString();
	}
	
	size_t last_pos = 0;
	unsigned int count = 0;
	
	while (last_pos != haystack.npos) {
		count++;
		last_pos = haystack.find( needle, last_pos );
	}
	
	// One extra was added, but one extra is needed if only one arg was supplied.
	if (args.size() >= 2) {
		count--;
	}
	
	return AFPData((long int)count);
}

AFPData af_norm( vector<AFPData> args ) {
	if (!args.size()) {
		throw AFPException( "Not enough arguments to norm" );
	}
	
	string orig = args[0].toString();
	
	string::const_iterator p, charStart, end;
	int chr = 0,lastchr = 0;
	map<int,int> &equivSet = getEquivSet();
	string result;
	
	p = orig.begin();
	end = orig.end();
	
	while (chr = next_utf8_char( p, charStart, end )) {
		if (equivSet.find(chr) != equivSet.end()) {
			chr = equivSet[chr];
		}
		
		if (chr != lastchr && isalnum(chr)) {
			result.append(codepointToUtf8(chr));
		}
		
		lastchr = chr;
	}
	
	return AFPData(result);
}

string rmdoubles( string orig ) {
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

AFPData af_specialratio( vector<AFPData> args ) {
	if (!args.size()) {
		throw AFPException( "Not enough arguments to specialratio" );
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
	
	double ratio = (float)(specialcount) / (float)(utf8_strlen(orig));
		
	return AFPData(ratio);
}

AFPData af_rmspecials( vector<AFPData> args ) {
	if (!args.size()) {
		throw AFPException( "Not enough arguments to rmspecials" );
	}
	
	string orig = args[0].toString();
	string result = rmspecials(orig);
	
	return AFPData(result);
}

string rmspecials( string orig ) {
	string::const_iterator p, charStart, end;
	int chr = 0;
	string result;
	
	p = orig.begin();
	end = orig.end();
	while (chr = next_utf8_char( p, charStart, end )) {
		if (isalnum(chr)) {
			result.append(codepointToUtf8(chr));
		}
	}
	
	return result;
}

AFPData af_ccnorm( vector<AFPData> args ) {
	if (!args.size()) {
		throw AFPException( "Not enough arguments to ccnorm" );
	}
	
	return AFPData( confusable_character_normalise( args[0].toString() ) );
}

AFPData af_rmdoubles( vector<AFPData> args ) {
	if (!args.size()) {
		throw AFPException( "Not enough arguments to rmdoubles" );
	}
	
	string result = rmdoubles( args[0].toString() );
	
	return AFPData(result);
}

AFPData af_length( vector<AFPData> args ) {
	if (!args.size()) {
		throw AFPException( "Not enough arguments to lcase" );
	}
	
	return AFPData( (long int)utf8_strlen(args[0].toString()) );
}

AFPData af_lcase( vector<AFPData> args ) {
	if (!args.size()) {
		throw AFPException( "Not enough arguments to lcase" );
	}
	
	return AFPData(utf8_tolower(args[0].toString()));
}

string confusable_character_normalise( string orig ) {
	string::const_iterator p, charStart, end;
	int chr;
	map<int,int> &equivSet = getEquivSet();
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

map<int,int> &
getEquivSet() {
	static map<int,int> equivSet;
	// Map of codepoint:codepoint
	
	if (equivSet.empty()) {
		ifstream eqsFile( EQUIVSET_LOC );
		
		if (!eqsFile) {
			throw AFPException( "Unable to open equivalence sets!" );
		}
		
		string line;
		
		while (getline(eqsFile,line)) {			
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
	int c=0;
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

std::size_t
utf8_strlen(std::string const &s)
{
std::size_t	ret = 0;
	for (std::string::const_iterator it = s.begin(), end = s.end();
		it < end; ++it)
	{
	int	skip = 1;

		skip = U8_LENGTH(*it);
#if 0
		if (*it >= 0xc0) {
			if (*it < 0xe0)
				skip = 1;
			else if (*it < 0xf0)
				skip = 2;
			else
				skip = 3;
		} else
			skip = 1;
#endif
			
		if (it + skip >= end)
			return ret;	/* end of string */
				
		it += skip;
	}

	return ret;
}

/*
 * This could almost certainly be done in a nicer way.
 */
std::string utf8_tolower(std::string const &s)
{
	std::vector<UChar> ustring;
	UErrorCode error = U_ZERO_ERROR;

	for (int i = 0; i < s.size(); ) {
		UChar32 c;
		U8_NEXT(s.data(), i, s.size(), c);
		ustring.push_back(c);
	}

	std::vector<UChar> dest;
	u_strToLower(&dest[0], dest.size(), &ustring[0], ustring.size(),
			NULL, &error);
	
	if (U_FAILURE(error))
		return s;

	std::vector<unsigned char> u8string;
	int i, j;
	for (i = 0, j = 0; i < dest.size(); j++) {
		U8_APPEND_UNSAFE(&u8string[0], i, dest[j]);
	}
	return std::string(u8string.begin(), u8string.begin() + i);
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

	throw AFPException("Asked for code outside of range ($codepoint)\n");
}
