#include <cstdlib>
#include <iostream>
#include <iterator>
#include <string>
#include <sstream>
#include <fstream>
#include <map>
#include <cerrno>
#include <cstring>

#include <boost/format.hpp>
#include <boost/next_prior.hpp>

#include "request.h"

int main( int argc, char** argv ) {
	while (true) {
		afp::request r;
		bool result = false;
		
		try {
			if (argv[1]) {
				std::ifstream inf(argv[1]);
				if (!inf) {
					std::cerr << boost::format("%s: %s: %s\n")
						% argv[0] % argv[1] % std::strerror(errno);
					return 0;
				}

				if (!r.load(inf))
					return 0;
			} else {
				if (!r.load(std::cin))
					return 0;
			}	

			result = r.evaluate();
		} catch (afp::exception &excep) {
			std::cerr << "EXCEPTION: " << excep.what() << std::endl;
		}
		
		std::cout << ( result ? "MATCH\n" : "NOMATCH\n" );
	}
}

