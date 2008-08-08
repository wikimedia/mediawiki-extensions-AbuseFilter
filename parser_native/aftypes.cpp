#include "aftypes.h"
#include <sstream>
#include <ios>
#include <iostream>
#include <cassert>
#include <algorithm>
#include <cmath>
#include <boost/lexical_cast.hpp>

AFPToken::AFPToken(unsigned int new_type, string new_value, unsigned int new_pos) {
	type = new_type;
	value = new_value;
	pos = new_pos;
}


AFPData::AFPData(std::string const &var) {
	_init_from_string(var);
}

AFPData::AFPData(char const *var)
{
	_init_from_string(var);
}

void
AFPData::_init_from_string(std::string const &var)
{
	// Try integer	
	try {
		value_ = boost::lexical_cast<long int>(var);
	} catch (boost::bad_lexical_cast &e) {
		try {
			value_ = boost::lexical_cast<double>(var);
		} catch (boost::bad_lexical_cast &e) {
			/* If it's nothing else, it's a string */
			value_ = var;
		}
	}
}

AFPData::AFPData() {
}

AFPData::AFPData(AFPData const &other) 
	: value_(other.value_)
{
}

AFPData::AFPData(long int var)
	: value_(var)
{
}

AFPData::AFPData(double var)
	: value_(var)
{
}

AFPData::AFPData(float var)
	: value_(var)
{
}

AFPData::AFPData(bool var)
	: value_((long int) var)
{
}

AFPData & AFPData::operator= (AFPData const &other) {
	// Protect against self-assignment
	if (this == &other) {
		return *this;
	}
	
	value_ = other.value_;
	return *this;
}

bool isInVector( string needle, vector<string> haystack ) {
	return std::find(haystack.begin(), haystack.end(), needle) != haystack.end();
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
 * Conversions from AFPData to other types.
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
AFPData::toString() const {
	return boost::apply_visitor(to_string_visitor(), value_);
}

long int
AFPData::toInt() const {
	return boost::apply_visitor(to_int_visitor(), value_);
}

double
AFPData::toFloat() const {
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

/*
 * A visitor that performs an arithmetic operation on its arguments,
 * after doing appropriate int->double promotion.
 */
template<template<typename V> class Operator>
struct arith_visitor : boost::static_visitor<AFPData> {
	/*
	 * Anything involving a double returns a double.
	 * Otherwise, int is returned.
	 */
	template<typename T, typename U>
	AFPData operator() (T const &a, U const &b) const {
		typedef typename from_string_converter<T>::type a_type;
		typedef typename from_string_converter<U>::type b_type;

		Operator<typename preferred_type<a_type, b_type>::type> op;
		return op(
			from_string_converter<T>::convert(a), 
			from_string_converter<U>::convert(b));
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
struct arith_compare_visitor : boost::static_visitor<AFPData> {
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

AFPData &
AFPData::operator+=(AFPData const &other)
{
	AFPData result = boost::apply_visitor(arith_visitor<std::plus>(), value_, other.value_);
	*this = result;
	return *this;
}

AFPData &
AFPData::operator-=(AFPData const &other)
{
	AFPData result = boost::apply_visitor(arith_visitor<std::minus>(), value_, other.value_);
	*this = result;
	return *this;
}

AFPData &
AFPData::operator*=(AFPData const &other)
{
	AFPData result = boost::apply_visitor(arith_visitor<std::multiplies>(), value_, other.value_);
	*this = result;
	return *this;
}
	
AFPData&
AFPData::operator/=(AFPData const &other)
{
	AFPData result = boost::apply_visitor(arith_visitor<std::divides>(), value_, other.value_);
	*this = result;
	return *this;
}

AFPData&
AFPData::operator%=(AFPData const &other)
{
	AFPData result = boost::apply_visitor(arith_visitor<afpmodulus>(), value_, other.value_);
	*this = result;
	return *this;
}

AFPData
operator+(AFPData const &a, AFPData const &b) {
	return AFPData(a) += b;
}

AFPData
operator-(AFPData const &a, AFPData const &b) {
	return AFPData(a) -= b;
}

AFPData
operator*(AFPData const &a, AFPData const &b) {
	return AFPData(a) *= b;
}

AFPData
operator/(AFPData const &a, AFPData const &b) {
	return AFPData(a) /= b;
}

AFPData
operator%(AFPData const &a, AFPData const &b) {
	return AFPData(a) %= b;
}

bool
operator==(AFPData const &a, AFPData const &b) {
	return a.compare(b);
}

bool
AFPData::compare(AFPData const &other) const {
	return boost::apply_visitor(compare_visitor<std::equal_to>(), value_, other.value_);
}

bool
AFPData::less_than(AFPData const &other) const {
	return boost::apply_visitor(arith_compare_visitor<std::less>(), value_, other.value_);
}

bool
operator< (AFPData const &a, AFPData const &b) {
	return a.less_than(b);
}

bool
operator<= (AFPData const &a, AFPData const &b) {
	return a.less_than(b) || a == b;
}

bool
operator> (AFPData const &a, AFPData const &b) {
	return !(a <= b);
}

bool
operator>= (AFPData const &a, AFPData const &b) {
	return !(a < b);
}

bool
operator!= (AFPData const &a, AFPData const &b) {
	return !(a == b);
}

bool
AFPData::operator! () const {
	return !(int) *this;
}
