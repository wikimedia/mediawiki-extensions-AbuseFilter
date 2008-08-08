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

#include	"filter_evaluator.h"
#include	"parser.h"
#include	"affunctions.h"

namespace afp {

filter_evaluator::filter_evaluator()
{
	e.add_function("length", af_length);
	e.add_function("lcase", af_lcase);
	e.add_function("ccnorm", af_ccnorm);
	e.add_function("rmdoubles", af_rmdoubles);
	e.add_function("specialratio", af_specialratio);
	e.add_function("rmspecials", af_rmspecials);
	e.add_function("norm", af_norm);
	e.add_function("count", af_count);
}

bool
filter_evaluator::evaluate(std::string const &filter) const
{
	try {
		return (bool) e.evaluate(filter);
	} catch (std::exception &e) {
		std::cerr << "can't evaluate filter: " << e.what() << '\n';
		return false;
	}
}

void
filter_evaluator::add_variable(std::string const &key, datum value)
{
	e.add_variable(key, value);
}

} // namespace afp
