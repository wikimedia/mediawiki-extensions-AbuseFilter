#include "aftypes.h"
#include <sstream>
#include <ios>
#include <iostream>

AFPToken::AFPToken(unsigned int new_type, string new_value, unsigned int new_pos) {
	type = new_type;
	value = new_value;
	pos = new_pos;
}


AFPData::AFPData( unsigned int new_type, void* new_value, size_t new_size ) {
	this->makeData( new_type, new_value, new_size );
}

void AFPData::makeData( unsigned int new_type, void* new_value, size_t new_size ) {
	type = new_type;
	value = new_value;
	size = new_size;
}

AFPData::AFPData( string var ) {
	const char* c_str = var.c_str();
	long int intval;
	double fval;
	char* last_char;
	istringstream ss(var);
	
	// Try integer	
	if (!!(ss >> intval) && intval != 0) { // 0.25 converts to 0, otherwise.
		// Valid conversion
		long int* val = new long int( intval );
		this->makeData( D_INTEGER, (void*)val, sizeof(long int) );
		return;
	}
	
	if (!!(ss >> fval)) {
		double* val = new double(fval);
		this->makeData( D_FLOAT, (void*)val, sizeof(double) );
		return;
	}
	
	// Last resort
	// Duplicate the string.
	string* s = new string(var);
	this->makeData( D_STRING, (void*)s, sizeof(string) );
	return;
}

AFPData::AFPData( AFPData old, unsigned int newType ) {
	if (old.type > DATATYPE_MAX) {
		// Non-existent type
		throw new AFPException( "Given junk data" );
	}

	if (old.type == newType) {
		void* newVal;
		
		// Duplicate the contents.
		if (old.type == D_STRING) {
			newVal = (void*) new string(old.toString());
		} else if (old.type == D_INTEGER) {
			newVal = (void*) new long int(old.toInt());
		} else if (old.type == D_FLOAT) {
			newVal = (void*) new double(old.toFloat());
		}
		
		this->makeData( old.type, newVal, old.size );
	} else if (newType == 0) {
		this->makeData( D_NULL, NULL, 0 );
		return;
	} else if (newType == D_INTEGER) {
		if (old.type==D_FLOAT) {
			long int* val = new long int(old.toFloat());
			this->makeData( D_INTEGER, (void*)val, sizeof(long int) );
			return;
		} else if (old.type==D_STRING) {
			long int* val = new long int();
			istringstream ss(old.toString());
			
			ss >> *val;
			
			this->makeData( D_INTEGER, (void*)val, sizeof(long int) );
			return;
		} else if (old.type==D_NULL) {
			long int* val = new long int(0);
			this->makeData( D_INTEGER, (void*)val, sizeof(long int) );
		}// No other types possible
	} else if (newType == D_FLOAT) {
		if (old.type==D_INTEGER) {
			double* val = new double(old.toInt());
			this->makeData( D_FLOAT, (void*)val, sizeof(double) );
			return;
		} else if (old.type==D_STRING) {
			double* val = new double();
			istringstream ss(old.toString());
			
			ss >> *val;
			
			this->makeData( D_FLOAT, (void*)val, sizeof(double) );
			return;
		} else if (old.type==D_NULL) {
			double* val = new double(0);
			this->makeData( D_FLOAT, (void*)val, sizeof(double) );
		} // No other types possible
	} else if (newType == D_STRING) {
		if (old.type == D_INTEGER || old.type == D_FLOAT) {
			ostringstream ss;
			
			if (old.type == D_INTEGER) {
				long int val = old.toInt();
				ss << val;
			} else if (old.type == D_FLOAT) {
				double val = old.toFloat();
				ss << val;
			}
			
			string* str = new string(ss.str());
			this->makeData( D_STRING, (void*)str, sizeof(string) );
			return;
		} else if (old.type==D_NULL) {
			string* s = new string("");
			this->makeData( D_STRING, (void*)s, sizeof(string) );
		} // No other types possible
	}
	
	if (this->type > DATATYPE_MAX) {
		// Non-existent type
		throw new AFPException( "Created junk data" );
	}
}

AFPData::AFPData() { this->makeData( 0, NULL, 0 );}

AFPData::~AFPData() { /*free(this->value);*/ }

AFPData::AFPData( const AFPData & oldData ) {
	// Duplicate the inner data
	void* newVal;
	
	if (oldData.type == D_STRING) {
		string* s = new string("");
		s->append(*(string*)oldData.value);
		newVal = (void*)s;
	} else if (oldData.type == D_INTEGER) {
		newVal = (void*) new long int(*(long int*)oldData.value);
	} else if (oldData.type == D_FLOAT) {
		newVal = (void*) new double(*(double*)oldData.value);
	}
	
	this->makeData( oldData.type, newVal, oldData.size );
}

long int AFPData::toInt() {
	if (this->type == D_INTEGER) {
		return *(long int*)this->value;
	}
	
	AFPData intData(*this,D_INTEGER);
	
	return intData.toInt();
}

double AFPData::toFloat() {
	if (this->type == D_FLOAT) {
		return *(double*)this->value;
	}

	AFPData floatData(*this,D_FLOAT);
	
	return floatData.toFloat();
}

bool AFPData::toBool() {
	return (bool)this->toInt();
}

string AFPData::toString() {
	if (this->type == D_STRING) {
		return *(string*)this->value;
	}
	
	AFPData stringData(*this,D_STRING);
	
	return stringData.toString();
}

AFPData::AFPData( long int var ) {
	long int* i = new long int(var);
	
	this->makeData( D_INTEGER, i, sizeof(long int) );
}

AFPData::AFPData( double var ) {
	double* d = new double(var);
	
	this->makeData( D_FLOAT, d, sizeof(double) );
}

AFPData::AFPData( bool var ) {
	long int* i = new long int(var);
	
	this->makeData( D_INTEGER, i, sizeof(long int) );
}
