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

#ifndef FILTER_EVALUATOR_H
#define FILTER_EVALUATOR_H

#include	<string>
#include	<map>

#include	<unicode/uchar.h>

#include	"aftypes.h"
#include	"parser.h"
#include	"affunctions.h"

namespace afp {

template<typename charT>
struct basic_filter_evaluator {
	basic_filter_evaluator();

	bool evaluate(std::basic_string<charT> const &filter) const;

	void add_variable(
		std::basic_string<charT> const &key, 
		basic_datum<charT> value);

private:
	basic_expressor<charT> e;
};

typedef basic_filter_evaluator<char> filter_evaluator;
typedef basic_filter_evaluator<UChar32> u32filter_evaluator;

template<typename charT>
basic_filter_evaluator<charT>::basic_filter_evaluator()
{
	e.add_function(make_astring<charT, char>("length"), af_length<charT>);
	e.add_function(make_astring<charT, char>("lcase"), af_lcase<charT>);
	e.add_function(make_astring<charT, char>("ccnorm"), af_ccnorm<charT>);
	e.add_function(make_astring<charT, char>("rmdoubles"), af_rmdoubles<charT>);
	e.add_function(make_astring<charT, char>("specialratio"), af_specialratio<charT>);
	e.add_function(make_astring<charT, char>("rmspecials"), af_rmspecials<charT>);
	e.add_function(make_astring<charT, char>("norm"), af_norm<charT>);
	e.add_function(make_astring<charT, char>("count"), af_count<charT>);
}

template<typename charT>
bool
basic_filter_evaluator<charT>::evaluate(
		std::basic_string<charT> const &filter) const
{
	try {
		return e.evaluate(filter).toBool();
	} catch (std::exception &e) {
		std::cerr << "can't evaluate filter: " << e.what() << '\n';
		return false;
	}
}

template<typename charT>
void
basic_filter_evaluator<charT>::add_variable(
		std::basic_string<charT> const &key, 
		basic_datum<charT> value)
{
	e.add_variable(key, value);
}

} // namespace afp

#endif	/* !FILTER_EVALUATOR_H */
