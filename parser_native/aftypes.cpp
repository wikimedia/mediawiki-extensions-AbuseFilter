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

#include <sstream>
#include <ios>
#include <iostream>
#include <cassert>
#include <algorithm>
#include <cmath>

#include <boost/lexical_cast.hpp>

#include "aftypes.h"

namespace afp {

datum::datum() {
}

datum::datum(datum const &other)
	: value_(other.value_)
{
}

datum
datum::from_string_convert(std::string const &var)
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

datum
datum::from_string(std::string const &v)
{
	datum d;
	d.value_ = v;
	return d;
}

datum
datum::from_int(long int v)
{
	datum d;
	d.value_ = v;
	return d;
}

datum
datum::from_double(double v)
{
	datum d;
	d.value_ = v;
	return d;
}

template<> datum datum::from<std::string>(std::string const &v) {
	return from_string(v);
}

template<> datum datum::from<long int>(long int const &v) {
	return from_int(v);
}

template<> datum datum::from<double>(double const &v) {
	return from_double(v);
}

datum & datum::operator= (datum const &other) {
	// Protect against self-assignment
	if (this == &other) {
		return *this;
	}
	
	value_ = other.value_;
	return *this;
}

/*
 * Convert a string to an integer value.
 */
template<typename T>
struct from_string_converter {
	typedef T type;

	static type convert(T const &v) {
		return v;
	}
};

template<>
struct from_string_converter<std::string> {
	typedef long int type;

	template<typename T>
	static type convert(T const &v) {
		try {
			return boost::lexical_cast<type>(v);
		} catch (boost::bad_lexical_cast &e) {
			return 0;
		}
	}
};

/*
 * Conversions from datum to other types.
 */
struct to_string_visitor : boost::static_visitor<std::string> {
	std::string operator() (std::string const &v) const {
		return v;
	}

	template<typename T>
	std::string operator() (T const &v) const {
		return boost::lexical_cast<std::string>(v);
	}
};

struct to_int_visitor : boost::static_visitor<long int> {
	long int operator() (std::string const &v) const {
		try {
			return boost::lexical_cast<long int>(v);
		} catch (boost::bad_lexical_cast &e) {
			return 0;
		}
	}

	long int operator() (double o) const {
		return (long int) o;
	}

	template<typename T>
	long int operator() (T const &v) const {
		return v;
	}
};

struct to_double_visitor : boost::static_visitor<double> {
	double operator() (std::string const &v) const {
		try {
			return boost::lexical_cast<double>(v);
		} catch (boost::bad_lexical_cast &e) {
			return 0;
		}
	}

	template<typename T>
	double operator() (T const &v) const {
		return v;
	}
};

std::string
datum::toString() const {
	return boost::apply_visitor(to_string_visitor(), value_);
}

long int
datum::toInt() const {
	return boost::apply_visitor(to_int_visitor(), value_);
}

double
datum::toFloat() const {
	return boost::apply_visitor(to_double_visitor(), value_);
}

/* Given T and U, find the preferred type for maths (i.e. double, if present) */
template<typename T, typename U>
struct preferred_type {
	typedef T type;
};

template<typename T>
struct preferred_type<double, T> {
	typedef double type;
};

template<typename T>
struct preferred_type<T, double> {
	typedef double type;
};

template<>
struct preferred_type<double, double> {
	typedef double type;
};

/*
 * std::modulus doesn't work with double, so we provide our own.
 */
template<typename T>
struct afpmodulus {
	T operator() (T const &a, T const &b) const {
		return a % b;
	}
};

template<>
struct afpmodulus<double> {
	double operator() (double const &a, double const &b) const {
		return std::fmod(a, b);
	}
};

template<typename T>
struct afppower {
	T operator() (T const &a, T const &b) const {
		return std::pow(a,b);
	}
};

/*
 * A visitor that performs an arithmetic operation on its arguments,
 * after doing appropriate int->double promotion.
 */
template<template<typename V> class Operator>
struct arith_visitor : boost::static_visitor<datum> {
	/*
	 * Anything involving a double returns a double.
	 * Otherwise, int is returned.
	 */
	template<typename T, typename U>
	datum operator() (T const &a, U const &b) const {
		typedef typename from_string_converter<T>::type a_type;
		typedef typename from_string_converter<U>::type b_type;

		Operator<typename preferred_type<a_type, b_type>::type> op;
		return datum::from<typename preferred_type<a_type, b_type>::type>(op(
			from_string_converter<T>::convert(a), 
			from_string_converter<U>::convert(b)));
	}

	/*
	 * Unary version.
	 */
	template<typename T>
	datum operator() (T const &a) const {
		typedef typename from_string_converter<T>::type a_type;

		Operator<typename preferred_type<a_type, a_type>::type> op;
		return datum::from<typename preferred_type<a_type, a_type>::type>(
				op(from_string_converter<T>::convert(a)));
	}

};

/*
 * Like arith_visitor, but for equality comparisons.
 */
template<
	template<typename V> class Operator,
	typename T,
	typename U>
struct compare_visitor_impl {
	bool operator() (T const &a, U const &b) const {
		typedef typename from_string_converter<T>::type a_type;
		typedef typename from_string_converter<U>::type b_type;

		Operator<typename preferred_type<a_type, b_type>::type> op;
		return op(
			from_string_converter<T>::convert(a), 
			from_string_converter<U>::convert(b));
	}
};

/*
 * Specialise for string<>string comparisons
 */
template<template<typename V> class Operator>
struct compare_visitor_impl<Operator, std::string, std::string> : boost::static_visitor<bool> {
	bool operator() (std::string const &a, std::string const &b) const {
		Operator<std::string> op;
		return op(a, b);
	}
};

template<template<typename V> class Operator>
struct compare_visitor : boost::static_visitor<bool> {
	template<typename T, typename U> 
	bool operator() (T const &a, U const &b) const {
		return compare_visitor_impl<Operator, T, U>()(a, b);
	}
};

/*
 * For comparisons that only work on integers - strings will be converted.
 */
template<template<typename V> class Operator>
struct arith_compare_visitor : boost::static_visitor<bool> {
	template<typename T, typename U>
	bool operator() (T const &a, U const &b) const {
		typedef typename from_string_converter<T>::type a_type;
		typedef typename from_string_converter<U>::type b_type;

		Operator<typename preferred_type<a_type, b_type>::type> op;
		return op(
			from_string_converter<T>::convert(a), 
			from_string_converter<U>::convert(b));
	}
};

datum &
datum::operator+=(datum const &other)
{
	/*
	 * If either argument is a string, convert both to string.  After discussion
	 * on #mediawiki, this seems to be the least confusing option.
	 */
	if (value_.which() == 0 || other.value_.which() == 0) {
		value_ = toString() + other.toString();
		return *this;
	}

	datum result = boost::apply_visitor(arith_visitor<std::plus>(), value_, other.value_);
	*this = result;
	return *this;
}

datum &
datum::operator-=(datum const &other)
{
	datum result = boost::apply_visitor(arith_visitor<std::minus>(), value_, other.value_);
	*this = result;
	return *this;
}

datum &
datum::operator*=(datum const &other)
{
	datum result = boost::apply_visitor(arith_visitor<std::multiplies>(), value_, other.value_);
	*this = result;
	return *this;
}
	
datum&
datum::operator/=(datum const &other)
{
	datum result = boost::apply_visitor(arith_visitor<std::divides>(), value_, other.value_);
	*this = result;
	return *this;
}

datum&
datum::operator%=(datum const &other)
{
	datum result = boost::apply_visitor(arith_visitor<afpmodulus>(), value_, other.value_);
	*this = result;
	return *this;
}

datum
datum::operator+() const
{
	return *this;
}

datum
datum::operator-() const
{
	return boost::apply_visitor(arith_visitor<std::negate>(), value_);
}

datum
operator+(datum const &a, datum const &b) {
	return datum(a) += b;
}

datum
operator-(datum const &a, datum const &b) {
	return datum(a) -= b;
}

datum
operator*(datum const &a, datum const &b) {
	return datum(a) *= b;
}

datum
operator/(datum const &a, datum const &b) {
	return datum(a) /= b;
}

datum
operator%(datum const &a, datum const &b) {
	return datum(a) %= b;
}

datum
pow(datum const &a, datum const &b) {
	datum result = datum::from_double(std::pow(a.toFloat(),b.toFloat()));
	
	return result;
}

bool
operator==(datum const &a, datum const &b) {
	return a.compare(b);
}

bool
datum::compare(datum const &other) const {
	return boost::apply_visitor(compare_visitor<std::equal_to>(), value_, other.value_);
}

bool
datum::compare_with_type(datum const &other) const {
	if (value_.which() != other.value_.which())
		return false;

	return boost::apply_visitor(compare_visitor<std::equal_to>(), value_, other.value_);
}

bool
datum::less_than(datum const &other) const {
	return boost::apply_visitor(arith_compare_visitor<std::less>(), value_, other.value_);
}

bool
operator< (datum const &a, datum const &b) {
	return a.less_than(b);
}

bool
operator<= (datum const &a, datum const &b) {
	return a.less_than(b) || a == b;
}

bool
operator> (datum const &a, datum const &b) {
	return !(a <= b);
}

bool
operator>= (datum const &a, datum const &b) {
	return !(a < b);
}

bool
operator!= (datum const &a, datum const &b) {
	return !(a == b);
}

bool
datum::operator! () const {
	return !toBool();
}

} // namespace afp
