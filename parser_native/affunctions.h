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

#ifndef AFFUNCTIONS_H
#define AFFUNCTIONS_H

#include	<map>
#include	<vector>
#include	<algorithm>
#include	<fstream>
#include	<sstream>
#include	<ios>
#include	<iostream>

#include	<unicode/uchar.h>

#include	<boost/format.hpp>

#include	"aftypes.h"
#include	"equiv.h"

namespace afp {

template<typename charT>
basic_datum<charT> 
af_length (std::vector<basic_datum<charT> > const &args);

template<typename charT> 
basic_datum<charT> 
af_ccnorm (std::vector<basic_datum<charT> > const &args);

template<typename charT> 
basic_datum<charT> 
af_rmdoubles (std::vector<basic_datum<charT> > const &args);

template<typename charT> 
basic_datum<charT> 
af_specialratio (std::vector<basic_datum<charT> > const &args);

template<typename charT> 
basic_datum<charT> 
af_rmspecials (std::vector<basic_datum<charT> > const &args);

template<typename charT> 
basic_datum<charT> 
af_norm (std::vector<basic_datum<charT> > const &args);

template<typename charT> 
basic_datum<charT> 
af_count (std::vector<basic_datum<charT> > const &args);

template<typename charT> 
basic_fray<charT> 
confusable_character_normalise(basic_fray<charT> const &orig);

template<typename charT> 
basic_fray<charT> 
rmdoubles(basic_fray<charT> const &orig);

template<typename charT> 
basic_fray<charT> 
rmspecials(basic_fray<charT> const &orig);

struct too_many_arguments_exception : afp::exception {
	too_many_arguments_exception(char const *what) 
		: afp::exception(what) {}
};

struct too_few_arguments_exception : afp::exception {
	too_few_arguments_exception(char const *what) 
		: afp::exception(what) {}
};

namespace {

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

template<typename charT>
basic_datum<charT> 
af_count(std::vector<basic_datum<charT> > const &args) {
	check_args("count", args.size(), 1, 2);
	
	basic_fray<charT> needle, haystack;
	
	if (args.size() < 2) {
		needle = make_astring<charT, char>(",");
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
	
	return basic_datum<charT>::from_int((long int)count);
}

template<typename charT>
basic_datum<charT>
af_norm(std::vector<basic_datum<charT> > const &args) {
	check_args("norm", args.size(), 1);
	
	basic_fray<charT> orig = args[0].toString();
	
	int lastchr = 0;
	equiv_set const &equivs = equiv_set::instance();
	std::vector<charT> result;
	result.reserve(orig.size());
	
	for (std::size_t i = 0; i < orig.size(); ++i) {
		int chr = equivs.get(orig[i]);
		
		if (chr != lastchr && u_isalnum(chr))
			result.push_back(chr);
		
		lastchr = chr;
	}
	
	return basic_datum<charT>::from_string(basic_fray<charT>(&result[0], result.size()));
}

template<typename charT>
basic_fray<charT>
rmdoubles(basic_fray<charT> const &orig) {
	int lastchr = 0;
	std::vector<charT> result;
	result.reserve(orig.size());
	
	for (std::size_t i = 0; i < orig.size(); ++i) {
		if (orig[i] != lastchr)
			result.push_back(orig[i]);
		
		lastchr = orig[i];
	}
	
	return basic_fray<charT>(&result[0], result.size());
}

template<typename charT>
basic_datum<charT>
af_specialratio(std::vector<basic_datum<charT> > const &args) {
	check_args("specialratio", args.size(), 1);
	
	basic_fray<charT> orig = args[0].toString();
	int len = 0;
	int specialcount = 0;
	
	for (std::size_t i = 0; i < orig.size(); ++i) {
		len++;
		if (!u_isalnum(orig[i]))
			specialcount++;
	}
	
	double ratio = (float)specialcount / len;
		
	return basic_datum<charT>::from_double(ratio);
}

template<typename charT>
basic_datum<charT>
af_rmspecials(std::vector<basic_datum<charT> > const &args) {
	check_args("rmspecials", args.size(), 1);
	return basic_datum<charT>::from_string(rmspecials(args[0].toString()));
}

template<typename charT>
basic_fray<charT> 
rmspecials(basic_fray<charT> const &orig) {
	std::vector<charT> result;
	result.reserve(orig.size());
	
	for (std::size_t i = 0; i < orig.size(); ++i) {
		if (u_isalnum(orig[i]))
			result.push_back(orig[i]);
	}
	
	return basic_fray<charT>(&result[0], result.size());
}

template<typename charT>
basic_datum<charT>
af_ccnorm(std::vector<basic_datum<charT> > const &args) {
	check_args("ccnorm", args.size(), 1);
	return basic_datum<charT>::from_string(confusable_character_normalise(args[0].toString()));
}

template<typename charT>
basic_datum<charT> 
af_rmdoubles(std::vector<basic_datum<charT> > const &args) {
	check_args("ccnorm", args.size(), 1);
	return basic_datum<charT>::from_string(rmdoubles(args[0].toString()));
}

template<typename charT>
basic_datum<charT>
af_length(std::vector<basic_datum<charT> > const &args) {
	check_args("ccnorm", args.size(), 1);
	return basic_datum<charT>::from_int(args[0].toString().size());
}

template<typename charT>
basic_datum<charT>
af_lcase(std::vector<basic_datum<charT> > const &args) {
	check_args("ccnorm", args.size(), 1);
	std::vector<charT> result;
	basic_fray<charT> const orig = args[0].toString();
	result.reserve(orig.size());

	for (std::size_t i = 0; i < orig.size(); ++i)
		result.push_back(u_tolower(orig[i]));

	return basic_datum<charT>::from_string(basic_fray<charT>(&result[0], result.size()));
}

template<typename charT>
basic_fray<charT> 
confusable_character_normalise(basic_fray<charT> const &orig) {
	equiv_set const &equivs = equiv_set::instance();
	std::vector<charT> result;
	result.reserve(orig.size());
	
	for (std::size_t i = 0; i < orig.size(); ++i)
		result.push_back(equivs.get(orig[i]));
	
	return basic_fray<charT>(&result[0], result.size());
}

} // namespace afp

#endif	/* !AFFUNCTIONS_H */
