#include <cstdlib>
#include <iostream>
#include <string>

#include "filter_evaluator.h"
#include "request.h"

int main(int argc, char** argv)
{
	afp::request r;	
	std::string result;
	
	try {
		if (!r.load(std::cin))
			return 1;
			
		result = r.evaluate();
	} catch (afp::exception &excep) {
		std::cout << "EXCEPTION: " << excep.what() << std::endl;
		std::cerr << "EXCEPTION: " << excep.what() << std::endl;
	}
	
	std::cout << result << "\0";
}
