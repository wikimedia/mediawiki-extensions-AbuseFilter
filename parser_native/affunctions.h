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

#ifndef AFFUNCTIONS_H
#define AFFUNCTIONS_H

#include <map>
#include <vector>

#include "aftypes.h"

namespace afp {

datum af_length		(std::vector<datum> const &args);
datum af_lcase		(std::vector<datum> const &args);
datum af_ccnorm		(std::vector<datum> const &args);
datum af_rmdoubles	(std::vector<datum> const &args);
datum af_specialratio	(std::vector<datum> const &args);
datum af_rmspecials	(std::vector<datum> const &args);
datum af_norm		(std::vector<datum> const &args);
datum af_count		(std::vector<datum> const &args);

std::string confusable_character_normalise(std::string const &orig);
std::string rmdoubles(std::string const &orig);
std::string rmspecials(std::string const &orig);

} // namespace afp

#endif	/* !AFFUNCTIONS_H */
