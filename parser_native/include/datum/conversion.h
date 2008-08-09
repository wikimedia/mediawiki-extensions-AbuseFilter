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

#ifndef DATUM_CONVERSION_H
#define DATUM_CONVERSION_H

#include	"datum/visitors.h"

namespace afp {

template<typename charT>
basic_datum<charT>
basic_datum<charT>::from_string_convert(basic_datum<charT>::string_t const &var)
{
	// Try integer	
	try {
		return from_int(boost::lexical_cast<long int>(var));
	} catch (boost::bad_lexical_cast &e) {
		try {
			return from_double(boost::lexical_cast<double>(var));
		} catch (boost::bad_lexical_cast &e) {
			/* If it's nothing else, it's a string */
			return from_string(var);
		}
	}
}

template<typename charT>
basic_datum<charT>
basic_datum<charT>::from_string(basic_datum<charT>::string_t const &v)
{
	basic_datum<charT> d;
	d.value_ = v;
	return d;
}

template<typename charT>
basic_datum<charT>
basic_datum<charT>::from_int(long int v)
{
	basic_datum<charT> d;
	d.value_ = v;
	return d;
}

template<typename charT>
basic_datum<charT>
basic_datum<charT>::from_double(double v)
{
	basic_datum<charT> d;
	d.value_ = v;
	return d;
}

template<typename charT>
std::basic_string<charT>
basic_datum<charT>::toString() const {
	return boost::apply_visitor(datum_impl::to_string_visitor<charT>(), value_);
}

template<typename charT>
long int
basic_datum<charT>::toInt() const {
	return boost::apply_visitor(datum_impl::to_int_visitor<charT>(), value_);
}

template<typename charT>
double
basic_datum<charT>::toFloat() const {
	return boost::apply_visitor(datum_impl::to_double_visitor<charT>(), value_);
}

} // namespace afp

#endif	/* !DATUM_CONVERSION_H */
