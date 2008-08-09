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
#ifndef AFSTRING_H
#define AFSTRING_H

#include	<string>

#include	<unicode/uchar.h>

#include	<boost/regex/pending/unicode_iterator.hpp>

typedef std::basic_string<UChar32> u32string;
typedef std::basic_istream<UChar32> u32istream;
typedef std::basic_ostream<UChar32> u32ostream;
typedef std::basic_iostream<UChar32> u32iostream;
typedef std::basic_istringstream<UChar32> u32istringstream;
typedef std::basic_ostringstream<UChar32> u32ostringstream;
typedef std::basic_stringstream<UChar32> u32stringstream;

template<typename iterator, int i> struct u32_conv_type;

template<typename iterator> struct u32_conv_type<iterator, 1> {
	typedef boost::u8_to_u32_iterator<iterator, UChar32> type;
};

template<typename iterator> struct u32_conv_type<iterator, 2> {
	typedef boost::u16_to_u32_iterator<iterator, UChar32> type;
};

template<typename iterator, int i> struct u8_conv_type;

template<typename iterator> struct u8_conv_type<iterator, 4> {
	typedef boost::u32_to_u8_iterator<iterator, char> type;
};

/*
 * Convert UTF-8 or UTF-16 strings to u32strings.
 */
template<typename charT>
u32string
make_u32string(std::basic_string<charT> v) 
{
	u32string result;

	typedef typename u32_conv_type<
			typename std::basic_string<charT>::iterator,
			sizeof(charT)>::type conv_type;

	std::copy(conv_type(v.begin()), conv_type(v.end()),
			std::back_inserter(result));

	return result;
}

template<typename charT>
u32string
make_u32string(charT const *v)
{
	return make_u32string(std::basic_string<charT>(v));
}

template<typename charT>
std::string
make_u8string(std::basic_string<charT> v) 
{
	std::string result;

	typedef typename u8_conv_type<
			typename std::basic_string<charT>::iterator,
			sizeof(charT)>::type conv_type;

	std::copy(conv_type(v.begin()), conv_type(v.end()),
			std::back_inserter(result));

	return result;
}

template<typename charT>
std::string
make_u8string(charT const *v)
{
	return make_u8string(std::basic_string<charT>(v));
}

template<typename fromT, typename toT>
struct ustring_convertor;

template<>
struct ustring_convertor<char, UChar32> {
	static u32string convert(std::string const &from) {
		return make_u32string(from);
	}
};

template<>
struct ustring_convertor<char, char> {
	static std::string convert(std::string const &from) {
		return from;
	}
};

template<typename To, typename From>
std::basic_string<To>
make_astring(std::basic_string<From> const &from)
{
	return ustring_convertor<From, To>::convert(from);
}

template<typename To, typename From>
std::basic_string<To>
make_astring(From const *from)
{
	return make_astring<To>(std::basic_string<From>(from));
}

struct bad_u32lexical_cast : std::runtime_error {
	bad_u32lexical_cast() : std::runtime_error(
		"bad_u32lexical_cast: source type could not be interpreted as target") {}
};

template<typename T>
struct u32lexical_cast_type_map {
	typedef T to_type;
	typedef T from_type;

	static T map_from(T const &s) {
		return s;
	}

	static T map_to(T const &s) {
		return s;
	}
};

template<>
struct u32lexical_cast_type_map<u32string> {
	typedef std::string from_type;
	typedef u32string to_type;

	static from_type map_from(u32string const &s) {
		return make_u8string(s);
	}

	static to_type map_to(std::string const &s) {
		return make_u32string(s);
	}
};

template<typename charT, typename To, typename From>
To
u32lexical_cast(From const &f) {
	try {
		return 
			u32lexical_cast_type_map<To>::map_to(
				boost::lexical_cast<typename u32lexical_cast_type_map<To>::from_type>(
					u32lexical_cast_type_map<From>::map_from(f)));
	} catch (boost::bad_lexical_cast &e) {
		throw bad_u32lexical_cast();
	}
#if 0
	std::basic_stringstream<charT, std::char_traits<charT> > strm;
	To target;
	strm << f;
	if (!(strm >> target))
		throw bad_u32lexical_cast();

	return target;
#endif
}

#endif	/* !AFSTRING_H */
