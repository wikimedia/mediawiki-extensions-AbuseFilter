/*
 * Copyright (c) 2008 Andrew Garrett.
 * Copyright (c) 2008 River Tarnell <river@wikimedia.org>
 * Derived from public domain code contributed by Victor Vasiliev.
 *
 * Permission is granted to anyone to use this software for any purpose,
 * including commercial applications, and to alter it and redistribute it
 * freely. This software is provided 'as-is', without any express or
 * implied warranty.
 */

#ifndef EXPRESSOR_H
#define EXPRESSOR_H

#include	<string>
#include	<vector>
#include	<stdexcept>
#include	<iostream>

#include	<boost/noncopyable.hpp>
#include	<boost/function.hpp>
#include	<boost/spirit/core.hpp>
#include	<boost/spirit/utility/confix.hpp>
#include	<boost/spirit/utility/chset.hpp>
#include	<boost/spirit/tree/ast.hpp>
#include	<boost/spirit/tree/tree_to_xml.hpp>
#include	<boost/spirit/symbols.hpp>
#include	<boost/spirit/utility/escape_char.hpp>
#include	<boost/function.hpp>
#include	<boost/noncopyable.hpp>
#include	<boost/format.hpp>
#include	<boost/regex/icu.hpp>

#include	<unicode/uchar.h>

#include	"aftypes.h"
#include	"afstring.h"
#include	"affunctions.h"
#include	"fray.h"
#include	"ast.h"

namespace afp {

template<typename T> struct parser_grammar;

template<typename charT>
struct basic_expressor : boost::noncopyable {
	typedef boost::function<basic_datum<charT> (std::vector<basic_datum<charT> >)> func_t;

	basic_expressor();
	~basic_expressor();

	basic_datum<charT> evaluate(basic_fray<charT> const &expr) const;
	void print_xml(std::ostream &strm, basic_fray<charT> const &expr) const;

	void add_variable(basic_fray<charT> const &name, basic_datum<charT> const &value);
	void add_function(basic_fray<charT> const &name, func_t value);

	void clear();
	void clear_functions();
	void clear_variables();

private:
	parser_grammar<charT> *grammar_;
};

typedef basic_expressor<char> expressor;
typedef basic_expressor<UChar32> u32expressor;

using namespace boost::spirit;

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

struct parse_error : std::runtime_error {
	parse_error(char const *what) : std::runtime_error(what) {}
};

/*
 * The grammar itself.
 */
template<typename charT>
struct parser_grammar : public grammar<parser_grammar<charT> >
{
	static const int id_value = 1;
	static const int id_variable = 2;
	static const int id_basic = 3;
	static const int id_bool_expr = 4;
	static const int id_ord_expr = 5;
	static const int id_eq_expr = 6;
	static const int id_pow_expr = 7;
	static const int id_mult_expr = 8;
	static const int id_plus_expr = 9;
	static const int id_in_expr = 10;
	static const int id_function = 12;
	static const int id_tern_expr = 13;
	static const int id_string = 14;

	/* User-defined variables. */
	symbols<basic_datum<charT>, charT > variables;

	void add_variable(basic_fray<charT> const &name, basic_datum<charT> const &value) {
		variables.add(name.c_str(), value);
	}

	/* User-defined functions. */
	symbols<boost::function<basic_datum<charT> (std::vector<basic_datum<charT> >)>, charT > functions;

	void add_function(
			basic_fray<charT> const &name, 
			boost::function<basic_datum<charT> (std::vector<basic_datum<charT> >)> func) {
		functions.add(name.c_str(), func);
	}

	symbols<int, charT> eq_opers, ord_opers, plus_opers, mult_opers, in_opers, bool_opers;

	parser_grammar() {
		eq_opers.add("=", 0);
		eq_opers.add("==", 0);
		eq_opers.add("===", 0);
		eq_opers.add("!=", 0);
		eq_opers.add("!==", 0);
		eq_opers.add("/=", 0);
		ord_opers.add("<", 0);
		ord_opers.add("<=", 0);
		ord_opers.add(">", 0);
		ord_opers.add(">=", 0);
		plus_opers.add("+", 0);
		plus_opers.add("-", 0);
		mult_opers.add("*", 0);
		mult_opers.add("/", 0);
		mult_opers.add("%", 0);
		bool_opers.add("&", 0);
		bool_opers.add("|", 0);
		bool_opers.add("^", 0);
		in_opers.add("in", 0);
		in_opers.add("contains", 0);
		in_opers.add("matches", 0);
		in_opers.add("like", 0);
		in_opers.add("rlike", 0);
		in_opers.add("regex", 0);
	}

	template<typename ScannerT>
	struct definition
	{
		parser_grammar const &self_;
 
		definition(parser_grammar const &self) 
		: self_(self)
		{
			/*
			 * A literal value.  Either a string, a floating
			 * pointer number or an integer.
			 */
			value = 
				  strict_real_p
				| as_lower_d[ leaf_node_d[
					  oct_p >> 'o'
					| hex_p >> 'x'
					| bin_p >> 'b'
					| int_p
				] ]
				| string
				;

			/*
			 * config_p can't be used here, because it will rewrite
			 * *(c_escape_ch_p[x]) into (*c_escape_ch_p)[x]
			 */
			string = inner_node_d[
					   '"'
					>> leaf_node_d[ *(lex_escape_ch_p - '"') ]
					>> '"'
				]
				;

			/*
			 * A variable.  If the variable is found in the
			 * user-supplied variable list, we use that.
			 * Otherwise, unknown variables (containing uppercase
			 * letters and underscore only) are returned as the
			 * empty string.
			 */
			variable = longest_d[
					  self.variables
					| leaf_node_d[ (+ (upper_p | '_') ) ]
				   ]
				;

			/*
			 * A function call: func([arg[, arg...]]).
			 */
			function = 
				  (
					   root_node_d[self.functions]
					>> inner_node_d[
						   '('
						>> ( tern_expr % discard_node_d[ch_p(',')] )
						>> ')'
					   ]
				  )
				;

			/*
			 * A basic atomic value.  Either a variable, function
			 * or literal, or a negated expression !a, or a
			 * parenthesised expression (a).
			 */
			basic =
				  value
				| variable
				| function
				| inner_node_d[ '(' >> tern_expr >> ')' ]
				| root_node_d[ch_p('!')] >> tern_expr
				| root_node_d[ch_p('+')] >> tern_expr 
				| root_node_d[ch_p('-')] >> tern_expr
				;

			/*
			 * "a in b" operator
			 */
			in_expr = 
				  basic
				>> *( root_node_d[ self.in_opers ] >> basic )
				;

			/*
			 * power-of.  This is right-associative. 
			 */
			pow_expr =
				   in_expr
				>> !( root_node_d[ str_p("**") ] >> pow_expr )
				;

			/*
			 * Multiplication and operators with the same
			 * precedence.
			 */
			mult_expr =
				   pow_expr
				>> *( root_node_d[ self.mult_opers ] >> pow_expr )
				;

			/*
			 * Additional and operators with the same precedence.
			 */
			plus_expr =
				   mult_expr
				>> *( root_node_d[ self.plus_opers ] >> mult_expr )
				;

			/*
			 * Ordinal comparisons and operators with the same
			 * precedence.
			 */
			ord_expr  =
				   plus_expr
				>> *( root_node_d[ self.ord_opers ] >> plus_expr )
				;

			/*
			 * Equality comparisons.
			 */
			eq_expr =
				   ord_expr
				>> *( root_node_d[ self.eq_opers ] >> ord_expr )
				;

			/*
			 * Boolean expressions.
			 */
			bool_expr =
				  eq_expr
				>> *( root_node_d[ self.bool_opers ] >> eq_expr )
				;

			/*
			 * The ternary operator.  Notice this is
			 * right-associative: a ? b ? c : d : e
			 * is supported.
			 */
			tern_expr =
				   bool_expr
				>> !(
					   root_node_d[ch_p('?')] >> tern_expr
					>> discard_node_d[ch_p(':')] >> tern_expr
				   )
				;
		}

		rule<ScannerT, parser_context<>, parser_tag<id_tern_expr> >
		const &start() const {
			return tern_expr;
		}

		rule<ScannerT, parser_context<>, parser_tag<id_value> > value;
		rule<ScannerT, parser_context<>, parser_tag<id_variable> > variable;
		rule<ScannerT, parser_context<>, parser_tag<id_basic> > basic;
		rule<ScannerT, parser_context<>, parser_tag<id_bool_expr> > bool_expr;
		rule<ScannerT, parser_context<>, parser_tag<id_ord_expr> > ord_expr;
		rule<ScannerT, parser_context<>, parser_tag<id_eq_expr> > eq_expr;
		rule<ScannerT, parser_context<>, parser_tag<id_pow_expr> > pow_expr;
		rule<ScannerT, parser_context<>, parser_tag<id_mult_expr> > mult_expr;
		rule<ScannerT, parser_context<>, parser_tag<id_plus_expr> > plus_expr;
		rule<ScannerT, parser_context<>, parser_tag<id_in_expr> > in_expr;

		rule<ScannerT, parser_context<>, parser_tag<id_function> > function;
		rule<ScannerT, parser_context<>, parser_tag<id_tern_expr> > tern_expr;
		rule<ScannerT, parser_context<>, parser_tag<id_string> > string;
	};
};

template<typename charT>
basic_expressor<charT>::basic_expressor()
	: grammar_(new parser_grammar<charT>)
{
	/*
	 * We provide a couple of standard variables everyone wants.
	 */
	add_variable(make_astring<charT>("true"), afp::basic_datum<charT>::from_int(true));
	add_variable(make_astring<charT>("false"), afp::basic_datum<charT>::from_int(false));

	/*
	 * The cast functions.
	 */
	add_function(make_astring<charT>("int"), &f_int<charT>);
	add_function(make_astring<charT>("string"), &f_string<charT>);
	add_function(make_astring<charT>("float"), &f_float<charT>);
}

template<typename charT>
basic_expressor<charT>::~basic_expressor()
{
	delete grammar_;
}

/*
 * The user interface to evaluate an expression.  It returns the result, or
 * throws an exception if an error occurs.
 */
template<typename charT>
basic_datum<charT>
basic_expressor<charT>::evaluate(basic_fray<charT> const &filter) const
{
	using namespace boost::spirit;

	typedef typename basic_fray<charT>::const_iterator iterator_t;

	basic_datum<charT> ret;

	tree_parse_info<iterator_t> info = ast_parse(filter.begin(), filter.end(), *grammar_,
			+chset<>("\n\t ") | comment_p("/*", "*/"));

	if (info.full) {
		ast_evaluator<charT, typename tree_match<iterator_t>::tree_iterator> ae(*grammar_);
		return ae.tree_eval(info.trees.begin());
	} else {
		throw parse_error("parsing failed");
	}
}

template<typename charT>
void
basic_expressor<charT>::print_xml(std::ostream &strm, basic_fray<charT> const &filter) const
{
	using namespace boost::spirit;

	typedef typename basic_fray<charT>::const_iterator iterator_t;

	tree_parse_info<iterator_t> info = ast_parse(filter.begin(), filter.end(), *grammar_,
			+chset<>("\n\t ") | comment_p("/*", "*/"));

	if (info.full) {
		std::map<parser_id, std::string> rule_names;
		rule_names[parser_grammar<charT>::id_value] = "value";
		rule_names[parser_grammar<charT>::id_variable] = "variable";
		rule_names[parser_grammar<charT>::id_basic] = "basic";
		rule_names[parser_grammar<charT>::id_bool_expr] = "bool_expr";
		rule_names[parser_grammar<charT>::id_ord_expr] = "ord_expr";
		rule_names[parser_grammar<charT>::id_eq_expr] = "eq_expr";
		rule_names[parser_grammar<charT>::id_pow_expr] = "pow_expr";
		rule_names[parser_grammar<charT>::id_mult_expr] = "mult_expr";
		rule_names[parser_grammar<charT>::id_plus_expr] = "plus_expr";
		rule_names[parser_grammar<charT>::id_in_expr] = "in_expr";
		rule_names[parser_grammar<charT>::id_function] = "function";
		rule_names[parser_grammar<charT>::id_tern_expr] = "tern_expr";
		rule_names[parser_grammar<charT>::id_string] = "string";
		tree_to_xml(strm, info.trees, "", rule_names);
	} else {
		throw parse_error("parsing failed");
	}
}

template<typename charT>
void
basic_expressor<charT>::clear()
{
	clear_variables();
	clear_functions();
}

template<typename charT>
void
basic_expressor<charT>::clear_variables()
{
	symbols<basic_datum<charT>, charT > variables;
	grammar_->variables = variables;
}

template<typename charT>
void
basic_expressor<charT>::clear_functions()
{
	symbols<boost::function<basic_datum<charT> (std::vector<basic_datum<charT> >)>, charT > functions;
	grammar_->functions = functions;
}

template<typename charT>
void
basic_expressor<charT>::add_variable(basic_fray<charT> const &name, basic_datum<charT> const &value)
{
	grammar_->add_variable(name, value);
}

template<typename charT>
void
basic_expressor<charT>::add_function(basic_fray<charT> const &name, func_t value)
{
	grammar_->add_function(name, value);
}

} // namespace afp

#endif	/* !EXPRESSOR_H */
