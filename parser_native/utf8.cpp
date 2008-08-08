#include	"utf8.h"

#include	<unicode/utf8.h>
#include	<unicode/ustring.h>

#include	"aftypes.h"

namespace utf8 {

// Ported from MediaWiki core function in PHP.
std::string
codepoint_to_utf8(int codepoint) {
	std::string ret;
	
	if(codepoint < 0x80) {
		ret.append(1, codepoint);
		return ret;
	}
	
	if(codepoint < 0x800) {
		ret.append(1, codepoint >> 6 & 0x3f | 0xc0);
		ret.append(1, codepoint & 0x3f | 0x80);
		return ret;
	}
	
	if(codepoint <  0x10000) {
		ret.append(1, codepoint >> 12 & 0x0f | 0xe0);
		ret.append(1, codepoint >> 6 & 0x3f | 0x80);
		ret.append(1, codepoint & 0x3f | 0x80);
		return ret;
	}
	
	if(codepoint < 0x110000) {
		ret.append(1, codepoint >> 18 & 0x07 | 0xf0);
		ret.append(1, codepoint >> 12 & 0x3f | 0x80);
		ret.append(1, codepoint >> 6 & 0x3f | 0x80);
		ret.append(1, codepoint & 0x3f | 0x80);
		return ret;
	}

	throw afp::exception("Asked for code outside of range ($codepoint)\n");
}

std::size_t
utf8_strlen(std::string const &s)
{
std::size_t	ret = 0;
	for (std::string::const_iterator it = s.begin(), end = s.end();
		it < end; ++it)
	{
	int	skip = 1;

		skip = U8_LENGTH(*it);
		if (it + skip >= end)
			return ret;	/* end of string */
				
		it += skip;
	}

	return ret;
}

/*
 * This could almost certainly be done in a nicer way.
 */
std::string
utf8_tolower(std::string const &s)
{
	std::vector<UChar> ustring;
	UErrorCode error = U_ZERO_ERROR;

	for (int i = 0, end = s.size(); i < end; ) {
		UChar32 c;
		U8_NEXT(s.data(), i, end, c);
		ustring.push_back(c);
	}

	std::vector<UChar> dest;
	u_strToLower(&dest[0], dest.size(), &ustring[0], ustring.size(),
			NULL, &error);
	
	if (U_FAILURE(error))
		return s;

	std::vector<unsigned char> u8string;
	int i, j, end;
	for (i = 0, j = 0, end = dest.size(); i < end; j++) {
		U8_APPEND_UNSAFE(&u8string[0], i, dest[j]);
	}
	return std::string(u8string.begin(), u8string.begin() + i);
}


} // namespace utf8
