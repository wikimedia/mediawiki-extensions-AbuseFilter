#ifndef EXPRESSOR_H
#define EXPRESSOR_H

#include	<string>
#include	<vector>

#include	<boost/noncopyable.hpp>
#include	<boost/function.hpp>

#include	"aftypes.h"

struct parser_grammar;

struct expressor : boost::noncopyable {
	typedef boost::function<AFPData (std::vector<AFPData>)> func_t;

	expressor();
	~expressor();

	AFPData evaluate(std::string const &expr) const;

	void add_variable(std::string const &name, AFPData value);
	void add_function(std::string const &name, func_t value);

private:
	parser_grammar *grammar_;
};

#endif	/* !EXPRESSOR_H */
