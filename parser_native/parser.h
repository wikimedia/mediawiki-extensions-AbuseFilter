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
#include	<boost/spirit.hpp>
#include	<boost/spirit/phoenix.hpp>
#include	<boost/spirit/phoenix/composite.hpp>
#include	<boost/spirit/phoenix/functions.hpp>
#include	<boost/spirit/phoenix/operators.hpp>
#include	<boost/function.hpp>
#include	<boost/noncopyable.hpp>
#include	<boost/format.hpp>
#include	<boost/regex/icu.hpp>

#include	<unicode/uchar.h>

#include	"aftypes.h"
#include	"afstring.h"

namespace afp {

template<typename T> struct parser_grammar;

template<typename charT>
struct basic_expressor : boost::noncopyable {
	typedef boost::function<basic_datum<charT> (std::vector<basic_datum<charT> >)> func_t;

	basic_expressor();
	~basic_expressor();

	basic_datum<charT> evaluate(std::basic_string<charT> const &expr) const;

	void add_variable(std::basic_string<charT> const &name, basic_datum<charT> const &value);
	void add_function(std::basic_string<charT> const &name, func_t value);

private:
	parser_grammar<charT> *grammar_;
};

typedef basic_expressor<char> expressor;
typedef basic_expressor<UChar32> u32expressor;

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

struct parse_error : std::runtime_error {
	parse_error(char const *what) : std::runtime_error(what) {}
};

/*
 * The parser stores the result of each grammar rule in a closure.  Most rules
 * use the parser_closure, which simply stores a single value.
 */
template<typename charT>
struct parser_closure : boost::spirit::closure<parser_closure<charT>, basic_datum<charT> >
{
	typename parser_closure<charT>::member1 val;
};

namespace {

template<typename charT>
int match(char const *, char const *);

template<typename charT>
basic_datum<charT>
f_in(basic_datum<charT> const &a, basic_datum<charT> const &b)
{
	std::basic_string<charT> sa = a.toString(), sb = b.toString();
	return basic_datum<charT>::from_int(std::search(sb.begin(), sb.end(), sa.begin(), sa.end()) != sb.end());
}

template<typename charT>
basic_datum<charT>
f_like(basic_datum<charT> const &str, basic_datum<charT> const &pattern)
{
	return basic_datum<charT>::from_int(match(str.toString().c_str(), pattern.toString().c_str()));
}

template<typename charT>
basic_datum<charT>
f_regex(basic_datum<charT> const &str, basic_datum<charT> const &pattern)
{
	boost::u32regex r = boost::make_u32regex(pattern.toString());
	std::basic_string<charT> s = str.toString();
	return basic_datum<charT>::from_int(boost::u32regex_match(
				s.begin(), s.end(), r));
}

template<typename charT>
basic_datum<charT>
f_ternary(basic_datum<charT> const &v, basic_datum<charT> const &iftrue, basic_datum<charT> const &iffalse)
{
	return v.toInt() ? iftrue : iffalse;
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

template<typename charT>
basic_datum<charT>
f_append(basic_datum<charT> const &a, charT b)
{
	return basic_datum<charT>::from_string(a.toString() + b);
}

template<typename charT>
basic_datum<charT>
f_strip_last(basic_datum<charT> const &a)
{
	std::basic_string<charT> s(a.toString());
	s.resize(s.size() - 1);
	return basic_datum<charT>::from_string(s);
}

template<typename charT>
basic_datum<charT>
datum_and(basic_datum<charT> const &a, basic_datum<charT> const &b)
{
	return basic_datum<charT>::from_int(a.toInt() && b.toInt());
}

template<typename charT>
basic_datum<charT>
datum_or(basic_datum<charT> const &a, basic_datum<charT> const &b)
{
	return basic_datum<charT>::from_int(a.toInt() || b.toInt());
}

template<typename charT>
basic_datum<charT>
datum_xor(basic_datum<charT> const &a, basic_datum<charT> const &b)
{
	return basic_datum<charT>::from_int((bool)a.toInt() ^ (bool)b.toInt());
}

template<typename charT>
basic_datum<charT>
datum_negate(basic_datum<charT> const &a)
{
	return basic_datum<charT>::from_int(!(a.toBool()));
}

} // anonymous namespace

/*
 * This is the closure types for functions.  'val' stores the final result of
 * the function call; func and args store the function object and the parsed
 * arguments.
 */
template<typename charT>
struct function_closure : boost::spirit::closure<
			  	function_closure<charT>,
				basic_datum<charT>,
				boost::function<basic_datum<charT> (std::vector<basic_datum<charT> >)>,
				std::vector<basic_datum<charT> > >
{
	typename function_closure<charT>::member1 val;
	typename function_closure<charT>::member2 func;
	typename function_closure<charT>::member3 args;
};

/*
 * The closure for the ?: operator.  Parsed as expr ? iftrue : iffalse.
 */
template<typename charT>
struct ternary_closure : boost::spirit::closure<
			 ternary_closure<charT>,
			 basic_datum<charT>,
			 basic_datum<charT>,
			 basic_datum<charT> >
{
	typename ternary_closure<charT>::member1 val;
	typename ternary_closure<charT>::member2 iftrue;
	typename ternary_closure<charT>::member3 iffalse;
};

/*
 * The grammar itself.
 */
template<typename charT>
struct parser_grammar : public grammar<parser_grammar<charT>, typename parser_closure<charT>::context_t >
{
	/* User-defined variables. */
	symbols<basic_datum<charT> > variables;

	void add_variable(std::basic_string<charT> const &name, basic_datum<charT> const &value) {
		variables.add(name.c_str(), value);
	}

	/* User-defined functions. */
	symbols<boost::function<basic_datum<charT> (std::vector<basic_datum<charT> >)> > functions;

	void add_function(
			std::basic_string<charT> const &name, 
			boost::function<basic_datum<charT> (std::vector<basic_datum<charT> >)> func) {
		functions.add(name.c_str(), func);
	}

	template<typename ScannerT>
	struct definition
	{
		typedef rule<ScannerT, typename parser_closure<charT>::context_t > rule_t;

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
				typedef basic_datum<charT> type;
			};

			template<typename F, typename A>
			basic_datum<charT> operator() (F const &func, A const &args) const {
				return func(args);
			}
		};

		phoenix::function<call_function_impl> const call_function;

		definition(parser_grammar const &self) 
		: self_(self)
		, push_back(push_back_impl())
	        , call_function(call_function_impl())
		{
			std::basic_string<charT> empty_string;

			/*
			 * A literal value.  Either a string, a floating
			 * pointer number or an integer.
			 */
			value = 
				  strict_real_p[value.val = bind(&basic_datum<charT>::from_double)(arg1)]
				| as_lower_d[
					  oct_p[value.val = bind(&basic_datum<charT>::from_int)(arg1)] >> 'o'
					| hex_p[value.val = bind(&basic_datum<charT>::from_int)(arg1)] >> 'x'
					| bin_p[value.val = bind(&basic_datum<charT>::from_int)(arg1)] >> 'b'
					| int_p[value.val = bind(&basic_datum<charT>::from_int)(arg1)]
				]
				/*
				 * config_p can't be used here, because it will rewrite
				 * *(c_escape_ch_p[x]) into (*c_escape_ch_p)[x]
				 */
				| (
					   ch_p(charT('"'))[value.val = bind(&basic_datum<charT>::from_string)(empty_string)]
					>> *((c_escape_ch_p[value.val = bind(&f_append<charT>)(value.val, arg1)] - '"'))
					>> ch_p(charT('"'))[value.val = bind(&f_strip_last<charT>)(value.val)]
				  )
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
				| (+ (upper_p | '_') )[variable.val = bind(&basic_datum<charT>::from_string)(empty_string)]
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
				| ch_p('!') >> tern_expr[basic.val = bind(&datum_negate<charT>)(arg1)]
				| ch_p('+') >> tern_expr[basic.val = arg1] 
				| ch_p('-') >> tern_expr[basic.val = -arg1] 
				| value[basic.val = arg1]
				| variable[basic.val = arg1]
				| function[basic.val = arg1]
				;

			/*
			 * "a in b" operator
			 */
			in_expr = 
				  basic[in_expr.val = arg1]
				>> *(
					  "in"       >> basic[in_expr.val = bind(&f_in<charT>)(in_expr.val, arg1)]
					| "contains" >> basic[in_expr.val = bind(&f_in<charT>)(arg1, in_expr.val)]
					| "like"     >> basic[in_expr.val = bind(&f_like<charT>)(arg1, in_expr.val)]
					| "matches"  >> basic[in_expr.val = bind(&f_like<charT>)(arg1, in_expr.val)]
					| "rlike"    >> basic[in_expr.val = bind(&f_regex<charT>)(in_expr.val, arg1)]
					| "regex"    >> basic[in_expr.val = bind(&f_regex<charT>)(in_expr.val, arg1)]
				    )
				;

			/*
			 * power-of.  This is right-associative. 
			 */
			pow_expr =
				   in_expr[pow_expr.val = arg1]
				>> !(
					"**" >> pow_expr[pow_expr.val = bind(&::afp::pow<charT>)(pow_expr.val, arg1)]
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
						/* don't remove the () from (ord_expr.val) - for some reason it confuses
						   gcc into thinkins the < begins a template list */
					  "<"  >> plus_expr[ord_expr.val = bind(&basic_datum<charT>::from_int)((ord_expr.val) < arg1)]
					| "<=" >> plus_expr[ord_expr.val = bind(&basic_datum<charT>::from_int)(ord_expr.val <= arg1)]
					| ">"  >> plus_expr[ord_expr.val = bind(&basic_datum<charT>::from_int)(ord_expr.val > arg1)]
					| ">=" >> plus_expr[ord_expr.val = bind(&basic_datum<charT>::from_int)(ord_expr.val >= arg1)]
				    )
				;

			/*
			 * Equality comparisons.
			 */
			eq_expr =
				  ord_expr[eq_expr.val = arg1]
				>> *( 
					  "="   >> eq_expr[eq_expr.val = bind(&basic_datum<charT>::from_int)(eq_expr.val == arg1)]
					| "=="  >> eq_expr[eq_expr.val = bind(&basic_datum<charT>::from_int)(eq_expr.val == arg1)]
					| "!="  >> eq_expr[eq_expr.val = bind(&basic_datum<charT>::from_int)(eq_expr.val != arg1)]
					| "/="  >> eq_expr[eq_expr.val = bind(&basic_datum<charT>::from_int)(eq_expr.val != arg1)]
					| "===" >> eq_expr[eq_expr.val = 
							bind(&basic_datum<charT>::from_int)(
								bind(&basic_datum<charT>::compare_with_type)(eq_expr.val, arg1))]
					| "!==" >> eq_expr[eq_expr.val = 
							bind(&basic_datum<charT>::from_int)(
								!bind(&basic_datum<charT>::compare_with_type)(eq_expr.val, arg1))]
				    )
				;

			/*
			 * Boolean expressions.
			 */
			bool_expr =
				  eq_expr[bool_expr.val = arg1]
				>> *( 
					  '&' >> eq_expr[bool_expr.val = bind(datum_and<charT>)(bool_expr.val, arg1)]
					| '|' >> eq_expr[bool_expr.val = bind(datum_or<charT>)(bool_expr.val, arg1)]
					| '^' >> eq_expr[bool_expr.val = bind(datum_xor<charT>)(bool_expr.val, arg1)]
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
						bind(f_ternary<charT>)(tern_expr.val, tern_expr.iftrue, tern_expr.iffalse)]
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
		rule<ScannerT, typename function_closure<charT>::context_t > function;
		rule<ScannerT, typename ternary_closure<charT>::context_t > tern_expr;
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
basic_expressor<charT>::evaluate(std::basic_string<charT> const &filter) const
{
	using namespace boost::spirit;

	typedef typename std::basic_string<charT>::const_iterator iterator_t;

	basic_datum<charT> ret;
	parse_info<iterator_t> info = 
		parse(filter.begin(), filter.end(), (*grammar_)[var(ret) = arg1],
				comment_p("/*", "*/") | chset<>("\n\t "));
	if (info.full) {
		return ret;
	} else {
		//std::cerr << "stopped at: [" << std::basic_string<charT>(info.stop, filter.end()) << "]\n";
		throw parse_error("parsing failed");
	}
}

template<typename charT>
void
basic_expressor<charT>::add_variable(std::basic_string<charT> const &name, basic_datum<charT> const &value)
{
	grammar_->add_variable(name, value);
}

template<typename charT>
void
basic_expressor<charT>::add_function(std::basic_string<charT> const &name, func_t value)
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
