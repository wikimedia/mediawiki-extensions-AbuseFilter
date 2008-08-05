#include "afeval.h"
#include "affunctions.h"
#include <libxml++/libxml++.h>
#include <iostream>
#include <string>
#include <sstream>
#include <map>

string filter;
map<string,AFPData> vars;

bool loadRequest();

int main( int argc, char** argv ) {
	FilterEvaluator e;
	registerBuiltinFunctions();
	
	string result;
	
	try {
		e.reset();
		if (!loadRequest())
			exit(-1);
			
		e.setVars( vars );
		result = e.evaluateExpression( filter );
	} catch (AFPException excep) {
		cout << "EXCEPTION: " << excep.what() << endl;
		cerr << "EXCEPTION: " << excep.what() << endl;
	}
	
	cout << result << "\0";
}

// Protocol:
// code NULL <key> NULL <value> NULL ... <value> NULL NULL

bool loadRequest() {
	stringbuf codesb(ios::out | ios::in);
	
	// Load the code
	cin.get( codesb, '\0' );
	cin.get();
	filter = codesb.str();
	
	cerr << "Got code " << filter << endl;
	
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
		
		cerr << "Got var " << keysb.str() << "=" << valsb.str() << endl;
		
		vars[keysb.str()] = AFPData( valsb.str() );
	}
	
	return true;
}
