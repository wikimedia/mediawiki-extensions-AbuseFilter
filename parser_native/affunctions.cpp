#include "affunctions.h"
#include <algorithm>
#include <fstream>
#include <sstream>
#include <ios>
#include <iostream>
#include <ctype.h>

#include "utf8.h"
#include "equiv.h"

namespace afp {

datum 
af_count(std::vector<datum> const &args) {
	if (!args.size()) {
		throw exception( "Not enough arguments to count" );
	}
	
	std::string needle, haystack;
	
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
		last_pos = haystack.find(needle, last_pos);
	}
	
	// One extra was added, but one extra is needed if only one arg was supplied.
	if (args.size() >= 2) {
		count--;
	}
	
	return datum((long int)count);
}

datum
af_norm(std::vector<datum> const &args) {
	if (!args.size()) {
		throw exception( "Not enough arguments to norm" );
	}
	
	std::string orig = args[0].toString();
	
	std::string::const_iterator p, charStart, end;
	int chr = 0, lastchr = 0;
	equiv_set const &equivs = equiv_set::instance();
	std::string result;
	
	p = orig.begin();
	end = orig.end();
	
	while (chr = utf8::next_utf8_char( p, charStart, end )) {
		chr = equivs.get(chr);
		
		if (chr != lastchr && isalnum(chr))
			result.append(utf8::codepoint_to_utf8(chr));
		
		lastchr = chr;
	}
	
	return datum(result);
}

std::string 
rmdoubles(std::string const &orig) {
	std::string::const_iterator p, charStart, end;
	int chr,lastchr = 0;
	std::string result;
	
	p = orig.begin();
	end = orig.end();
	while (chr = utf8::next_utf8_char( p, charStart, end )) {
		if (chr != lastchr) {
			result.append(utf8::codepoint_to_utf8(chr));
		}
		
		lastchr = chr;
	}
	
	return result;
}

datum
af_specialratio(std::vector<datum> const &args) {
	if (!args.size()) {
		throw exception( "Not enough arguments to specialratio" );
	}
	
	std::string orig = args[0].toString();
	std::string::const_iterator p, charStart, end;
	int chr;
	int specialcount = 0;
	
	p = orig.begin();
	end = orig.end();
	while (chr = utf8::next_utf8_char( p, charStart, end )) {
		if (!isalnum(chr)) {
			specialcount++;
		}
	}
	
	double ratio = (float)(specialcount) / (float)(utf8::utf8_strlen(orig));
		
	return datum(ratio);
}

datum
af_rmspecials(std::vector<datum> const &args) {
	if (!args.size()) {
		throw exception( "Not enough arguments to rmspecials" );
	}
	
	return datum(rmspecials(args[0].toString()));
}

std::string 
rmspecials(std::string const &orig) {
	std::string::const_iterator p, charStart, end;
	int chr = 0;
	std::string result;
	
	p = orig.begin();
	end = orig.end();
	while (chr = utf8::next_utf8_char( p, charStart, end )) {
		if (isalnum(chr)) {
			result.append(utf8::codepoint_to_utf8(chr));
		}
	}
	
	return result;
}

datum
af_ccnorm(std::vector<datum> const &args) {
	if (!args.size()) {
		throw exception( "Not enough arguments to ccnorm" );
	}
	
	return datum( confusable_character_normalise( args[0].toString() ) );
}

datum 
af_rmdoubles(std::vector<datum> const &args) {
	if (!args.size()) {
		throw exception( "Not enough arguments to rmdoubles" );
	}
	
	return datum(rmdoubles(args[0].toString()));
}

datum
af_length(std::vector<datum> const &args) {
	if (!args.size()) {
		throw exception( "Not enough arguments to lcase" );
	}
	
	return datum( (long int)utf8::utf8_strlen(args[0].toString()) );
}

datum
af_lcase(std::vector<datum> const &args) {
	if (!args.size()) {
		throw exception( "Not enough arguments to lcase" );
	}
	
	return datum(utf8::utf8_tolower(args[0].toString()));
}

std::string 
confusable_character_normalise(std::string const &orig) {
	std::string::const_iterator p, charStart, end;
	int chr;
	equiv_set const &equivs = equiv_set::instance();
	std::string result;
	
	p = orig.begin();
	end = orig.end();
	
	while (chr = utf8::next_utf8_char( p, charStart, end )) {
		chr = equivs.get(chr);
		result.append(utf8::codepoint_to_utf8(chr));
	}
	
	return result;
}

} // namespace afp
