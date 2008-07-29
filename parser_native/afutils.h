#include "aftypes.h"
#include <vector>

#ifndef UTILS
#define UTILS

AFPData af_boolInvert( AFPData value );
AFPData af_pow( AFPData base, AFPData exponent );
AFPData af_keywordIn( AFPData needle, AFPData haystack );
AFPData af_unaryMinus( AFPData data );
AFPData af_boolOp( AFPData a, AFPData b, string op );
AFPData af_compareOp( AFPData a, AFPData b, string op );
AFPData af_mulRel( AFPData a, AFPData b, string op );
AFPData af_sum( AFPData a, AFPData b );
AFPData af_sub( AFPData a, AFPData b );
AFPData af_keyword( string keyword, AFPData a, AFPData b );
bool isInVector( string needle, vector<string> haystack );

#endif
