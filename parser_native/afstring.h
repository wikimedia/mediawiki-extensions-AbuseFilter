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

typedef std::basic_string<UChar> u32string;
typedef std::basic_istream<UChar> u32istream;
typedef std::basic_ostream<UChar> u32ostream;
typedef std::basic_iostream<UChar> u32iostream;
typedef std::basic_istringstream<UChar> u32istringstream;
typedef std::basic_ostringstream<UChar> u32ostringstream;
typedef std::basic_stringstream<UChar> u32stringstream;

#endif	/* !AFSTRING_H */
