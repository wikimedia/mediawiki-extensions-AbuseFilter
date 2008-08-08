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
