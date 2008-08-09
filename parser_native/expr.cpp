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

#include	<iostream>

#include	"parser.h"
#include	"afstring.h"

template<typename charT>
afp::basic_datum<charT> 
f_add(std::vector<afp::basic_datum<charT> > const &args)
{
	return args[0] + args[1];
}

template<typename charT>
afp::basic_datum<charT> 
f_norm(std::vector<afp::basic_datum<charT> > const &args)
{
	return args[0];
}

template<typename charT>
afp::basic_datum<charT> 
f_length(std::vector<afp::basic_datum<charT> > const &args)
{
	return afp::basic_datum<charT>::from_int(args[0].toString().size());
}
int
main(int argc, char **argv)
{
	if (argc != 2) {
		std::cerr << boost::format("usage: %s <expr>\n")
				% argv[0];
		return 1;
	}

	afp::u32expressor e;

	e.add_variable(make_u32string("ONE"), afp::u32datum::from_int(1));
	e.add_variable(make_u32string("TWO"), afp::u32datum::from_int(2));
	e.add_variable(make_u32string("THREE"), afp::u32datum::from_int(3));
	e.add_function(make_u32string("add"), f_add<UChar32>);
	e.add_function(make_u32string("norm"), f_norm<UChar32>);
	e.add_function(make_u32string("length"), f_length<UChar32>);

	try {
		std::cout << make_u8string(e.evaluate(make_u32string(argv[1])).toString()) << '\n';
	} catch (std::exception &e) {
		std::cout << "parsing failed: " << e.what() << '\n';
	}
}
