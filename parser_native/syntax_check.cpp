#include <cstdlib>
#include <string>
#include <sstream>
#include <iostream>

#include "filter_evaluator.h"

int main( int argc, char** argv ) {
	stringbuf ss( ios::in | ios::out );
	
	// Fill the stringstream
	cin.get(ss,'\x04');
	
	string filter = ss.str();
	
	try {
		filter_evaluator f;
		f.evaluate(filter);
	} catch (AFPException excep) {
		cout << "PARSERR: " << excep.what() << endl;
		exit(0);
	}
	
	cout << "SUCCESS" << endl;
}
