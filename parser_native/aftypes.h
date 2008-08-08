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

#ifndef AFTYPES_H
#define AFTYPES_H

#include <string>
#include <vector>
#include <iostream>

#include <boost/variant.hpp>
#include <boost/lexical_cast.hpp>

namespace afp {

/*
 *                ABUSEFILTER VARIABLE STORAGE
 *                ============================
 *
 * datum is the AFP variable type.  It is runtime polymorphic, storing objects
 * of string, integer or floating point type.  It provides the usual operator
 * overloads, except that operator>>(istream, datum) is not provided.
 *
 * A datum automatically converts between types as required, using the
 * following rules:
 *
 *   - arithmetic operations convert arguments to doubles if either argument is
 *     a double, otherwise to ints.
 *   - converting a string to a numeric type attempts to parse the string as an
 *     integer.  if this is not possible, the value 0 is used.
 *   - type-blind compare (operator==, compare()) does a lexical comparison if
 *     both arguments are strings, otherwise an arithmetic comparison.
 *   - type-sensitive compare always returns false if the types are different;
 *     otherwise, it is identical to a type-blind compare.
 *   - ordinal comparisons always convert their arguments to arithmetic types,
 *     even if both are strings.
 *
 * Internally, datum is implemented using a boost:variant object.  This is
 * entirely stack-based, avoiding memory allocation overhead when manipulating
 * datum objects.
 */
class datum {
public:
	datum();

	/*
	 * Generic ctor tries to convert to an int.
	 */
	template<typename T>
	datum(T const &v)
		: value_(boost::lexical_cast<long int>(v))
	{
	}

	// Specific type constructors
	datum( std::string const &var );
	datum( char const *var );
	datum( long int var );
	datum( float var );
	datum( double var );
	datum( bool var );

	datum( const datum & oldData );
		
	// Type forcing helpers
	static datum from_string(std::string const &v);
	static datum from_int(long int v);
	static datum from_double(double v);
	
	// Assignment operator
	datum &operator= (const datum & other);
		
	datum &operator+=(datum const &other);
	datum &operator-=(datum const &other);
	datum &operator*=(datum const &other);
	datum &operator/=(datum const &other);
	datum &operator%=(datum const &other);
	bool operator!() const;

	bool compare(datum const &other) const;
	bool compare_with_type(datum const &other) const;
	bool less_than(datum const &other) const;

	std::string toString() const;
	long int toInt() const;
	double toFloat() const;
	bool toBool() const {
		return (bool) toInt();
	}
		
	operator long int(void) const {
		return toInt();
	}

	operator double(void) const {
		return toFloat();
	}

	operator std::string(void) const {
		return toString();
	}

	operator bool(void) const {
		return (bool) toInt();
	}

	template<typename char_type, typename traits>
	void
	print_to(std::basic_ostream<char_type, traits> &s) const {
		s << value_;
	}

protected:
	void _init_from_string(std::string const &);	

	typedef boost::variant<std::string, long int, double> valuetype;
	valuetype value_;
};

class exception : std::exception {
public:
	exception(std::string const &what) 
		: what_(what) {}
	~exception() throw() {}

	char const *what() const throw() {
		return what_.c_str();
	}

private:
	std::string what_;
};

datum operator+(datum const &a, datum const &b);
datum operator-(datum const &a, datum const &b);
datum operator*(datum const &a, datum const &b);
datum operator/(datum const &a, datum const &b);
datum operator%(datum const &a, datum const &b);

bool operator==(datum const &a, datum const &b);
bool operator!=(datum const &a, datum const &b);
bool operator<(datum const &a, datum const &b);
bool operator>(datum const &a, datum const &b);
bool operator<=(datum const &a, datum const &b);
bool operator>=(datum const &a, datum const &b);

datum pow(datum const &a, datum const &b);

template<typename char_type, typename traits>
std::basic_ostream<char_type, traits> &
operator<<(std::basic_ostream<char_type, traits> &s, datum const &d) {
	d.print_to(s);
	return s;
}

} // namespace afp

#endif	/* !AFTYPES_H */
