#include <cstdlib>
#include <string>
#include <sstream>
#include <iostream>

#include "filter_evaluator.h"

int main(int argc, char** argv)
{
	std::stringbuf ss( std::ios::in | std::ios::out );
	
	// Fill the stringstream
	std::cin.get(ss,'\x04');
	
	std::string filter = ss.str();
	
	try {
		afp::filter_evaluator f;
		f.evaluate(filter);
	} catch (afp::exception &excep) {
		std::cout << "PARSERR: " << excep.what() << std::endl;
		std::exit(0);
	}
	
	std::cout << "SUCCESS" << std::endl;
}
