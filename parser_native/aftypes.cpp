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
