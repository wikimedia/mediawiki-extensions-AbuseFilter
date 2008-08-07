#include "afeval.h"
#include "affunctions.h"
#include <iostream>
#include <string>
#include <sstream>
#include <fstream>
#include <map>

string filter;
map<string,AFPData> vars;

bool loadRequest();
void clearNulls();

int main( int argc, char** argv ) {
	FilterEvaluator e;
	registerBuiltinFunctions();
	
	while (true) {
		bool result;
		
		try {
			// Reset
			e.reset();
			vars.clear();
			filter = "";
			
			if (!loadRequest())
				continue;
				
			e.setVars( vars );
			result = e.evaluateFilter( filter );
		} catch (AFPException excep) {
			cout << "EXCEPTION: " << excep.what() << endl;
			cerr << "EXCEPTION: " << excep.what() << endl;
		}
		
		cout << ( result ? "MATCH\n" : "NOMATCH\n" );
	}
}

// Protocol:
// code NULL <key> NULL <value> NULL ... <value> NULL NULL

bool loadRequest() {
	stringbuf codesb(ios::out | ios::in);
	
	// Load the code
	cin.get( codesb, '\0' );
	cin.get();
	filter = codesb.str();
	
	while (true) {
		stringbuf keysb(ios::out | ios::in);
		stringbuf valsb(ios::out | ios::in);
		
		// Double NULL = end
		if (cin.peek() == 0) {
			cin.get();
			break;
		} else if (cin.peek() == -1) {
			exit(-1);
		}
	
		cin.get( keysb, '\0' );
		cin.get();
		
		if (cin.peek() == 0) {
			cin.get();
			// Leave blank.
		} else {
			cin.get( valsb, '\0' );
			cin.get();
		}
		
		vars[keysb.str()] = AFPData( valsb.str() );
	}
	
	return true;
}
