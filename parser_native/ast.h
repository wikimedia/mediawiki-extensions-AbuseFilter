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

#ifndef AST_H
#define AST_H

#include	<boost/date_time/date_parsing.hpp>

#include	"parserdefs.h"

namespace {

template<typename charT>
int
hex2int(charT const *str, int ndigits)
{
	int ret = 0;

	while (ndigits--) {
		ret *= 0x10;
		if (*str >= 'a' && *str <= 'f')
			ret += 10 + int(*str - 'a');
		else if (*str >= 'A' && *str <= 'F')
			ret += 10 + int(*str - 'A');
		else if (*str >= '0' && *str <= '9')
			ret += int(*str - '0');

		str++;
	}

	return ret;
}

}

namespace afp {

template<typename T> struct parser_grammar;

template<typename charT, typename iterator>
struct ast_evaluator {
	parser_grammar<charT> const &grammar_;

	ast_evaluator(parser_grammar<charT> const &grammar);

	basic_datum<charT> tree_eval(iterator const &);

	basic_datum<charT> ast_eval_basic(charT, iterator const &);
	basic_datum<charT> ast_eval_variable(basic_fray<charT> const &);
	basic_datum<charT> ast_eval_in(charT, iterator const &, iterator const &);
	basic_datum<charT> ast_eval_bool(charT, iterator const &, iterator const &);
	basic_datum<charT> ast_eval_plus(charT, iterator const &, iterator const &);
	basic_datum<charT> ast_eval_mult(charT, iterator const &, iterator const &);
	basic_datum<charT> ast_eval_pow(iterator const &, iterator const &);
	basic_datum<charT> ast_eval_string(basic_fray<charT> const &);
	basic_datum<charT> ast_eval_date(basic_fray<charT> const &);
	basic_datum<charT> ast_eval_num(basic_fray<charT> const &);
	basic_datum<charT> ast_eval_ord(basic_fray<charT> const &, iterator const &, iterator const &);
	basic_datum<charT> ast_eval_eq(basic_fray<charT> const &, iterator const &, iterator const &);
	basic_datum<charT> ast_eval_tern(iterator const &, iterator const &, iterator const &);
	basic_datum<charT> ast_eval_function(basic_fray<charT> const &, iterator, iterator const &);
	basic_datum<charT> ast_eval_time_unit(basic_fray<charT> const &, iterator const &);
	basic_datum<charT> ast_eval_comma(iterator const &, iterator const &);

};

template<typename charT, typename iterator>
ast_evaluator<charT, iterator>::ast_evaluator(parser_grammar<charT> const &grammar)
	: grammar_(grammar)
{
}

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_time_unit(
		basic_fray<charT> const &value,
		iterator const &child)
{
	int mult = *find(grammar_.time_units, value.c_str());
	return basic_datum<charT>::from_interval(
			boost::posix_time::seconds(mult)
			* tree_eval(child).toInt().get_si());
}

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_comma(
		iterator const &a, iterator const &b)
{
	return tree_eval(a) + tree_eval(b);
}

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_in(charT oper, iterator const &a, iterator const &b)
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

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_bool(charT oper, iterator const &a, iterator const &b)
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
			
template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_plus(charT oper, iterator const &a, iterator const &b)
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

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_mult(charT oper, iterator const &a, iterator const &b)
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

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_ord(basic_fray<charT> const &oper, iterator const &a, iterator const &b)
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

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_eq(basic_fray<charT> const &oper, iterator const &a, iterator const &b)
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

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_pow(iterator const &a, iterator const &b)
{
	return pow(tree_eval(a), tree_eval(b));
}

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_string(basic_fray<charT> const &s)
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
		case 'x':
			if (i + 3 >= end)
				break;
			ret.push_back(hex2int(s.data() + i + 2, 2));
			i += 2;
			break;

		case 'u':
			if (i + 5 >= end)
				break;
			ret.push_back(hex2int(s.data() + i + 2, 4));
			i += 4;
			break;

		case 'U':
			if (i + 9 >= end)
				break;
			ret.push_back(hex2int(s.data() + i + 2, 8));
			i += 8;
			break;

		default:
			ret.push_back(s[i + 1]);
			break;
		}

		i++;
	}

	return basic_datum<charT>::from_string(basic_fray<charT>(ret.begin(), ret.end()));
}

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_tern(iterator const &cond, iterator const &iftrue, iterator const &iffalse)
{
	if (tree_eval(cond).toBool())
		return tree_eval(iftrue);
	else
		return tree_eval(iffalse);
}

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_num(basic_fray<charT> const &s)
{
	if (s.find('.') != basic_fray<charT>::npos) {
		return basic_datum<charT>::from_double(
				typename basic_datum<charT>::float_t(
					make_u8fray(s).c_str()));
	}

	int base;
	int trim = 1;
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
		trim = 0;
		break;
	}

	fray t(make_u8fray(s));
	std::string str(t.begin(), t.end() - trim);
	return basic_datum<charT>::from_int(
			typename basic_datum<charT>::integer_t(str, base));
}

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_function(basic_fray<charT> const &f, iterator abegin, iterator const &aend)
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

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_basic(charT op, iterator const &val)
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

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_variable(basic_fray<charT> const &v)
{
	basic_datum<charT> const *var;
	if ((var = find(grammar_.variables, v.c_str())) == NULL)
		return basic_datum<charT>::from_string(basic_fray<charT>());
	else
		return *var;
}

template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::ast_eval_date(basic_fray<charT> const &v)
{
	using namespace boost::posix_time;
	return basic_datum<charT>::from_date(ptime(time_from_string(std::string(v.begin(), v.end()))));
}


template<typename charT, typename iterator>
basic_datum<charT>
ast_evaluator<charT, iterator>::tree_eval(iterator const &i)
{
	switch (i->value.id().to_long()) {
	case pid_value:
		return ast_eval_num(
			basic_fray<charT>(i->value.begin(), i->value.end()));

	case pid_string:
		return ast_eval_string(basic_fray<charT>(i->value.begin(), i->value.end()));

	case pid_date:
		return ast_eval_date(basic_fray<charT>(i->value.begin(), i->value.end()));

	case pid_basic:
		return ast_eval_basic(*i->value.begin(), i->children.begin());

	case pid_variable:
		return ast_eval_variable(basic_fray<charT>(i->value.begin(), i->value.end()));

	case pid_function:
		return ast_eval_function(
				basic_fray<charT>(i->value.begin(), i->value.end()),
				i->children.begin(), i->children.end());

	case pid_in_expr:
		return ast_eval_in(*i->value.begin(), i->children.begin(), i->children.begin() + 1);

	case pid_bool_expr:
		return ast_eval_bool(*i->value.begin(), i->children.begin(), i->children.begin() + 1);

	case pid_plus_expr:
		return ast_eval_plus(*i->value.begin(), i->children.begin(), i->children.begin() + 1);

	case pid_mult_expr:
		return ast_eval_mult(*i->value.begin(), i->children.begin(), i->children.begin() + 1);

	case pid_pow_expr:
		return ast_eval_pow(i->children.begin(), i->children.begin() + 1);

	case pid_ord_expr:
		return ast_eval_ord(
			basic_fray<charT>(i->value.begin(), i->value.end()),
			i->children.begin(), i->children.begin() + 1);

	case pid_eq_expr:
		return ast_eval_eq(
			basic_fray<charT>(i->value.begin(), i->value.end()),
			i->children.begin(), i->children.begin() + 1);

	case pid_tern_expr:
		return ast_eval_tern(
				i->children.begin(),
				i->children.begin() + 1,
				i->children.begin() + 2);

	case pid_comma_expr:
		return ast_eval_comma(
				i->children.begin(),
				i->children.begin() + 1);

	case pid_time_unit:
		return ast_eval_time_unit(
				basic_fray<charT>(i->value.begin(), i->value.end()),
				i->children.begin());

	default:
		throw parse_error(
			str(boost::format("internal error: unmatched expr type %d") % i->value.id().to_long()));
	}
}

} // namespace afp

#endif	/* !AST_H */
