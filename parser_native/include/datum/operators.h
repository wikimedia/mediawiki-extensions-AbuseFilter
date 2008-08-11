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

#ifndef DATUM_OPERATORS_H
#define DATUM_OPERATORS_H

#include	"datum/visitors.h"

namespace afp {

namespace datum_impl {

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
struct afpmodulus<mpf_class> {
	double operator() (mpf_class const &a, mpf_class const &b) const {
		/* this is less than ideal */
		return std::fmod(a.get_d(), b.get_d());
	}
};

template<>
struct afpmodulus<boost::posix_time::ptime> {
	template<typename T, typename U>
	boost::posix_time::ptime operator() (T a, U b) const {
		throw type_error("operator % not applicable to datetime_t");
	}
};

template<typename T>
struct afppower {
	T operator() (T const &a, T const &b) const {
		return std::pow(a,b);
	}
};

template<typename T>
struct afpnegate {
	T operator() (T const &arg) const {
		return std::negate<T>()(arg);
	}
};

template<>
struct afpnegate<boost::posix_time::ptime> {
	boost::posix_time::ptime operator() (boost::posix_time::ptime const &) const {
		throw type_error("operator unary - not applicable to datetime_t");
	}
};

template<typename T>
struct afpplus {
	T operator() (T const &a, T const &b) const {
		return a + b;
	}
};

template<>
struct afpplus<boost::posix_time::ptime> {
	boost::posix_time::ptime operator() (
			boost::posix_time::ptime const &t,
			boost::posix_time::time_duration const &i) const {
		return t + i;
	}

	boost::posix_time::ptime operator() (
			boost::posix_time::time_duration const &i,
			boost::posix_time::ptime const &t) const {
		return t + i;
	}
};

template<>
struct afpplus<boost::posix_time::time_duration> {
	boost::posix_time::ptime operator() (
			boost::posix_time::time_duration const &i,
			boost::posix_time::ptime const &t) const {
		return t + i;
	}
};

template<typename T>
struct afpminus {
	T operator() (T const &a, T const &b) const {
		return a - b;
	}
};

template<>
struct afpminus<boost::posix_time::ptime> {
	boost::posix_time::ptime operator() (
			boost::posix_time::ptime const &t,
			boost::posix_time::time_duration const &i) const {
		return t - i;
	}
	
	boost::posix_time::ptime operator() (
			boost::posix_time::time_duration const &i,
			boost::posix_time::ptime const &t) const {
		throw type_error("operator- not applicable to (interval_t, datetime_t)");
	}
};

template<>
struct afpminus<boost::posix_time::time_duration> {
	boost::posix_time::ptime operator() (
			boost::posix_time::time_duration const &i,
			boost::posix_time::ptime const &t) const {
		return t - i;
	}
};

template<typename T>
struct afpmultiplies {
	T operator() (T const &a, T const &b) const {
		return a * b;
	}
};

template<>
struct afpmultiplies<boost::posix_time::ptime> {
	template<typename T, typename U>
	boost::posix_time::ptime operator() (T a, U b) const {
		throw type_error("operator * not applicable to datetime_t");
	}
};

template<>
struct afpmultiplies<boost::posix_time::time_duration> {
	template<typename U>
	boost::posix_time::time_duration operator() (boost::posix_time::time_duration const &a, U b) const {
		return a * b;
	}

	template<typename T, typename U>
	boost::posix_time::time_duration operator() (T a, U b) const {
		throw type_error("operator * not applicable to operands");
	}
};

template<typename T>
struct afpdivides {
	T operator() (T const &a, T const &b) const {
		return a / b;
	}
};

template<>
struct afpdivides<boost::posix_time::ptime> {
	template<typename T, typename U>
	boost::posix_time::ptime operator() (T a, U b) const {
		throw type_error("operator / not applicable to datetime_t");
	}
};

template<>
struct afpdivides<boost::posix_time::time_duration> {
	template<typename U>
	boost::posix_time::time_duration operator() (boost::posix_time::time_duration const &a, U b) const {
		return a / b;
	}

	template<typename T, typename U>
	boost::posix_time::time_duration operator() (T a, U b) const {
		throw type_error("operator / not applicable to operands");
	}
};

template<typename T>
struct afpequal_to {
	template<typename U>
	bool operator() (T a, U b) const {
		return a == b;
	}

	bool operator() (T, boost::posix_time::ptime const &) const {
		throw type_error("operator < not applicable to these types");
	}
		
	bool operator() (boost::posix_time::ptime const &, T) const {
		throw type_error("operator < not applicable to these types");
	}

	bool operator() (T, boost::posix_time::time_duration const &) const {
		throw type_error("operator < not applicable to these types");
	}

	bool operator() (boost::posix_time::time_duration const &, T) const {
		throw type_error("operator < not applicable to these types");
	}
};

template<>
struct afpequal_to<boost::posix_time::ptime> {
	template<typename U>
	bool operator() (boost::posix_time::ptime const &, U const &b) const {
		throw type_error("operator == not applicable to these types");
	}

	bool operator() (boost::posix_time::ptime const &a, boost::posix_time::ptime const &b) const {
		return a == b;
	}
};

template<>
struct afpequal_to<boost::posix_time::time_duration> {
	template<typename U>
	bool operator() (boost::posix_time::time_duration const &, U const &b) const {
		throw type_error("operator == not applicable to these types");
	}

	bool operator() (boost::posix_time::time_duration const &a, boost::posix_time::time_duration const &b) const {
		return a == b;
	}
};

template<typename T>
struct afpless {
	template<typename U>
	bool operator() (T a, U b) const {
		return a < b;
	}

	bool operator() (T, boost::posix_time::ptime const &) const {
		throw type_error("operator < not applicable to these types");
	}
		
	bool operator() (boost::posix_time::ptime const &, T) const {
		throw type_error("operator < not applicable to these types");
	}

	bool operator() (T, boost::posix_time::time_duration const &) const {
		throw type_error("operator < not applicable to these types");
	}

	bool operator() (boost::posix_time::time_duration const &, T) const {
		throw type_error("operator < not applicable to these types");
	}
};

template<>
struct afpless<boost::posix_time::ptime> {
	template<typename U>
	bool operator() (boost::posix_time::ptime const &, U const &b) const {
		throw type_error("operator < not applicable to these types");
	}

	bool operator() (boost::posix_time::ptime const &a, boost::posix_time::ptime const &b) const {
		return a < b;
	}
};

template<>
struct afpless<boost::posix_time::time_duration> {
	template<typename U>
	bool operator() (boost::posix_time::time_duration const &, U const &b) const {
		throw type_error("operator < not applicable to these types");
	}

	bool operator() (boost::posix_time::time_duration const &a, boost::posix_time::time_duration const &b) const {
		return a < b;
	}
};

} // namespace datum_impl

template<typename charT>
basic_datum<charT> operator+(basic_datum<charT> const &a, basic_datum<charT> const &b);
template<typename charT>
basic_datum<charT> operator-(basic_datum<charT> const &a, basic_datum<charT> const &b);
template<typename charT>
basic_datum<charT> operator*(basic_datum<charT> const &a, basic_datum<charT> const &b);
template<typename charT>
basic_datum<charT> operator/(basic_datum<charT> const &a, basic_datum<charT> const &b);
template<typename charT>
basic_datum<charT> operator%(basic_datum<charT> const &a, basic_datum<charT> const &b);

template<typename charT>
bool operator==(basic_datum<charT> const &a, basic_datum<charT> const &b);
template<typename charT>
bool operator!=(basic_datum<charT> const &a, basic_datum<charT> const &b);
template<typename charT>
bool operator<(basic_datum<charT> const &a, basic_datum<charT> const &b);
template<typename charT>
bool operator>(basic_datum<charT> const &a, basic_datum<charT> const &b);
template<typename charT>
bool operator<=(basic_datum<charT> const &a, basic_datum<charT> const &b);
template<typename charT>
bool operator>=(basic_datum<charT> const &a, basic_datum<charT> const &b);

template<typename charT>
basic_datum<charT> pow(basic_datum<charT> const &a, basic_datum<charT> const &b);

template<typename charT, typename char_type, typename traits>
std::basic_ostream<char_type, traits> &
operator<<(std::basic_ostream<char_type, traits> &s, basic_datum<charT> const &d) {
	d.print_to(s);
	return s;
}

template<typename charT>
basic_datum<charT> & 
basic_datum<charT>::operator= (basic_datum<charT> const &other) {
	value_ = other.value_;
	return *this;
}

template<typename charT>
basic_datum<charT> &
basic_datum<charT>::operator+=(basic_datum<charT> const &other)
{
	/*
	 * If either argument is a string, convert both to string.  After discussion
	 * on #mediawiki, this seems to be the least confusing option.
	 */
	if (value_.which() == 1 || other.value_.which() == 1) {
		value_ = toString() + other.toString();
		return *this;
	}

	basic_datum<charT> result = boost::apply_visitor(
			datum_impl::arith_visitor<charT, datum_impl::afpplus>(), value_, other.value_);
	*this = result;
	return *this;
}

template<typename charT>
basic_datum<charT> &
basic_datum<charT>::operator-=(basic_datum<charT> const &other)
{
	basic_datum<charT> result = boost::apply_visitor(
			datum_impl::arith_visitor<charT, datum_impl::afpminus>(), value_, other.value_);
	*this = result;
	return *this;
}

template<typename charT>
basic_datum<charT> &
basic_datum<charT>::operator*=(basic_datum<charT> const &other)
{
	basic_datum<charT> result = boost::apply_visitor(
			datum_impl::arith_visitor<charT, datum_impl::afpmultiplies>(), value_, other.value_);
	*this = result;
	return *this;
}
	
template<typename charT>
basic_datum<charT>&
basic_datum<charT>::operator/=(basic_datum<charT> const &other)
{
	basic_datum<charT> result = boost::apply_visitor(
			datum_impl::arith_visitor<charT, datum_impl::afpdivides>(), value_, other.value_);
	*this = result;
	return *this;
}

template<typename charT>
basic_datum<charT>&
basic_datum<charT>::operator%=(basic_datum<charT> const &other)
{
	basic_datum<charT> result = boost::apply_visitor(
			datum_impl::arith_visitor<charT, datum_impl::afpmodulus>(), value_, other.value_);
	*this = result;
	return *this;
}

template<typename charT>
basic_datum<charT>
basic_datum<charT>::operator+() const
{
	return *this;
}

template<typename charT>
basic_datum<charT>
basic_datum<charT>::operator-() const
{
	return boost::apply_visitor(datum_impl::arith_visitor<charT, datum_impl::afpnegate>(), value_);
}

template<typename charT>
basic_datum<charT>
operator+(basic_datum<charT> const &a, basic_datum<charT> const &b) {
	return basic_datum<charT>(a) += b;
}

template<typename charT>
basic_datum<charT>
operator-(basic_datum<charT> const &a, basic_datum<charT> const &b) {
	return basic_datum<charT>(a) -= b;
}

template<typename charT>
basic_datum<charT>
operator*(basic_datum<charT> const &a, basic_datum<charT> const &b) {
	return basic_datum<charT>(a) *= b;
}

template<typename charT>
basic_datum<charT>
operator/(basic_datum<charT> const &a, basic_datum<charT> const &b) {
	return basic_datum<charT>(a) /= b;
}

template<typename charT>
basic_datum<charT>
operator%(basic_datum<charT> const &a, basic_datum<charT> const &b) {
	return basic_datum<charT>(a) %= b;
}

template<typename charT>
basic_datum<charT>
pow(basic_datum<charT> const &a, basic_datum<charT> const &b) {
	mpf_t res;
	mpf_init(res);
	mpf_pow_ui(res, a.toFloat().get_mpf_t(), 
			b.toInt().get_ui());
	basic_datum<charT> result = basic_datum<charT>::from_double(
			mpf_class(res));
	mpf_clear(res);
	return result;
}

template<typename charT>
bool
operator==(basic_datum<charT> const &a, basic_datum<charT> const &b) {
	return a.compare(b);
}

template<typename charT>
bool
operator< (basic_datum<charT> const &a, basic_datum<charT> const &b) {
	return a.less_than(b);
}

template<typename charT>
bool
operator<= (basic_datum<charT> const &a, basic_datum<charT> const &b) {
	return a.less_than(b) || a == b;
}

template<typename charT>
bool
operator> (basic_datum<charT> const &a, basic_datum<charT> const &b) {
	return !(a <= b);
}

template<typename charT>
bool
operator>= (basic_datum<charT> const &a, basic_datum<charT> const &b) {
	return !(a < b);
}

template<typename charT>
bool
operator!= (basic_datum<charT> const &a, basic_datum<charT> const &b) {
	return !(a == b);
}

template<typename charT>
bool
basic_datum<charT>::operator! () const {
	return !toBool();
}

} // namespace afp

#endif	/* !DATUM_OPERATORS_H */
