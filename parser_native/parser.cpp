#include	<stdexcept>
#include	<iostream>

#include	<boost/spirit.hpp>
#include	<boost/spirit/phoenix.hpp>
#include	<boost/spirit/phoenix/composite.hpp>
#include	<boost/spirit/phoenix/functions.hpp>
#include	<boost/spirit/phoenix/operators.hpp>
#include	<boost/function.hpp>
#include	<boost/noncopyable.hpp>
#include	<boost/format.hpp>

#include	"aftypes.h"
#include	"parser.h"

using namespace boost::spirit;
using namespace phoenix;

/*
 *                    ABUSEFILTER EXPRESSION PARSER
 *                    =============================
 *
 * This is the basic expression parser.  It doesn't contain any AF logic
 * itself, but rather presents an interface for the user to add custom
 * functions and variables.
 *
 * The interface to the parser is the 'expressor' class.  Use it like this:
 *
 *   expressor e;
 *   e.add_variable("ONE", 1);
 *   e.evaluate("ONE + 2");     -- returns 3 
 * 
 * Custom functions should have the following prototype:
 *
 *   datum (std::vector<afp::datum) const &args);
 *
 * Functions must return a value; they cannot be void.  The arguments passed to
 * the function are stored in the 'args' array in left-to-right order.
 *
 * The parser implements a C-like grammar with some differences.  The following
 * operators are available:
 *
 *   a & b	true if a and b are both true
 *   a | b	true if either a or b is true
 *   a ^ b	true if either a or b is true, but not if both are true
 *   a + b	arithmetic
 *   a - b
 *   a * b
 *   a / b
 *   a % b
 *   a ** b	power-of (a^b)
 *   a in b	true if the string "b" contains the substring "a"
 *   !a		true if a is false
 *   (a)	same value as a
 *   a ? b : c	if a is true, returns the value of b, otherwise c
 *   a == b	comparison operators
 *   a != b
 *   a < b
 *   a <= b
 *   a > b
 *   a >= b
 *   a === b	returns true if a==b and both are the same type
 *   a !== b	return true if a != b or they are different types
 *
 * The parser uses afp::datum for its variables.  This means it supports
 * strings, ints and floats, with automatic conversion between types.
 */

namespace px = phoenix;

namespace afp {

struct parse_error : std::runtime_error {
	parse_error(char const *what) : std::runtime_error(what) {}
};

/*
 * The parser stores the result of each grammar rule in a closure.  Most rules
 * use the parser_closure, which simply stores a single value.
 */
struct parser_closure : boost::spirit::closure<parser_closure, datum>
{
	member1 val;
};

namespace {

datum
f_in(datum const &a, datum const &b)
{
	std::string sa = a, sb = b;
	return datum(std::search(sb.begin(), sb.end(), sa.begin(), sa.end()) != sb.end());
}

datum
f_ternary(datum const &v, datum const &iftrue, datum const &iffalse)
{
	return (bool)v ? iftrue : iffalse;
}

datum
f_int(std::vector<datum> const &args)
{
	if (args.size() != 1)
		throw parse_error("wrong number of arguments to int() (expected 1)");

	return datum::from_int(args[0].toInt());
}

datum
f_string(std::vector<datum> const &args)
{
	if (args.size() != 1)
		throw parse_error("wrong number of arguments to string() (expected 1)");

	return datum::from_string(args[0].toString());
}

datum
f_float(std::vector<datum> const &args)
{
	if (args.size() != 1)
		throw parse_error("wrong number of arguments to float() (expected 1)");

	return datum::from_double(args[0].toFloat());
}

}

/*
 * This is the closure types for functions.  'val' stores the final result of
 * the function call; func and args store the function object and the parsed
 * arguments.
 */
struct function_closure : boost::spirit::closure<
			  	function_closure,
				datum,
				boost::function<datum (std::vector<datum>)>,
				std::vector<datum> >
{
	member1 val;
	member2 func;
	member3 args;
};

/*
 * The closure for the ?: operator.  Parsed as expr ? iftrue : iffalse.
 */
struct ternary_closure : boost::spirit::closure<
			 ternary_closure,
			 datum,
			 datum,
			 datum>
{
	member1 val;
	member2 iftrue;
	member3 iffalse;
};

/*
 * The grammar itself.
 */
struct parser_grammar : public grammar<parser_grammar, parser_closure::context_t>
{
	/* User-defined variables. */
	symbols<datum> variables;

	void add_variable(std::string const &name, datum const &value) {
		variables.add(name.c_str(), value);
	}

	/* User-defined functions. */
	symbols<boost::function<datum (std::vector<datum>)> > functions;

	void add_function(std::string const &name, boost::function<datum (std::vector<datum>)> func) {
		functions.add(name.c_str(), func);
	}

	template<typename ScannerT>
	struct definition
	{
		typedef rule<ScannerT, parser_closure::context_t> rule_t;

		parser_grammar const &self_;
 
		/*
		 * A phoenix actor to append its argument to a container.
		 */
		struct push_back_impl {
			template<typename C, typename I>
			struct result {
				typedef void type;
			};

			template<typename C, typename I>
			void operator() (C &c, I const &i) const {
				c.push_back(i);
			}
		};

		phoenix::function<push_back_impl> const push_back;

		/*
		 * A phoenix actor to call a user-defined function given the
		 * function object and arguments.
		 */
		struct call_function_impl {
			template<typename F, typename A>
			struct result {
				typedef datum type;
			};

			template<typename F, typename A>
			datum operator() (F const &func, A const &args) const {
				return func(args);
			}
		};

		phoenix::function<call_function_impl> const call_function;

		definition(parser_grammar const &self) 
		: self_(self)
		, push_back(push_back_impl())
	        , call_function(call_function_impl())
		{
			/*
			 * A literal value.  Either a string, a floating
			 * pointer number or an integer.
			 */
			value = 
				  strict_real_p[value.val = bind(&datum::from_double)(arg1)]
				| int_p[value.val = bind(&datum::from_int)(arg1)]
				| confix_p('"', *c_escape_ch_p, '"')[
					value.val = bind(&datum::from_string)(construct_<std::string>(arg1 + 1, arg2 - 1))
				]
				;

			/*
			 * A variable.  If the variable is found in the
			 * user-supplied variable list, we use that.
			 * Otherwise, unknown variables (containing uppercase
			 * letters and underscore only) are returned as the
			 * empty string.
			 */
			variable = 
				  self.variables[variable.val = arg1]
				| (+ (upper_p | '_') )[variable.val = ""]
				;

			/*
			 * A function call: func([arg[, arg...]]).
			 */
			function = 
				  (
					   self.functions[function.func = arg1]
					>> '('
					>> ( tern_expr[push_back(function.args, arg1)] % ',' )
					>> ')'
				  ) [function.val = call_function(function.func, function.args)]
				;

			/*
			 * A basic atomic value.  Either a variable, function
			 * or literal, or a negated expression !a, or a
			 * parenthesised expression (a).
			 */
			basic =
				  ( '(' >> tern_expr[basic.val = arg1] >> ')' )
				| ch_p('!') >> tern_expr[basic.val = !arg1] 
				| variable[basic.val = arg1]
				| function[basic.val = arg1]
				| value[basic.val = arg1]
				;

			/*
			 * "a in b" operator
			 */
			in_expr = 
				  basic[in_expr.val = arg1]
				>> *(
					  "in" >> basic[in_expr.val = bind(&f_in)(in_expr.val, arg1)]
				    )
				;

			/*
			 * power-of.  This is right-associative. 
			 */
			pow_expr =
				   in_expr[pow_expr.val = arg1]
				>> !(
					"**" >> pow_expr[pow_expr.val = bind(&afp::pow)(pow_expr.val, arg1)]
				    )
				;

			/*
			 * Multiplication and operators with the same
			 * precedence.
			 */
			mult_expr =
				  pow_expr[mult_expr.val = arg1]
				>> *( 
					  '*' >> pow_expr[mult_expr.val *= arg1] 
					| '/' >> pow_expr[mult_expr.val /= arg1] 
					| '%' >> pow_expr[mult_expr.val %= arg1] 
				    )
				;

			/*
			 * Additional and operators with the same precedence.
			 */
			plus_expr =
				  mult_expr[plus_expr.val = arg1]
				>> *( 
					  '+' >> mult_expr[plus_expr.val += arg1] 
					| '-' >> mult_expr[plus_expr.val -= arg1] 
				    )
				;

			/*
			 * Ordinal comparisons and operators with the same
			 * precedence.
			 */
			ord_expr  =
				  plus_expr[ord_expr.val = arg1]
				>> *( 
					  "<"  >> plus_expr[ord_expr.val = ord_expr.val < arg1]
					| ">"  >> plus_expr[ord_expr.val = ord_expr.val > arg1]
					| "<=" >> plus_expr[ord_expr.val = ord_expr.val <= arg1]
					| ">=" >> plus_expr[ord_expr.val = ord_expr.val >= arg1]
				    )
				;

			/*
			 * Equality comparisons.
			 */
			eq_expr =
				  ord_expr[eq_expr.val = arg1]
				>> *( 
					  "=="  >> eq_expr[eq_expr.val = eq_expr.val == arg1]
					| "!="  >> eq_expr[eq_expr.val = eq_expr.val != arg1]
					| "===" >> eq_expr[eq_expr.val = 
							bind(&datum::compare_with_type)(eq_expr.val, arg1)]
					| "!==" >> eq_expr[eq_expr.val = 
							!bind(&datum::compare_with_type)(eq_expr.val, arg1)]
				    )
				;

			/*
			 * Boolean expressions.
			 */
			bool_expr =
				  eq_expr[bool_expr.val = arg1]
				>> *( 
					  '&' >> eq_expr[bool_expr.val = bool_expr.val && arg1]
					| '|' >> eq_expr[bool_expr.val = bool_expr.val || arg1]
					| '^' >> eq_expr[bool_expr.val = 
							((bool_expr.val || arg1)
							  && !(bool_expr.val && arg1)) ]
				    )
				;

			/*
			 * The ternary operator.  Notice this is
			 * right-associative: a ? b ? c : d : e
			 * is supported.
			 */
			tern_expr =
				   bool_expr[tern_expr.val = arg1]
				>> !(
					(
						   "?" >> tern_expr[tern_expr.iftrue = arg1]
						>> ":" >> tern_expr[tern_expr.iffalse = arg1]
					)[tern_expr.val =
						bind(f_ternary)(tern_expr.val, tern_expr.iftrue, tern_expr.iffalse)]
				   )
				;

			/*
			 * The root expression type.
			 */
			expr = tern_expr[self.val = arg1];
		}

		rule_t const &start() const {
			return expr;
		}

		rule_t value, variable, basic, bool_expr,
		       ord_expr, eq_expr, pow_expr, mult_expr, plus_expr, in_expr, expr;
		rule<ScannerT, function_closure::context_t> function;
		rule<ScannerT, ternary_closure::context_t> tern_expr;
	};
};

expressor::expressor()
	: grammar_(new parser_grammar)
{
	/*
	 * We provide a couple of standard variables everyone wants.
	 */
	add_variable("true", afp::datum(true));
	add_variable("false", afp::datum(false));

	/*
	 * The cast functions.
	 */
	add_function("int", &f_int);
	add_function("string", &f_string);
	add_function("float", &f_float);
}

expressor::~expressor()
{
	delete grammar_;
}

/*
 * The user interface to evaluate an expression.  It returns the result, or
 * throws an exception if an error occurs.
 */
datum
expressor::evaluate(std::string const &filter) const
{
	datum ret;
	parse_info<std::string::const_iterator> info = 
		parse(filter.begin(), filter.end(), (*grammar_)[var(ret) = arg1],
				comment_p("/*", "*/") | chset<>("\n\t "));
	if (info.full) {
		return ret;
	} else {
		std::cerr << "stopped at: [" << std::string(info.stop, filter.end()) << "]\n";
		throw parse_error("parsing failed");
	}
}

void
expressor::add_variable(std::string const &name, datum value)
{
	grammar_->add_variable(name, value);
}

void
expressor::add_function(std::string const &name, func_t value)
{
	grammar_->add_function(name, value);
}

} // namespace afp

#ifdef TEST_PARSER
afp::datum 
f_add(std::vector<afp::datum> const &args)
{
	return args[0] + args[1];
}

afp::datum 
f_norm(std::vector<afp::datum> const &args)
{
	return args[0];
}

int
main(int argc, char **argv)
{
	if (argc != 2) {
		std::cerr << boost::format("usage: %s <expr>\n")
				% argv[0];
		return 1;
	}

	afp::expressor e;
	e.add_variable("ONE", 1);
	e.add_variable("TWO", 2);
	e.add_variable("THREE", 3);
	e.add_function("add", f_add);
	e.add_function("norm", f_norm);

	try {
		std::cout << e.evaluate(argv[1]) << '\n';
	} catch (std::exception &e) {
		std::cout << "parsing failed: " << e.what() << '\n';
	}
}
#endif
