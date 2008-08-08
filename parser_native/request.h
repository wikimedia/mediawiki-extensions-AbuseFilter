#ifndef REQUEST_H
#define REQUEST_H

#include	<string>
#include	<istream>

#include	"filter_evaluator.h"

namespace afp {

struct request {
	bool load(std::istream &);
	bool evaluate(void);

private:
	filter_evaluator f;
	std::string filter;
};

} // namespace afp

#endif	/* !REQUEST_H */
