#include "filter_evaluator.h"
#include "affunctions.h"

int main( int argc, char** argv ) {
	afp::filter_evaluator f;

	bool result = false;
	
	for(int i=0;i<=100;i++) {
		try {
			f.add_variable("foo", afp::datum("love"));
			result = f.evaluate( "specialratio('foo;') == 0.25" );
		} catch (afp::exception* excep) {
			printf( "Exception: %s\n", excep->what() );
		}
	}
	
	if (result) {
		printf("Success!\n");
	} else {
		printf("OH NOES!\n");
	}
}
