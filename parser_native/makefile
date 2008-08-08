include makefile.config

CPPFLAGS	= $(EXTRA_CPPFLAGS)
LDFLAGS		= $(EXTRA_LDFLAGS)

LIBS		= -lboost_regex$(BOOST_TAG) -licuuc -licui18n -licudata

expr: CPPFLAGS+=-DTEST_PARSER

af_expr_objs = \
	af_expr-affunctions.o		\
	af_expr-aftypes.o		\
	af_expr-parser.o		\
	af_expr-filter_evaluator.o	\
	af_expr-eval.o			\
	af_expr-utf8.o			\
	af_expr-equiv.o			\
	af_expr-request.o

af_parser_objs = \
	af_parser-affunctions.o		\
	af_parser-aftypes.o		\
	af_parser-main.o		\
	af_parser-parser.o		\
	af_parser-request.o		\
	af_parser-utf8.o		\
	af_parser-equiv.o		\
	af_parser-filter_evaluator.o

check_objs = \
	check-affunctions.o	\
	check-aftypes.o		\
	check-check.o		\
	check-parser.o		\
	check-utf8.o		\
	check-equiv.o		\
	check-filter_evaluator.o

syntax_check_objs = \
	syntax_check-affunctions.o	\
	syntax_check-aftypes.o		\
	syntax_check-filter_evaluator.o	\
	syntax_check-parser.o		\
	syntax_check-utf8.o		\
	syntax_check-equiv.o		\
	syntax_check-syntax_check.o

expr_objs = \
	expr-aftypes.o	\
	expr-parser.o

progs = check af_parser syntax_check af_expr expr

all: $(progs)

af_expr: $(af_expr_objs)
	$(CXX) $(CXXFLAGS) -o $@ $(af_expr_objs) $(LDFLAGS) $(LIBS)
af_parser: $(af_parser_objs)
	$(CXX) $(CXXFLAGS) -o $@ $(af_parser_objs) $(LDFLAGS) $(LIBS)
check: $(check_objs)
	$(CXX) $(CXXFLAGS) -o $@ $(check_objs) $(LDFLAGS) $(LIBS)
syntax_check: $(syntax_check_objs)
	$(CXX) $(CXXFLAGS) -o $@ $(syntax_check_objs) $(LDFLAGS) $(LIBS)
expr: $(expr_objs)
	$(CXX) $(CXXFLAGS) -o $@ $(expr_objs) $(LDFLAGS) $(LIBS)

$(af_expr_objs): af_expr-%.o: %.cpp
	$(CXX) $(CPPFLAGS) $(CXXFLAGS) -o $@ -c $(@:af_expr-%.o=%.cpp)

$(af_parser_objs): af_parser-%.o: %.cpp
	$(CXX) $(CPPFLAGS) $(CXXFLAGS) -o $@ -c $(@:af_parser-%.o=%.cpp)

$(check_objs): check-%.o: %.cpp
	$(CXX) $(CPPFLAGS) $(CXXFLAGS) -o $@ -c $(@:check-%.o=%.cpp)

$(syntax_check_objs): syntax_check-%.o: %.cpp
	$(CXX) $(CPPFLAGS) $(CXXFLAGS) -o $@ -c $(@:syntax_check-%.o=%.cpp)

$(expr_objs): expr-%.o: %.cpp
	$(CXX) $(CPPFLAGS) $(CXXFLAGS) -o $@ -c $(@:expr-%.o=%.cpp)

clean:
	rm -f *.o $(progs)

clean-final:
	rm -f $(progs)

.SUFFIXES: .cpp .o
