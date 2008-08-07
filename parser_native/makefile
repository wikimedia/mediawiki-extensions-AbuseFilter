all: check af_parser syntax_check af_expr

af_expr: afeval.o affunctions.o afparser.o aftypes.o afutils.o eval.o
	g++ -O3 -o af_expr afeval.o affunctions.o afparser.o aftypes.o afutils.o eval.o -lboost_regex -lxml++-2.6 -lxml2 -lglibmm-2.4 -lgobject-2.0 -lsigc-2.0 -lglib-2.0

af_parser: afeval.o affunctions.o afparser.o aftypes.o afutils.o main.o
	g++ -O3 -o af_parser afeval.o affunctions.o afparser.o aftypes.o afutils.o main.o -lboost_regex -lglibmm-2.4

check: afeval.o affunctions.o afparser.o aftypes.o afutils.o check.o
	g++ -O3 -o check -lboost_regex afeval.o affunctions.o afparser.o aftypes.o afutils.o check.o -lglibmm-2.4
	
syntax_check: afeval.o affunctions.o afparser.o aftypes.o afutils.o syntax_check.o
	g++ -O3 -o syntax_check afeval.o affunctions.o afparser.o aftypes.o afutils.o syntax_check.o -lboost_regex -lglibmm-2.4

.cpp.o:
	g++ -O3 -c $< -I/usr/include/libxml++-2.6 -I/usr/lib/libxml++-2.6/include -I/usr/include/libxml2 -I/usr/include/glibmm-2.4 -I/usr/lib/glibmm-2.4/include -I/usr/include/sigc++-2.0 -I/usr/lib/sigc++-2.0/include -I/usr/include/glib-2.0 -I/usr/lib/glib-2.0/include
	
clean:
	rm -f *.o check af_parser syntax_check

clean-final:
	rm -f check af_parser syntax_check

.SUFFIXES: .cpp .o
