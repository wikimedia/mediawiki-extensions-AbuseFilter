#ifndef AFPARSER_H
#define AFPARSER_H

#include "aftypes.h"
#include <vector>
#include "afutils.h"

vector<AFPToken> af_parse( string code );

bool isDigitOrDot( char chr );
bool isValidIdSymbol( char chr );

vector<string> getValidOps();
vector<string> getKeywords();

bool isKeyword( string id );
bool isValidOp( string op );

#endif	/* !AFPARSER_H */
