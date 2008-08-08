#ifndef EXPRESSOR_H
#define EXPRESSOR_H

#include	<string>
#include	<vector>

#include	<boost/noncopyable.hpp>
#include	<boost/function.hpp>

#include	"aftypes.h"

namespace afp {

struct parser_grammar;

struct expressor : boost::noncopyable {
	typedef boost::function<datum (std::vector<datum>)> func_t;

	expressor();
	~expressor();

	datum evaluate(std::string const &expr) const;

	void add_variable(std::string const &name, datum value);
	void add_function(std::string const &name, func_t value);

private:
	parser_grammar *grammar_;
};

} // namespace afp

#endif	/* !EXPRESSOR_H */
