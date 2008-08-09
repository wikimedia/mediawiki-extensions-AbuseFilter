/*
 * Copyright (c) 2008 Andrew Garrett.
 * Copyright (c) 2008 River Tarnell <river@wikimedia.org>
 * Derived from public domain code contributed by Victor Vasiliev.
 *
 * Permission is granted to anyone to use this software for any purpose,
 * including commercial applications, and to alter it and redistribute it
 * freely. This software is provided 'as-is', without any express or
 * implied warranty.
 */

#include	<algorithm>
#include	<fstream>
#include	<sstream>
#include	<ios>
#include	<iostream>

#include	<unicode/uchar.h>

#include	<boost/format.hpp>

#include	"utf8.h"
#include	"equiv.h"
#include	"affunctions.h"

namespace {

struct too_many_arguments_exception : afp::exception {
	too_many_arguments_exception(char const *what) 
		: afp::exception(what) {}
};

struct too_few_arguments_exception : afp::exception {
	too_few_arguments_exception(char const *what) 
		: afp::exception(what) {}
};

void
check_args(std::string const &fname, int args, int min, int max = 0)
{
	if (max == 0)
		max = min;
	if (args < min) {
		std::string s = str(boost::format(
			"too few arguments for function %s (got %d, expected %d)")
				% fname % args % min);
		throw too_few_arguments_exception(s.c_str());
	} else if (args > max) {
		std::string s = str(boost::format(
			"too many arguments for function %s (got %d, expected %d)")
				% fname % args % min);
		throw too_many_arguments_exception(s.c_str());
	}
}

} // anonymous namespace

namespace afp {

datum 
af_count(std::vector<datum> const &args) {
	check_args("count", args.size(), 1, 2);
	
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
	if (args.size() >= 2)
		count--;
	
	return datum((long int)count);
}

datum
af_norm(std::vector<datum> const &args) {
	check_args("norm", args.size(), 1);
	
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
	check_args("specialratio", args.size(), 1);
	
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
	check_args("rmspecials", args.size(), 1);
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
	check_args("ccnorm", args.size(), 1);
	return datum( confusable_character_normalise( args[0].toString() ) );
}

datum 
af_rmdoubles(std::vector<datum> const &args) {
	check_args("ccnorm", args.size(), 1);
	return datum(rmdoubles(args[0].toString()));
}

datum
af_length(std::vector<datum> const &args) {
	check_args("ccnorm", args.size(), 1);
	return datum( (long int)utf8::utf8_strlen(args[0].toString()) );
}

datum
af_lcase(std::vector<datum> const &args) {
	check_args("ccnorm", args.size(), 1);
	return datum(utf8::utf8_tolower(args[0].toString()));
}

std::string 
confusable_character_normalise(std::string const &orig) {
	equiv_set const &equivs = equiv_set::instance();
	std::string result;
	
	utf8::utf8_iterator<std::string::const_iterator> it(orig.begin(), orig.end()), end;
	for (; it != end; ++it)
		result += utf8::codepoint_to_utf8(equivs.get(*it));
	
	return result;
}

} // namespace afp
