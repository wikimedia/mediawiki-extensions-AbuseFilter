#ifndef AFFUNCTIONS_H
#define AFFUNCTIONS_H

#include "aftypes.h"
#include <map>
#include <vector>

AFPData af_length(std::vector<AFPData> const &args);
AFPData af_lcase(std::vector<AFPData> const &args);
AFPData af_ccnorm(std::vector<AFPData> const &args);
AFPData af_rmdoubles(std::vector<AFPData> const &args);
AFPData af_specialratio(std::vector<AFPData> const &args);
AFPData af_rmspecials(std::vector<AFPData> const &args);
AFPData af_norm(std::vector<AFPData> const &args);
AFPData af_count(std::vector<AFPData> const &args);

map<int,int> const &getEquivSet();
int next_utf8_char(std::string::const_iterator & p, std::string::const_iterator & charStart, std::string::const_iterator end);
string codepointToUtf8( int codepoint );
string confusable_character_normalise(std::string const &orig);
vector<AFPData> makeFuncArgList( AFPData arg );
AFPData callFunction(string const &name, AFPData arg);
string rmdoubles(string const &orig);
string rmspecials(string const &orig);
std::size_t utf8_strlen(std::string const &s);
std::string utf8_tolower(std::string const &s);

#endif	/* !AFFUNCTIONS_H */
