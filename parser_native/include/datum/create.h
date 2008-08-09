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

#ifndef DATUM_CREATE_H
#define DATUM_CREATE_H

namespace afp {

template<typename charT, typename T>
struct create_datum;

template<typename charT>
struct create_datum<charT, long int> {
	static basic_datum<charT> create(long int v) {
		return basic_datum<charT>::from_int(v);
	}
};

template<typename charT>
struct create_datum<charT, double> {
	static basic_datum<charT> create(double v) {
		return basic_datum<charT>::from_double(v);
	}
};

template<typename charT>
struct create_datum<charT, std::string> {
	static basic_datum<charT> create(std::basic_string<charT> const &v) {
		return basic_datum<charT>::from_string(v);
	}
};

}

#endif	/* !DATUM_CREATE_H */
