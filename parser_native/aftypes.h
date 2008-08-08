#ifndef AFTYPES_H
#define AFTYPES_H

#include <string>
#include <vector>
#include <iostream>

#include <boost/variant.hpp>
#include <boost/lexical_cast.hpp>

using namespace std;

#define T_NONE 0
#define T_ID 1
#define T_KEYWORD 2
#define T_STRING 3
#define T_NUMBER 4
#define T_OP 5
#define T_BRACE 6
#define T_COMMA 7

#define D_NULL 0
#define D_INTEGER 1
#define D_FLOAT 2
#define D_STRING 3

#define DATATYPE_MAX 3

class AFPToken {
public:
	AFPToken() {}
	AFPToken(unsigned int type, string value, unsigned int pos);
	unsigned int type;
	string value;
	unsigned int pos;
};

class AFPData {
public:
	AFPData();

	/*
	 * Generic ctor tries to convert to an int.
	 */
	template<typename T>
	AFPData(T const &v)
		: value_(boost::lexical_cast<long int>(v))
	{
	}

	// Specific type constructors
	AFPData( std::string const &var );
	AFPData( char const *var );
	AFPData( long int var );
	AFPData( float var );
	AFPData( double var );
	AFPData( bool var );

	AFPData( const AFPData & oldData );
		
	// Assignment operator
	AFPData &operator= (const AFPData & other);
		
	AFPData &operator+=(AFPData const &other);
	AFPData &operator-=(AFPData const &other);
	AFPData &operator*=(AFPData const &other);
	AFPData &operator/=(AFPData const &other);
	AFPData &operator%=(AFPData const &other);
	bool operator!() const;

	bool compare(AFPData const &other) const;
	bool compare_with_type(AFPData const &other) const;
	bool less_than(AFPData const &other) const;

	string toString() const;
	long int toInt() const;
	double toFloat() const;
	bool toBool() const {
		return (bool) toInt();
	}
		
	operator long int(void) const {
		return toInt();
	}

	operator double(void) const {
		return toFloat();
	}

	operator std::string(void) const {
		return toString();
	}

	operator bool(void) const {
		return (bool) toInt();
	}

	template<typename char_type, typename traits>
	void
	print_to(std::basic_ostream<char_type, traits> &s) const {
		s << value_;
	}

protected:
	void _init_from_string(std::string const &);	

	typedef boost::variant<std::string, long int, double> valuetype;
	valuetype value_;
};

class AFPException :exception {
	public:
		const char* what() {return this->s;}
		AFPException( const char* str ) {s = str;}
		AFPException( string str, string var ) { char* s1 = new char[1024]; sprintf( s1, str.c_str(), var.c_str() ); s = s1; }
		AFPException( string str, int var ) { char* s1 = new char[1024]; sprintf( s1, str.c_str(), var ); s = s1; }
		AFPException( string str, string svar, int ivar ) { char* s1 = new char[1024]; sprintf( s1, str.c_str(), ivar, svar.c_str() ); s = s1; }
		
	private:
		const char* s;
};

AFPData operator+(AFPData const &a, AFPData const &b);
AFPData operator-(AFPData const &a, AFPData const &b);
AFPData operator*(AFPData const &a, AFPData const &b);
AFPData operator/(AFPData const &a, AFPData const &b);
AFPData operator%(AFPData const &a, AFPData const &b);

bool operator==(AFPData const &a, AFPData const &b);
bool operator!=(AFPData const &a, AFPData const &b);
bool operator<(AFPData const &a, AFPData const &b);
bool operator>(AFPData const &a, AFPData const &b);
bool operator<=(AFPData const &a, AFPData const &b);
bool operator>=(AFPData const &a, AFPData const &b);

template<typename char_type, typename traits>
std::basic_ostream<char_type, traits> &
operator<<(std::basic_ostream<char_type, traits> &s, AFPData const &d) {
	d.print_to(s);
	return s;
}

bool isInVector( string needle, vector<string> haystack );

#endif	/* !AFTYPES_H */
