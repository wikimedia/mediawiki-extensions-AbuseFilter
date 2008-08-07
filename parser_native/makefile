include makefile.config

CXX		= g++
CXXFLAGS	= -O3 -W -Wall

CPPFLAGS	= $(EXTRA_CPPFLAGS)
LDFLAGS		= $(EXTRA_LDFLAGS)

LIBS		= -lboost_regex$(BOOST_TAG) -licuuc -licui18n -licudata -licui18n

af_expr_objs = afeval.o affunctions.o afparser.o aftypes.o afutils.o eval.o
af_parser_objs = afeval.o affunctions.o afparser.o aftypes.o afutils.o main.o
check_objs = afeval.o affunctions.o afparser.o aftypes.o afutils.o check.o
syntax_check_objs = afeval.o affunctions.o afparser.o aftypes.o afutils.o syntax_check.o

progs = check af_parser syntax_check af_expr

all: $(progs)

af_expr: $(af_expr_objs)
	$(CXX) $(CXXFLAGS) -o $@ $(af_expr_objs) $(LDFLAGS) $(LIBS)
af_parser: $(af_parser_objs)
	$(CXX) $(CXXFLAGS) -o $@ $(af_parser_objs) $(LDFLAGS) $(LIBS)
check: $(check_objs)
	$(CXX) $(CXXFLAGS) -o $@ $(check_objs) $(LDFLAGS) $(LIBS)
syntax_check: $(syntax_check_objs)
	$(CXX) $(CXXFLAGS) -o $@ $(syntax_check_objs) $(LDFLAGS) $(LIBS)

.cpp.o:
	$(CXX) $(CPPFLAGS) $(CXXFLAGS) -c $<
	
clean:
	rm -f *.o $(progs)

clean-final:
	rm -f $(progs)

.SUFFIXES: .cpp .o
