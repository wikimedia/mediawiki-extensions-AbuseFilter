#ifndef EQUIV_H
#define EQUIV_H

#include	<map>

#include	<boost/noncopyable.hpp>

namespace afp {

struct equiv_set : boost::noncopyable {
	static equiv_set const &instance();

	int get(int) const;

private:
	equiv_set();

	std::map<int, int> equivs_;
};

} // namespace afp

#endif	/* !EQUIV_H */
