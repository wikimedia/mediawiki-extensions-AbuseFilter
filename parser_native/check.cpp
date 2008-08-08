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

#include "filter_evaluator.h"
#include "affunctions.h"

int main( int argc, char** argv ) {
	afp::filter_evaluator f;

	bool result = false;
	
	for(int i=0;i<=100;i++) {
		try {
			f.add_variable("foo", afp::datum("love"));
			result = f.evaluate( "specialratio('foo;') == 0.25" );
		} catch (afp::exception* excep) {
			printf( "Exception: %s\n", excep->what() );
		}
	}
	
	if (result) {
		printf("Success!\n");
	} else {
		printf("OH NOES!\n");
	}
}
