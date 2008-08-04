#include "afeval.h"
#include "affunctions.h"

void FilterEvaluator::reset() {
	this->vars.clear();
	this->tokens.clear();
	this->cur = AFPToken();
	this->pos = 0;
	this->code = "";
	this->forceResult = false;
}

void FilterEvaluator::setVar( string key, AFPData value ) {
	this->vars[key] = AFPData(value);
}

void FilterEvaluator::setVars( map<string,AFPData> values ) {
	for (map<string,AFPData>::iterator it=values.begin();it!=values.end();++it) {
		this->setVar( it->first, it->second );
	}
}

string FilterEvaluator::evaluateExpression( string code ) {
	this->code = code;
	this->pos = 0;
	
	if (this->tokenCache.find(code) != this->tokenCache.end()) {
		this->tokens = this->tokenCache[code];
	} else {
		this->tokenCache[code] = this->tokens = af_parse( code );
	}
	
	if (this->tokenCache.size() > 100) {
		this->tokenCache.clear();
	}

	this->cur = this->tokens[0];
	
	AFPData result;
	
	this->doLevelEntry( &result );
	
	return result.toString();
}

bool FilterEvaluator::evaluateFilter( string code ) {
	this->code = code;
	this->pos = 0;
	
	if (this->tokenCache.find(code) != this->tokenCache.end()) {
		this->tokens = this->tokenCache[code];
	} else {
		this->tokenCache[code] = this->tokens = af_parse( code );
	}
	
	if (this->tokenCache.size() > 100) {
		this->tokenCache.clear();
	}

	this->cur = this->tokens[0];
	
	AFPData result;
	
	this->doLevelEntry( &result );
	
	return result.toBool();
}

bool FilterEvaluator::move() { this->move(1); }

bool FilterEvaluator::move( int shift ) {
	this->pos += shift;
	
	if (this->pos >= 0 && this->pos < this->tokens.size()) {
		this->cur = this->tokens[this->pos];
		return true;
	} else {
		this->pos -= shift;
		return false;
	}
}

void FilterEvaluator::doLevelEntry( AFPData* result ) {
	this->doLevelSet( result );
	
	if (this->cur.type != T_NONE) {
		throw AFPException( "Unexpected tokens at end." );
	}
}

void FilterEvaluator::doLevelSet( AFPData* result ) {
	if (this->cur.type == T_ID) {
		string varName = this->cur.value;
		
		this->move();
		
		if (this->cur.type == T_OP && this->cur.value == "=") {
			this->move();
			this->doLevelSet( result );
			this->vars[varName] = AFPData(result);
			return;
		}
		this->move(-1);
	}
	
	this->doLevelBoolOps( result );
}

vector<string> getOpsForType( string type ) {
	static map<string,vector<string> > oft;
	
	if (oft.empty()) {
		vector<string> cv;
		
		cv = vector<string>();
		cv.push_back("&");
		cv.push_back("|");
		cv.push_back("^");
		oft["bool"] = cv;
		
		cv = vector<string>();
		cv.push_back( "==" );
		cv.push_back( "===" );
		cv.push_back( "!=" );
		cv.push_back( "!==" );
		cv.push_back( "<" );
		cv.push_back( ">" );
		cv.push_back( "<=" );
		cv.push_back( ">=" );
		oft["compare"] = cv;
		
		cv = vector<string>();
		cv.push_back( "*" );
		cv.push_back( "/" );
		cv.push_back( "%" );
		oft["mulrel"] = cv;
		
		cv = vector<string>();
		cv.push_back( "+" );
		cv.push_back( "-" );
		oft["sumrel"] = cv;
		
		cv = vector<string>();
		cv.push_back( "in" );
		cv.push_back( "like" );
		oft["special"] = cv;
	}
	
	return oft[type];
}

void FilterEvaluator::doLevelBoolOps( AFPData* result ) {
	bool setForce = false;
	
	this->doLevelCompares( result );
	
	vector<string> ops = getOpsForType( "bool" );
	
	while ( this->cur.type == T_OP && isInVector( this->cur.value, ops ) ) {
		string op = this->cur.value;
		this->move();
		AFPData r2;
		
		if (!this->forceResult && op == "&" && !result->toBool()) {
			setForce = true;
			this->forceResult = true;
		} else if (!this->forceResult && op == "|" && result->toBool()) {
			setForce = true;
			this->forceResult = true;
		}
		
		this->doLevelCompares( &r2 );
		
		if (!this->forceResult) {
			*result = af_boolOp( *result, r2, op );
		} else if (setForce) {
			setForce = false;
			this->forceResult = false;
		}
	}
	
	if (setForce)
		this->forceResult = false;
}

void FilterEvaluator::doLevelCompares( AFPData* result ) {
	this->doLevelMulRels( result );
	vector<string> ops = getOpsForType( "compare" );
	
	while (this->cur.type == T_OP && isInVector( this->cur.value, ops ) ) {
		string op = this->cur.value;
		this->move();
		AFPData r2;
		
		this->doLevelMulRels( &r2 );
		
		if (!this->forceResult)
			*result = af_compareOp( *result, r2, op );
	}
}

void FilterEvaluator::doLevelMulRels( AFPData* result ) {
	this->doLevelSumRels( result );
	vector<string> ops = getOpsForType( "mulrel" );
	
	while (this->cur.type == T_OP && isInVector( this->cur.value, ops ) ) {
		string op = this->cur.value;
		this->move();
		AFPData r2;
		
		this->doLevelSumRels( &r2 );
		
		if (!this->forceResult)
			*result = af_mulRel( *result, r2, op );
	}
}

void FilterEvaluator::doLevelSumRels( AFPData* result ) {
	this->doLevelPow( result );
	vector<string> ops = getOpsForType( "sumrel" );
	
	while (this->cur.type == T_OP && isInVector( this->cur.value, ops ) ) {
		string op = this->cur.value;
		this->move();
		AFPData r2;
		
		this->doLevelPow( &r2 );
		
		if (op == "+") {
			if (!this->forceResult)
				*result = af_sum( *result, r2 );
		} else if (op == "-") {
			if (!this->forceResult)
				*result = af_sub( *result, r2 );
		}
	}
}	

void FilterEvaluator::doLevelPow( AFPData* result ) {
	this->doLevelBoolInvert( result );
	
	while (this->cur.type == T_OP && this->cur.value == "**" ) {
		this->move();
		AFPData exp;
		
		this->doLevelBoolInvert( &exp );
		
		if (!this->forceResult)
			*result = af_pow( *result, exp );
	}
}

void FilterEvaluator::doLevelBoolInvert( AFPData* result ) {
	if (this->cur.type == T_OP && this->cur.value == "!") {
		this->move();
		this->doLevelSpecialWords( result );
		if (!this->forceResult)
			*result = af_boolInvert( *result );
	} else {
		this->doLevelSpecialWords( result );
	}
}

void FilterEvaluator::doLevelSpecialWords( AFPData* result ) {
	this->doLevelUnarys( result );
	vector<string> specwords = getOpsForType( "special" );
	
	if (this->cur.type == T_KEYWORD && isInVector( this->cur.value, specwords )) {
		string keyword = this->cur.value;
		
		this->move();
		AFPData r2 = AFPData();
		this->doLevelUnarys( &r2 );
		
		if (!this->forceResult)
			*result = af_keyword( keyword, *result, r2 );
	}
}

void FilterEvaluator::doLevelUnarys( AFPData* result ) {
	if (this->cur.type == T_OP && (this->cur.value == "+" || this->cur.value == "-") ) {
		this->move();
		this->doLevelBraces( result );
		if (this->cur.value == "-") {
			if (!this->forceResult)
				*result = af_unaryMinus( *result );
		}
	} else {
		this->doLevelBraces( result );
	}
}

void FilterEvaluator::doLevelBraces( AFPData* result ) {
	if (this->cur.type == T_BRACE && this->cur.value == "(") {
		this->move();
		this->doLevelSet( result );
		
		if ( !(this->cur.type == T_BRACE && this->cur.value == ")") ) {
			throw AFPException( "Expected ')' at pos %d", this->cur.pos );
		}
		this->move();
	} else {
		this->doLevelFunction( result );
	}
}

void FilterEvaluator::doLevelFunction( AFPData* result ) {
	if ( this->cur.type == T_ID && isFunction( this->cur.value ) ) {
		string func = this->cur.value;
		this->move();
		
		if (this->cur.type != T_BRACE || this->cur.value != "(") {
			throw AFPException( "Expected (" );
		}
		this->move();
		
		vector<AFPData> args = vector<AFPData>();
		
		if (this->cur.type != T_BRACE || this->cur.value != ")") {
			this->move(-1);
			do {
				this->move();
				AFPData r = new AFPData();
				this->doLevelSet( &r );
				args.push_back( r );
			} while (this->cur.type == T_COMMA );
		}
		
		if (!this->forceResult)
			*result = callFunction( func, args );
		this->move();
	} else {
		this->doLevelAtom( result );
	}
}

void FilterEvaluator::doLevelAtom( AFPData* result ) {
	string tok = this->cur.value;
	
	switch (this->cur.type) {
		 case T_ID:
			if (this->vars.find(tok) != this->vars.end()) {
				*result = this->vars[tok];
			} else {
				*result = AFPData();
			}
			break;
		case T_STRING:
		case T_NUMBER:
			*result = AFPData( tok );
			break;
		case T_KEYWORD:
			if (tok == "true") {
				*result = AFPData( true );
			} else if (tok == "false") {
				*result = AFPData( false );
			} else if (tok == "null") {
				*result = AFPData();
			} else {
				throw AFPException( "Unidentifiable keyword" );
			}
			break;
		case T_BRACE:
			if (tok == ")") {
				return;
			}
			break;
		case T_COMMA:
			return;
			break;
		default: throw AFPException( "Unexpected token value %s", this->cur.value.c_str() );
	}
	
	this->move();
}
