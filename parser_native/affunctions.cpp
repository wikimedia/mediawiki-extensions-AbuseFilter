#include	<algorithm>
#include	<fstream>
#include	<sstream>
#include	<ios>
#include	<iostream>

#include	"utf8.h"
#include	"equiv.h"
#include	"affunctions.h"

#include	<unicode/uchar.h>

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
	if (!args.size())
		throw exception( "Not enough arguments to norm" );
	
	std::string orig = args[0].toString();
	
	int lastchr = 0;
	equiv_set const &equivs = equiv_set::instance();
	std::string result;
	
	utf8::utf8_iterator<std::string::const_iterator>
	       	it(orig.begin(), orig.end()), end;

	for (; it != end; ++it) {
		int chr = equivs.get(*it);
		
		if (chr != lastchr && u_isalnum(chr))
			result.append(utf8::codepoint_to_utf8(chr));
		
		lastchr = chr;
	}
	
	return datum(result);
}

std::string 
rmdoubles(std::string const &orig) {
	int lastchr = 0;
	std::string result;
	
	utf8::utf8_iterator<std::string::const_iterator> it(orig.begin(), orig.end()), end;
	for (; it != end; ++it) {
		if (*it != lastchr)
			result.append(utf8::codepoint_to_utf8(*it));
		
		lastchr = *it;
	}
	
	return result;
}

datum
af_specialratio(std::vector<datum> const &args) {
	if (!args.size())
		throw exception( "Not enough arguments to specialratio" );
	
	std::string orig = args[0].toString();
	int len = 0;
	int specialcount = 0;
	
	utf8::utf8_iterator<std::string::const_iterator> it(orig.begin(), orig.end()), end;
	for (; it != end; ++it) {
		len++;
		if (!u_isalnum(*it))
			specialcount++;
	}
	
	double ratio = (float)specialcount / len;
		
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
	std::string result;
	
	utf8::utf8_iterator<std::string::const_iterator> it(orig.begin(), orig.end()), end;
	for (; it != end; ++it) {
		if (u_isalnum(*it))
			result.append(utf8::codepoint_to_utf8(*it));
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
	equiv_set const &equivs = equiv_set::instance();
	std::string result;
	
	utf8::utf8_iterator<std::string::const_iterator> it(orig.begin(), orig.end()), end;
	for (; it != end; ++it) {
		int chr = equivs.get(*it);
		result.append(utf8::codepoint_to_utf8(chr));
	}
	
	return result;
}

} // namespace afp
