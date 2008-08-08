#include	"request.h"

namespace afp {

/* Perhaps, these should be configurable */
static const int MAX_FILTER_LEN = 1024 * 10; /* 10 KB */
static const int MAX_VARNAME_LEN = 255;
static const int MAX_VALUE_LEN = 1024 * 256; /* 256 KB */

// Protocol:
// code NULL <key> NULL <value> NULL ... <value> NULL NULL

bool 
request::load(std::istream &inp) {
	inp.unsetf(std::ios_base::skipws);

	std::istream_iterator<char> it(inp), p, end;

	std::pair<std::istream_iterator<char>, std::istream_iterator<char> >
		iters;

	filter.erase();
	for (; it != end; ++it) {
		if (*it == '\0')
			break;
		if (filter.size() > MAX_FILTER_LEN)
			return false;

		filter.push_back(*it);
	}

	if (it == end)
		return false;

	it++;

	while (true) {
		std::string key, value;

		/* read the key */
		for (; it != end; ++it) {
			if (*it == '\0')
				break;
			if (key.size() > MAX_VARNAME_LEN)
				return false;
			key.push_back(*it);
		}

		if (it == end)
			return false;

		if (key.empty()) 
			/*  empty string means end of input */
			return true;

		it++;

		/* read the value */
		for (; it != end; ++it) {
			if (*it == '\0')
				break;
			if (value.size() > MAX_VALUE_LEN)
				return false;
			value.push_back(*it);
		}

		if (it == end)
			return false;

		it++;

		f.add_variable(key, datum(value));
	}
	
	return true;
}

bool
request::evaluate()
{
	return f.evaluate(filter);
}

} // namespace afp
