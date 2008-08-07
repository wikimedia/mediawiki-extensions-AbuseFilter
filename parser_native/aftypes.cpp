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
	this->makeData( new_type, new_value, new_size, "full constructor" );
}

void AFPData::makeData( unsigned int new_type, void* new_value, size_t new_size, string new_source ) {
	this->type = new_type;
	this->value = new_value;
	this->size = new_size;
	this->source = new_source;
	
 	if (this->type > DATATYPE_MAX) {
		// Something funky's going on
// 		cerr << "Something funky. Trying to construct a datum with type " << this->type << ", source is " << new_source << endl;
		return;
	}
}

AFPData::AFPData( string var ) {
	const char* c_str = var.c_str();
	long int intval;
	double fval;
	char* last_char;
	istringstream ss(var);
	
	this->source = "string constructor";
	
	// Try integer	
	if (!!(ss >> intval) && intval != 0) { // 0.25 converts to 0, otherwise.
		// Valid conversion
		long int* val = new long int( intval );
		this->makeData( D_INTEGER, (void*)val, sizeof(long int), "string constructor" );
		return;
	}
	
	if (!!(ss >> fval)) {
		double* val = new double(fval);
		this->makeData( D_FLOAT, (void*)val, sizeof(double), "string constructor" );
		return;
	}
	
	// Last resort
	// Duplicate the string.
	string* s = new string(var);
	this->makeData( D_STRING, (void*)s, sizeof(string), "string constructor" );
	return;
}

AFPData::AFPData( AFPData old, unsigned int newType ) {
	if (old.type > DATATYPE_MAX) {
		// Non-existent type
		throw AFPException( "Given junk data" );
	}

	if (old.type == newType) {
		void* newVal;
		
		// Duplicate the contents.
		if (old.type == D_STRING) {
			string* s = new string();
			s->append(old.toString());
			newVal = (void*) s;
		} else if (old.type == D_INTEGER) {
			newVal = (void*) new long int(old.toInt());
		} else if (old.type == D_FLOAT) {
			newVal = (void*) new double(old.toFloat());
		}
		
		this->makeData( old.type, newVal, old.size, "cast constructor (copy)" );
	} else if (newType == 0) {
		this->makeData( D_NULL, NULL, 0, "cast constructor - null" );
		return;
	} else if (newType == D_INTEGER) {
		if (old.type==D_FLOAT) {
			long int* val = new long int(old.toFloat());
			this->makeData( D_INTEGER, (void*)val, sizeof(long int), "cast constructor - float2int" );
			return;
		} else if (old.type==D_STRING) {
			long int* val = new long int();
			istringstream ss(old.toString());
			
			ss >> *val;
			
			this->makeData( D_INTEGER, (void*)val, sizeof(long int), "cast constructor - string2int" );
			return;
		} else if (old.type==D_NULL) {
			long int* val = new long int(0);
			this->makeData( D_INTEGER, (void*)val, sizeof(long int), "cast constructor - null2int" );
		}// No other types possible
	} else if (newType == D_FLOAT) {
		if (old.type==D_INTEGER) {
			double* val = new double(old.toInt());
			this->makeData( D_FLOAT, (void*)val, sizeof(double), "cast constructor - int2float" );
			return;
		} else if (old.type==D_STRING) {
			double* val = new double();
			istringstream ss(old.toString());
			
			ss >> *val;
			
			this->makeData( D_FLOAT, (void*)val, sizeof(double), "cast constructor - string2float" );
			return;
		} else if (old.type==D_NULL) {
			double* val = new double(0);
			this->makeData( D_FLOAT, (void*)val, sizeof(double), "cast constructor - null2float" );
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
			this->makeData( D_STRING, (void*)str, sizeof(string), "cast constructor - num2string" );
			return;
		} else if (old.type==D_NULL) {
			string* s = new string("");
			this->makeData( D_STRING, (void*)s, sizeof(string), "cast constructor - null2string" );
		} // No other types possible
	}
	
	if (this->type > DATATYPE_MAX) {
		// Non-existent type
		throw AFPException( "Created junk data" );
	}
}

AFPData::AFPData() { this->source = "empty constructor"; this->makeData( 0, NULL, 0, "empty constructor" );}

AFPData::~AFPData() { this->release(); }

void AFPData::release() {
	if (this->value == 0x0) {
		return;
	} else if (this->type > DATATYPE_MAX) {
		// Something funky's going on
// 		cerr << "Something funky. Trying to destruct a datum with type " << this->type << endl;
		return;
	}
	
// 	cerr << "Freeing " << this->value << " - type " << this->type << " - source " << this->source << endl;
	
	switch (this->type) {
		case D_FLOAT:
			delete (double*)this->value;
			break;
		case D_INTEGER:
			delete (long int*)this->value;
			break;
		case D_STRING:
			delete (string*)this->value;
			break;
// 		default:
// 			delete this->value;
	}
	
	this->value = 0x0;
	this->type = D_NULL;
}

AFPData::AFPData( const AFPData & oldData ) {
	this->source = "copy constructor";
	
 	if (oldData.type > DATATYPE_MAX) {
		// Something funky's going on
// 		cerr << "Something funky. Trying to copy a datum with type " << oldData.type << ", source " << oldData.source << endl;
		return;
	}
	
	// Duplicate the inner data
	void* newVal;
	
	if (oldData.type == D_STRING) {
		string* ival = new string();
		*ival = *(string*)oldData.value;
		newVal = (void*)ival;
	} else if (oldData.type == D_INTEGER) {
		long int* ival = new long int;
		*ival = *(long int*)oldData.value;
		newVal = (void*)ival;
	} else if (oldData.type == D_FLOAT) {
		double* ival = new double;
		*ival = *(double*)oldData.value;
		newVal = (void*)ival;
	} else if (oldData.type == D_NULL) {
		newVal = 0;
	} else {
// 		cerr << "Asked to copy an unknown type " << oldData.type << endl;
	}
	
	this->makeData( oldData.type, newVal, oldData.size, "copy constructor" );
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
	
	this->makeData( D_INTEGER, i, sizeof(long int), "int constructor" );
}

AFPData::AFPData( double var ) {
	double* d = new double(var);
	
	this->makeData( D_FLOAT, d, sizeof(double), "double constructor" );
}

AFPData::AFPData( bool var ) {
	long int* i = new long int(var);
	
	this->makeData( D_INTEGER, i, sizeof(long int), "bool constructor" );
}

unsigned int AFPData::getType() { return this->type; }

AFPData & AFPData::operator= (const AFPData & oldData) {
	// Protect against self-assignment
	if (this == &oldData) {
		return *this;
	}
	
	// Clear it.
	this->release();
	
	// NULLs and INVALID data types need no deep copy
	if (oldData.type > DATATYPE_MAX || oldData.type == D_NULL) {
		this->makeData( 0, NULL, 0, "assignment operator" );
		return *this;
	}
	
	// Otherwise, do a proper copy.
	// Duplicate the inner data
	void* newVal;
	if (oldData.type == D_STRING) {
		string* ival = new string();
		*ival = *(string*)oldData.value;
		newVal = (void*)ival;
	} else if (oldData.type == D_INTEGER) {
		long int* ival = new long int;
		*ival = *(long int*)oldData.value;
		newVal = (void*)ival;
	} else if (oldData.type == D_FLOAT) {
		double* ival = new double;
		*ival = *(double*)oldData.value;
		newVal = (void*)ival;
	} else if (oldData.type == D_NULL) {
		newVal = 0;
	} else {
// 		cerr << "Asked to copy an unknown type " << oldData.type << endl;
	}
	
	this->makeData( oldData.type, newVal, oldData.size, "assignment operator" );
	
	return *this;
}

bool isInVector( string needle, vector<string> haystack ) {
	for( vector<string>::iterator it=haystack.begin(); it!=haystack.end(); ++it ) {
		string test = *it;
		if (test == needle.c_str()) { return true; }
	}
	
	return false;
}
