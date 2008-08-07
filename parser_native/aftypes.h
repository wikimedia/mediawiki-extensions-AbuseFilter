#ifndef AFTYPES_H
#define AFTYPES_H

#include <string>
#include <vector>

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

#include <iostream>

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
		~AFPData();
		AFPData();
		AFPData( unsigned int type, void* value, size_t size );
		AFPData( string var );
		AFPData( AFPData oldData, unsigned int newType );
		AFPData( const AFPData & oldData );
		
		// Assignment operator
		AFPData & operator = (const AFPData & other);
		
		// Specific type constructors
		AFPData( long int var );
		AFPData( double var );
		AFPData( bool var );

		bool toBool();
		string toString();
		long int toInt();
		double toFloat();
		unsigned int getType();
		
	protected:
		void makeData( unsigned int type, void* value, size_t size, string source );
		void release();
		
		unsigned int type;
		void* value;
		size_t size;
		string source;
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

bool isInVector( string needle, vector<string> haystack );

#endif	/* !AFTYPES_H */
