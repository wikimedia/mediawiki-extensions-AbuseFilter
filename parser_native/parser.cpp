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

namespace px = phoenix;

namespace afp {

struct parse_error : std::runtime_error {
	parse_error(char const *what) : std::runtime_error(what) {}
};

struct parser_closure : boost::spirit::closure<parser_closure, datum>
{
	member1 val;
};

namespace {

datum f_in(datum const &a, datum const &b)
{
	std::string sa = a, sb = b;
	return datum(std::search(sb.begin(), sb.end(), sa.begin(), sa.end()) != sb.end());
}

datum
f_ternary(datum const &v, datum const &iftrue, datum const &iffalse)
{
	return (bool)v ? iftrue : iffalse;
}

}

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

struct parser_grammar : public grammar<parser_grammar, parser_closure::context_t>
{
	symbols<datum> variables;
	symbols<boost::function<datum (std::vector<datum>)> > functions;

	void add_variable(std::string const &name, datum const &value) {
		variables.add(name.c_str(), value);
	}

	void add_function(std::string const &name, boost::function<datum (std::vector<datum>)> func) {
		functions.add(name.c_str(), func);
	}

	template<typename ScannerT>
	struct definition
	{
		typedef rule<ScannerT, parser_closure::context_t> rule_t;

		parser_grammar const &self_;
 
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
			value = 
				  real_p[value.val = arg1]
				| int_p[value.val = arg1]
				| confix_p('"', *c_escape_ch_p, '"')[
					value.val = construct_<std::string>(arg1 + 1, arg2 - 1)
				]
				;

			/* a sequence of uppercase letters is a variable */
			variable = 
				  self.variables[variable.val = arg1]
				| (+upper_p)[variable.val = ""]
				;

			/* func(value) */
			function = 
				  (
					   self.functions[function.func = arg1]
					>> '('
					>> ( tern_expr[push_back(function.args, arg1)] % ',' )
					>> ')'
				  ) [function.val = call_function(function.func, function.args)]
				;

			basic =
				  ( '(' >> tern_expr[basic.val = arg1] >> ')' )
				| ch_p('!') >> tern_expr[basic.val = !arg1] 
				| variable[basic.val = arg1]
				| function[basic.val = arg1]
				| value[basic.val = arg1]
				;

			in_expr = 
				  basic[in_expr.val = arg1]
				>> *(
					  "in" >> basic[in_expr.val = bind(&f_in)(in_expr.val, arg1)]
				    )
				;


			mult_expr =
				  in_expr[mult_expr.val = arg1]
				>> *( 
					  '*' >> in_expr[mult_expr.val *= arg1] 
					| '/' >> in_expr[mult_expr.val /= arg1] 
					| '%' >> in_expr[mult_expr.val %= arg1] 
					| "**" >> in_expr[mult_expr.val = bind(&afp::pow)(mult_expr.val,arg1)]
				    )
				;

			plus_expr =
				  mult_expr[plus_expr.val = arg1]
				>> *( 
					  '+' >> mult_expr[plus_expr.val += arg1] 
					| '-' >> mult_expr[plus_expr.val -= arg1] 
				    )
				;

			eq_expr =
				  plus_expr[eq_expr.val = arg1]
				>> *( 
					  "<"  >> plus_expr[eq_expr.val = eq_expr.val < arg1]
					| ">"  >> plus_expr[eq_expr.val = eq_expr.val > arg1]
					| "<=" >> plus_expr[eq_expr.val = eq_expr.val <= arg1]
					| ">=" >> plus_expr[eq_expr.val = eq_expr.val >= arg1]
				    )
				;

			eq2_expr =
				  eq_expr[eq2_expr.val = arg1]
				>> *( 
					  "=="  >> eq_expr[eq2_expr.val = eq2_expr.val == arg1]
					| "!="  >> eq_expr[eq2_expr.val = eq2_expr.val != arg1]
					| "===" >> eq_expr[eq2_expr.val = 
							bind(&datum::compare_with_type)(eq2_expr.val, arg1)]
					| "!==" >> eq_expr[eq2_expr.val = 
							!bind(&datum::compare_with_type)(eq2_expr.val, arg1)]
				    )
				;

			bool_expr =
				  eq2_expr[bool_expr.val = arg1]
				>> *( 
					  '&' >> eq2_expr[bool_expr.val = bool_expr.val && arg1]
					| '|' >> eq2_expr[bool_expr.val = bool_expr.val || arg1]
					| '^' >> eq2_expr[bool_expr.val = 
							((bool_expr.val || arg1)
							  && !(bool_expr.val && arg1)) ]
				    )
				;

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

			expr = tern_expr[self.val = arg1];
		}

		rule_t const &start() const {
			return expr;
		}

		rule_t value, variable, basic, bool_expr,
		       eq_expr, eq2_expr, mult_expr, plus_expr, in_expr, expr;
		rule<ScannerT, function_closure::context_t> function;
		rule<ScannerT, ternary_closure::context_t> tern_expr;
	};
};

expressor::expressor()
	: grammar_(new parser_grammar)
{
}

expressor::~expressor()
{
	delete grammar_;
}

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
