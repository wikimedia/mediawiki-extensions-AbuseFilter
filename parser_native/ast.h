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
	basic_datum<charT> ast_eval_num(basic_fray<charT> const &);
	basic_datum<charT> ast_eval_ord(basic_fray<charT> const &, iterator const &, iterator const &);
	basic_datum<charT> ast_eval_eq(basic_fray<charT> const &, iterator const &, iterator const &);
	basic_datum<charT> ast_eval_tern(iterator const &, iterator const &, iterator const &);
	basic_datum<charT> ast_eval_function(basic_fray<charT> const &, iterator, iterator const &);

};

template<typename charT, typename iterator>
ast_evaluator<charT, iterator>::ast_evaluator(parser_grammar<charT> const &grammar)
	: grammar_(grammar)
{
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
ast_evaluator<charT, iterator>::tree_eval(iterator const &i)
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

} // namespace afp

#endif	/* !AST_H */
