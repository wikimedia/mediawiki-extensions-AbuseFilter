#include <cstdlib>
#include <iostream>
#include <string>
#include <sstream>
#include <map>

#include "filter_evaluator.h"
#include "request.h"

int main( int argc, char** argv ) {
	request r;	
	string result;
	
	try {
		if (!r.load(std::cin))
			return 1;
			
		result = r.evaluate();
	} catch (AFPException excep) {
		cout << "EXCEPTION: " << excep.what() << endl;
		cerr << "EXCEPTION: " << excep.what() << endl;
	}
	
	cout << result << "\0";
}
