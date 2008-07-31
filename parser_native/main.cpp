#include "afeval.h"
#include "affunctions.h"
#include <libxml++/libxml++.h>
#include <iostream>
#include <string>
#include <sstream>
#include <map>

string filter;
map<string,AFPData> vars;

bool loadRequest();

int main( int argc, char** argv ) {
	FilterEvaluator e;
	registerBuiltinFunctions();
	
	while (true) {
		e.reset();
		
		if (!loadRequest())
			continue;
		
		bool result;
		
		try {
			e.setVars( vars );
			result = e.evaluateFilter( filter );
		} catch (AFPException excep) {
			cerr << "EXCEPTION: " << excep.what() << endl;
		}
		
		cout << ( result ? "MATCH\n" : "NOMATCH\n" );
// 		exit(result ? 1 : 0); // Exit 0 means OK, exit 1 means match
	}
}

/* REQUEST FORMAT:
<request>
	<vars>
		<var key="varname">value</var>
	</vars>
	<rule> RULE CONTENT </rule>
</request> */

bool loadRequest() {
	// Parse the XML.
	xmlpp::DomParser parser;
	parser.set_substitute_entities();

	stringbuf sb(ios::out | ios::in);
	cin.get( sb, '\x04' );
	cin.get();
	
	string text = sb.str();
	
	// Remove the NULL
	for( string::iterator it = text.begin(); it!=text.end(); ++it ) {
		if (*it == '\x04') { text.erase(it); }
	}
	
	if (text.size() < 2) {
		return false;
	}
	
	istringstream ss(text);
	parser.parse_stream( ss );
// 	parser.parse_file( "xml.test" );
	xmlpp::Node* rootNode = parser.get_document()->get_root_node();
	
	// Get vars
	xmlpp::Node::NodeList varNodes = rootNode->get_children( "vars" );
	
	if (varNodes.begin() == varNodes.end()) {
		throw AFPException( "Request did not contain any vars" );	
	}
	
	xmlpp::Node::Node* varNode = *(varNodes.begin()); // Get the <vars> element
	varNodes = varNode->get_children( "var" ); // Iterate through <var> child nodes
	for (xmlpp::Node::NodeList::const_iterator it = varNodes.begin(); it!=varNodes.end(); ++it) {
		xmlpp::Element* n = dynamic_cast<xmlpp::Element*>(*it);
		
		string attName = n->get_attribute( "key" )->get_value();
		if (n->has_child_text()) {
			string attValue = n->get_child_text()->get_content();
			vars[attName] = AFPData(attValue);
		} else {
			vars[attName] = "";
		}
	}
	
	//Get code.
	xmlpp::Node::NodeList codeNodes = rootNode->get_children( "rule" );
	
	if (codeNodes.begin() == codeNodes.end()) {
		throw new AFPException( "Request did not contain any filter" );	
	}
	
	xmlpp::Node* codeNode = *(codeNodes.begin());
	xmlpp::Element* codeElement = dynamic_cast<xmlpp::Element*>(codeNode);
	
	filter = codeElement->get_child_text()->get_content();
	
	return true;
}
