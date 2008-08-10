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
#include	"fray.h"

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

namespace {

template<typename charT>
int match(char const *, char const *);

template<typename charT>
basic_datum<charT>
f_in(basic_datum<charT> const &a, basic_datum<charT> const &b)
{
	basic_fray<charT> sa = a.toString(), sb = b.toString();
	return basic_datum<charT>::from_int(std::search(sb.begin(), sb.end(), sa.begin(), sa.end()) != sb.end());
}

template<typename charT>
basic_datum<charT>
f_like(basic_datum<charT> const &str, basic_datum<charT> const &pattern)
{
	return basic_datum<charT>::from_int(match(pattern.toString().c_str(), str.toString().c_str()));
}

template<typename charT>
basic_datum<charT>
f_regex(basic_datum<charT> const &str, basic_datum<charT> const &pattern)
{
	basic_fray<charT> f = pattern.toString();
	boost::u32regex r = boost::make_u32regex(f.begin(), f.end(),
				boost::regex_constants::perl);
	basic_fray<charT> s = str.toString();
	return basic_datum<charT>::from_int(boost::u32regex_match(
				s.begin(), s.end(), r));
}

template<typename charT>
basic_datum<charT>
f_int(std::vector<basic_datum<charT> > const &args)
{
	if (args.size() != 1)
		throw parse_error("wrong number of arguments to int() (expected 1)");

	return basic_datum<charT>::from_int(args[0].toInt());
}

template<typename charT>
basic_datum<charT>
f_string(std::vector<basic_datum<charT> > const &args)
{
	if (args.size() != 1)
		throw parse_error("wrong number of arguments to string() (expected 1)");

	return basic_datum<charT>::from_string(args[0].toString());
}

template<typename charT>
basic_datum<charT>
f_float(std::vector<basic_datum<charT> > const &args)
{
	if (args.size() != 1)
		throw parse_error("wrong number of arguments to float() (expected 1)");

	return basic_datum<charT>::from_double(args[0].toFloat());
}

} // anonymous namespace

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

template<typename charT>
struct ast_evaluator {
	typedef typename tree_match<typename basic_fray<charT>::const_iterator>::tree_iterator astiter_t;
	parser_grammar<charT> const &grammar_;

	ast_evaluator(parser_grammar<charT> const &grammar)
		: grammar_(grammar)
	{
	}

	basic_datum<charT>
	ast_eval_in(charT oper, astiter_t const &a, astiter_t const &b)
	{
		switch (oper) {
		case 'i':
			return f_in(tree_eval(a), tree_eval(b));
		case 'c':
			return f_in(tree_eval(b), tree_eval(a));
		case 'l':
		case 'm':
			return f_like(tree_eval(a), tree_eval(b));
		case 'r':
			return f_regex(tree_eval(a), tree_eval(b));
		default:
			abort();
		}
	}

	basic_datum<charT>
	ast_eval_bool(charT oper, astiter_t const &a, astiter_t const &b)
	{
		switch (oper) {
		case '&':
			if (tree_eval(a).toBool())
				if (tree_eval(b).toBool())
					return basic_datum<charT>::from_int(1);
			return basic_datum<charT>::from_int(0);
		
		case '|':
			if (tree_eval(a).toBool())
				return basic_datum<charT>::from_int(1);
			else
				if (tree_eval(b).toBool())
					return basic_datum<charT>::from_int(1);
			return basic_datum<charT>::from_int(0);

		case '^': 
			{
				int va = tree_eval(a).toBool(), vb = tree_eval(b).toBool();
				if ((va && !vb) || (!va && vb))
					return basic_datum<charT>::from_int(1);
				return basic_datum<charT>::from_int(0);
			}
		}

		abort();
	}
				
	basic_datum<charT>
	ast_eval_plus(charT oper, astiter_t const &a, astiter_t const &b)
	{
		switch (oper) {
		case '+':
			return tree_eval(a) + tree_eval(b);

		case '-':
			return tree_eval(a) - tree_eval(b);

		default:
			abort();
		}
	}

	basic_datum<charT>
	ast_eval_mult(charT oper, astiter_t const &a, astiter_t const &b)
	{
		switch (oper) {
		case '*':
			return tree_eval(a) * tree_eval(b);
		case '/':
			return tree_eval(a) / tree_eval(b);
		case '%':
			return tree_eval(a) % tree_eval(b);
		default:
			abort();
		}
	}

	basic_datum<charT>
	ast_eval_ord(basic_fray<charT> const &oper, astiter_t const &a, astiter_t const &b)
	{
		switch (oper.size()) {
		case 1:
			switch (oper[0]) {
			case '<':
				return basic_datum<charT>::from_int(tree_eval(a) < tree_eval(b));
			case '>':
				return basic_datum<charT>::from_int(tree_eval(a) > tree_eval(b));
			default:
				abort();
			}

		case 2:
			switch(oper[0]) {
			case '<':
				return basic_datum<charT>::from_int(tree_eval(a) <= tree_eval(b));
			case '>':
				return basic_datum<charT>::from_int(tree_eval(a) >= tree_eval(b));
			default:
				abort();
			}

		default:
			abort();
		}
	}

	basic_datum<charT>
	ast_eval_eq(basic_fray<charT> const &oper, astiter_t const &a, astiter_t const &b)
	{
		switch (oper.size()) {
		case 1: /* = */
			return basic_datum<charT>::from_int(tree_eval(a) == tree_eval(b));
		case 2: /* != /= == */
			switch (oper[0]) {
			case '!':
			case '/':
				return basic_datum<charT>::from_int(tree_eval(a) != tree_eval(b));
			case '=':
				return basic_datum<charT>::from_int(tree_eval(a) == tree_eval(b));
			default:
				abort();
			}
		case 3: /* === !== */
			switch (oper[0]) {
			case '=':
				return basic_datum<charT>::from_int(tree_eval(a).compare_with_type(tree_eval(b)));
			case '!':
				return basic_datum<charT>::from_int(!tree_eval(a).compare_with_type(tree_eval(b)));
			default:
				abort();
			}
		default:
			abort();
		}
	}

	basic_datum<charT>
	ast_eval_pow(astiter_t const &a, astiter_t const &b)
	{
		return pow(tree_eval(a), tree_eval(b));
	}

	basic_datum<charT>
	ast_eval_string(basic_fray<charT> const &s)
	{
		std::vector<charT> ret;
		ret.reserve(int(s.size() * 1.2));

		for (std::size_t i = 0, end = s.size(); i < end; ++i) {
			if (s[i] != '\\') {
				ret.push_back(s[i]);
				continue;
			}

			if (i+1 == end)
				break;

			switch (s[i + 1]) {
			case 't':
				ret.push_back('\t');
				break;
			case 'n':
				ret.push_back('\n');
				break;
			case 'r':
				ret.push_back('\r');
				break;
			case 'b':
				ret.push_back('\b');
				break;
			case 'a':
				ret.push_back('\a');
				break;
			case 'f':
				ret.push_back('\f');
				break;
			case 'v':
				ret.push_back('\v');
				break;
			default:
				ret.push_back(s[i + 1]);
				break;
			}

			i++;
		}

		return basic_datum<charT>::from_string(basic_fray<charT>(ret.begin(), ret.end()));
	}

	basic_datum<charT>
	ast_eval_tern(astiter_t const &cond, astiter_t const &iftrue, astiter_t const &iffalse)
	{
		if (tree_eval(cond).toBool())
			return tree_eval(iftrue);
		else
			return tree_eval(iffalse);
	}

	basic_datum<charT>
	ast_eval_num(basic_fray<charT> const &s)
	{
		if (s.find('.') != basic_fray<charT>::npos)
			return basic_datum<charT>::from_double(std::strtod(make_u8fray(s).c_str(), 0));

		int base;
		switch (s[s.size() - 1]) {
		case 'x':
			base = 16;
			break;
		case 'o':
			base = 8;
			break;
		case 'b':
			base = 2;
			break;
		default:
			base = 10;
			break;
		}

		return basic_datum<charT>::from_int(std::strtol(make_u8fray(s).c_str(), 0, base));
	}

	basic_datum<charT>
	ast_eval_function(basic_fray<charT> const &f, astiter_t abegin, astiter_t const &aend)
	{
		std::vector<basic_datum<charT> > args;

		for (; abegin != aend; ++abegin)
			args.push_back(tree_eval(abegin));

		boost::function<basic_datum<charT> (std::vector<basic_datum<charT> >)> *fptr;
		if ((fptr = find(grammar_.functions, f.c_str())) == NULL)
			return basic_datum<charT>::from_string(basic_fray<charT>());
		else
			return (*fptr)(args);
	}

	basic_datum<charT>
	ast_eval_basic(charT op, astiter_t const &val)
	{
		switch (op) {
		case '!':
			if (tree_eval(val).toBool())
				return basic_datum<charT>::from_int(0);
			else
				return basic_datum<charT>::from_int(1);

		case '-':
			return -tree_eval(val);

		case '+':
			return tree_eval(val);
		default:
			abort();
		}
	}

	basic_datum<charT>
	ast_eval_variable(basic_fray<charT> const &v)
	{
		basic_datum<charT> const *var;
		if ((var = find(grammar_.variables, v.c_str())) == NULL)
			return basic_datum<charT>::from_string(basic_fray<charT>());
		else
			return *var;
	}

	basic_datum<charT>
	tree_eval(astiter_t const &i)
	{
		switch (i->value.id().to_long()) {
		case parser_grammar<charT>::id_value:
			return ast_eval_num(
				basic_fray<charT>(i->value.begin(), i->value.end()));

		case parser_grammar<charT>::id_string:
			return ast_eval_string(basic_fray<charT>(i->value.begin(), i->value.end()));

		case parser_grammar<charT>::id_basic:
			return ast_eval_basic(*i->value.begin(), i->children.begin());

		case parser_grammar<charT>::id_variable:
			return ast_eval_variable(basic_fray<charT>(i->value.begin(), i->value.end()));

		case parser_grammar<charT>::id_function:
			return ast_eval_function(
					basic_fray<charT>(i->value.begin(), i->value.end()),
					i->children.begin(), i->children.end());

		case parser_grammar<charT>::id_in_expr:
			return ast_eval_in(*i->value.begin(), i->children.begin(), i->children.begin() + 1);

		case parser_grammar<charT>::id_bool_expr:
			return ast_eval_bool(*i->value.begin(), i->children.begin(), i->children.begin() + 1);

		case parser_grammar<charT>::id_plus_expr:
			return ast_eval_plus(*i->value.begin(), i->children.begin(), i->children.begin() + 1);

		case parser_grammar<charT>::id_mult_expr:
			return ast_eval_mult(*i->value.begin(), i->children.begin(), i->children.begin() + 1);

		case parser_grammar<charT>::id_pow_expr:
			return ast_eval_pow(i->children.begin(), i->children.begin() + 1);

		case parser_grammar<charT>::id_ord_expr:
			return ast_eval_ord(
				basic_fray<charT>(i->value.begin(), i->value.end()),
				i->children.begin(), i->children.begin() + 1);

		case parser_grammar<charT>::id_eq_expr:
			return ast_eval_eq(
				basic_fray<charT>(i->value.begin(), i->value.end()),
				i->children.begin(), i->children.begin() + 1);

		case parser_grammar<charT>::id_tern_expr:
			return ast_eval_tern(
					i->children.begin(),
					i->children.begin() + 1,
					i->children.begin() + 2);

		default:
			std::cerr << "warning: unmatched expr type " << i->value.id().to_long() << "\n";
			return basic_datum<charT>::from_int(0);
		}
	}
};

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
		ast_evaluator<charT> ae(*grammar_);
		return ae.tree_eval(info.trees.begin());
	} else {
		//std::cerr << "stopped at: [" << basic_fray<charT>(info.stop, filter.end()) << "]\n";
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

namespace {

/*	$NetBSD: fnmatch.c,v 1.21 2005/12/24 21:11:16 perry Exp $	*/

/*
 * Copyright (c) 1989, 1993, 1994
 *	The Regents of the University of California.  All rights reserved.
 *
 * This code is derived from software contributed to Berkeley by
 * Guido van Rossum.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. Neither the name of the University nor the names of its contributors
 *    may be used to endorse or promote products derived from this software
 *    without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE REGENTS AND CONTRIBUTORS ``AS IS'' AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED.  IN NO EVENT SHALL THE REGENTS OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS
 * OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
 * HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
 * OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF
 * SUCH DAMAGE.
 */

/*
 * Function fnmatch() as specified in POSIX 1003.2-1992, section B.6.
 * Compares a filename or pathname to a pattern.
 */

#include <ctype.h>
#include <string.h>

#define	EOS	'\0'

template<typename charT>
const charT *rangematch(const charT *, int);

template<typename charT>
int
match(charT const *pattern, charT const *string)
{
	const charT *stringstart;
	charT c, test;

	for (stringstart = string;;)
		switch (c = *pattern++) {
		case EOS:
			return (*string == EOS ? 1 : 0);
		case '?':
			if (*string == EOS)
				return (0);
			++string;
			break;
		case '*':
			c = *pattern;
			/* Collapse multiple stars. */
			while (c == '*')
				c = *++pattern;

			/* Optimize for pattern with * at end or before /. */
			if (c == EOS) {
				return (1);
			}

			/* General case, use recursion. */
			while ((test = *string) != EOS) {
				if (match(pattern, string))
					return (1);
				++string;
			}
			return (0);
		case '[':
			if (*string == EOS)
				return (0);
			if ((pattern =
			    rangematch(pattern, *string)) == NULL)
				return (0);
			++string;
			break;
		case '\\':
			if ((c = *pattern++) == EOS) {
				c = '\\';
				--pattern;
			}
			/* FALLTHROUGH */
		default:
			if (c != *string++)
				return (0);
			break;
		}
	/* NOTREACHED */
}

template<typename charT>
const charT *
rangematch(charT const *pattern, int test)
{
	int negate, ok;
	charT c, c2;

	/*
	 * A bracket expression starting with an unquoted circumflex
	 * character produces unspecified results (IEEE 1003.2-1992,
	 * 3.13.2).  This implementation treats it like '!', for
	 * consistency with the regular expression syntax.
	 * J.T. Conklin (conklin@ngai.kaleida.com)
	 */
	if ((negate = (*pattern == '!' || *pattern == '^')) != 0)
		++pattern;
	
	for (ok = 0; (c = *pattern++) != ']';) {
		if (c == '\\')
			c = *pattern++;
		if (c == EOS)
			return (NULL);
		if (*pattern == '-' 
		    && (c2 = (*(pattern+1))) != EOS &&
		        c2 != ']') {
			pattern += 2;
			if (c2 == '\\')
				c2 = *pattern++;
			if (c2 == EOS)
				return (NULL);
			if (c <= test && test <= c2)
				ok = 1;
		} else if (c == test)
			ok = 1;
	}
	return (ok == negate ? NULL : pattern);
}

} // anonymous namespace

} // namespace afp

#endif	/* !EXPRESSOR_H */
