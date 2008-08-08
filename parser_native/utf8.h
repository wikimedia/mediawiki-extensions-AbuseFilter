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

#ifndef UTF8_H
#define UTF8_H

#include	<string>
#include	<cstddef>

namespace utf8 {

int next_utf8_char(std::string::const_iterator &p, std::string::const_iterator &charStart, std::string::const_iterator end);
std::string codepoint_to_utf8(int codepoint);
std::size_t utf8_strlen(std::string const &s);
std::string utf8_tolower(std::string const &s);

// Weak UTF-8 decoder
// Will return garbage on invalid input (overshort sequences, overlong sequences, etc.)
// Stolen from wikidiff2 extension by Tim Starling (no point in reinventing the wheel)
template<typename InputIterator>
struct utf8_iterator {
	utf8_iterator(InputIterator begin, InputIterator end)
		: cur_(begin)
		, end_(end)
		, atend_(false)
	{
		advance();
	}

	utf8_iterator()
		: atend_(true)
	{
	}

	int operator* (void) const {
		return curval;
	}

	bool operator==(utf8_iterator<InputIterator> const &other) const {
		if (atend_ || other.atend_)
			return atend_ == other.atend_;

		return cur_ == other.cur_;
	}

	utf8_iterator<InputIterator> &operator++(void) {
		advance();
		return *this;
	}

private:
	int curval;
	InputIterator cur_, end_;
	bool atend_;

	void advance();
};

template<typename InputIterator>
void
utf8_iterator<InputIterator>::advance()
{
	int c=0;
	unsigned char byte;
	int bytes = 0;

	if (cur_ == end_) {
		atend_ = true;
		curval = 0;
		return;
	}

	do {
		byte = (unsigned char)*cur_;
		if (byte < 0x80) {
			c = byte;
			bytes = 0;
		} else if (byte >= 0xc0) {
			// Start of UTF-8 character
			// If this is unexpected, due to an overshort sequence, we ignore the invalid
			// sequence and resynchronise here
			if (byte < 0xe0) {
				bytes = 1;
				c = byte & 0x1f;
			} else if (byte < 0xf0) {
				bytes = 2;
				c = byte & 0x0f;
			} else {
				bytes = 3;
				c = byte & 7;
			}
		} else if (bytes) {
			c <<= 6;
			c |= byte & 0x3f;
			--bytes;
		} else {
			// Unexpected continuation, ignore
		}
		++cur_;
	} while (bytes && cur_ != end_);
	curval = c;
}

template<typename InputIterator>
bool operator!= (utf8_iterator<InputIterator> const &a, utf8_iterator<InputIterator> const &b)
{
	return !(a == b);
}

} // namespace utf8

#endif	/* !UTF8_H */
