#include "aftypes.h"
#include <vector>
#include "afutils.h"

#ifndef PARSER
#define PARSER
vector<AFPToken> af_parse( string code );

bool isDigitOrDot( char chr );
bool isValidIdSymbol( char chr );

vector<string> getValidOps();
vector<string> getKeywords();

bool isKeyword( string id );
bool isValidOp( string op );
#endif
