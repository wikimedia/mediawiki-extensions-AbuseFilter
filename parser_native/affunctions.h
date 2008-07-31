#include "aftypes.h"
#include <map>
#include <vector>

typedef AFPData(*AFPFunction)(vector<AFPData>);

extern map<string,AFPFunction> af_functions;

void af_registerfunction( string name, AFPFunction method );
void registerBuiltinFunctions();
AFPData callFunction( string name, vector<AFPData> args );
bool isFunction( string name );
map<int,int> getEquivSet();
int next_utf8_char(std::string::const_iterator & p, std::string::const_iterator & charStart, std::string::const_iterator end);
string codepointToUtf8( int codepoint );
string confusable_character_normalise( string orig );
vector<AFPData> makeFuncArgList( AFPData arg );
AFPData callFunction( string name, AFPData arg );
string rmdoubles( string orig );
string rmspecials( string orig );
