#include "afeval.h"
#include "affunctions.h"

int main( int argc, char** argv ) {
	FilterEvaluator e;

	e.reset();
	bool result = false;
	
	registerBuiltinFunctions();
	
	for(int i=0;i<=1;i++) {
	try {
		e.setVar( "foo", AFPData(string("love")) );
		result = e.evaluateFilter( "specialratio('foo;') == 0.25" );
	} catch (AFPException* excep) {
		printf( "Exception: %s\n", excep->what() );
	}
	}
	
	if (result) {
		printf("Success!\n");
	} else {
		printf("OH NOES!\n");
	}
}
