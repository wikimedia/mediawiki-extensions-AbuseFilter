#include	"request.h"

// Protocol:
// code NULL <key> NULL <value> NULL ... <value> NULL NULL

bool 
request::load(std::istream &inp) {
	inp.unsetf(ios_base::skipws);

	std::istream_iterator<char> it(inp), p, end;

	std::pair<std::istream_iterator<char>, std::istream_iterator<char> >
		iters;

	filter.erase();
	for (; it != end; ++it) {
		if (*it == '\0')
			break;
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
			value.push_back(*it);
		}

		if (it == end)
			return false;

		it++;

		f.add_variable(key, AFPData(value));
	}
	
	return true;
}

bool
request::evaluate()
{
	return f.evaluate(filter);
}

