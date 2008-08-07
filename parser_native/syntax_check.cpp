#include "afeval.h"
#include <cstdlib>
#include <string>
#include <sstream>
#include <iostream>

int main( int argc, char** argv ) {
	stringbuf ss( ios::in | ios::out );
	
	// Fill the stringstream
	cin.get(ss,'\x04');
	
	string filter = ss.str();
	
	try {
		FilterEvaluator e;
		e.evaluateFilter( filter );
	} catch (AFPException excep) {
		cout << "PARSERR: " << excep.what() << endl;
		exit(0);
	}
	
	cout << "SUCCESS" << endl;
}
