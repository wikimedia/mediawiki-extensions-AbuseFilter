<?php
/**
 * Represents a node of a parser tree.
 */
class AFPTreeNode {
	// Each of the constants below represents a node corresponding to a level
	// of the parser, from the top of the tree to the bottom.

	// ENTRY is always one-element and thus does not have its own node.

	// SEMICOLON is a many-children node, denoting that the nodes have to be
	// evaluated in order and the last value has to be returned.
	const SEMICOLON = 'SEMICOLON';

	// ASSIGNMENT (formerly known as SET) is a node which is responsible for
	// assigning values to variables.  ASSIGNMENT is a (variable name [string],
	// value [tree node]) tuple, INDEX_ASSIGNMENT (which is used to assign
	// values at list offsets) is a (variable name [string], index [tree node],
	// value [tree node]) tuple, and LIST_APPEND has the form of (variable name
	// [string], value [tree node]).
	const ASSIGNMENT = 'ASSIGNMENT';
	const INDEX_ASSIGNMENT = 'INDEX_ASSIGNMENT';
	const LIST_APPEND = 'LIST_APPEND';

	// CONDITIONAL represents both a ternary operator and an if-then-else-end
	// construct.  The format is (condition, evaluated-if-true,
	// evaluated-in-false), all tree nodes.
	const CONDITIONAL = 'CONDITIONAL';

	// LOGIC is a logic operator accepted by AFPData::boolOp.  The format is
	// (operation, left operand, right operand).
	const LOGIC = 'LOGIC';

	// COMPARE is a comparison operator accepted by AFPData::boolOp.  The format is
	// (operation, left operand, right operand).
	const COMPARE = 'COMPARE';

	// SUM_REL is either '+' or '-'.  The format is (operation, left operand,
	// right operand).
	const SUM_REL = 'SUM_REL';

	// MUL_REL is a multiplication-related operation accepted by AFPData::mulRel.
	// The format is (operation, left operand, right operand).
	const MUL_REL = 'MUL_REL';

	// POW is an exponentiation operator.  The format is (base, exponent).
	const POW = 'POW';

	// BOOL_INVERT is a boolean inversion operator.  The format is (operand).
	const BOOL_INVERT = 'BOOL_INVERT';

	// KEYWORD_OPERATOR is one of the binary keyword operators supported by the
	// filter language.  The format is (keyword, left operand, right operand).
	const KEYWORD_OPERATOR = 'KEYWORD_OPERATOR';

	// UNARY is either unary minus or unary plus.  The format is (operator,
	// operand).
	const UNARY = 'UNARY';

	// LIST_INDEX is an operation of accessing a list by an offset.  The format
	// is (list, offset).
	const LIST_INDEX = 'LIST_INDEX';

	// Since parenthesis only manipulate precedence of the operators, they are
	// not explicitly represented in the tree.

	// FUNCTION_CALL is an invocation of built-in function.  The format is a
	// tuple where the first element is a function name, and all subsequent
	// elements are the arguments.
	const FUNCTION_CALL = 'FUNCTION_CALL';

	// LIST_DEFINITION is a list literal.  The $children field contains tree
	// nodes for the values of each of the list element used.
	const LIST_DEFINITION = 'LIST_DEFINITION';

	// ATOM is a node representing a literal.  The only element of $children is a
	// token corresponding to the literal.
	const ATOM = 'ATOM';

	/** @var string Type of the node, one of the constants above */
	public $type;
	/**
	 * Parameters of the value. Typically it is an array of children nodes,
	 * which might be either strings (for parametrization of the node) or another
	 * node. In case of ATOM it's a parser token.
	 * @var AFPTreeNode[]|string[]|AFPToken
	 */
	public $children;

	// Position used for error reporting.
	public $position;

	public function __construct( $type, $children, $position ) {
		$this->type = $type;
		$this->children = $children;
		$this->position = $position;
	}

	public function toDebugString() {
		return implode( "\n", $this->toDebugStringInner() );
	}

	private function toDebugStringInner() {
		if ( $this->type == self::ATOM ) {
			return [ "ATOM({$this->children->type} {$this->children->value})" ];
		}

		$align = function ( $line ) {
			return '  ' . $line;
		};

		$lines = [ "{$this->type}" ];
		foreach ( $this->children as $subnode ) {
			if ( $subnode instanceof AFPTreeNode ) {
				$sublines = array_map( $align, $subnode->toDebugStringInner() );
			} elseif ( is_string( $subnode ) ) {
				$sublines = [ "  {$subnode}" ];
			} else {
				throw new AFPException( "Each node parameter has to be either a node or a string" );
			}

			$lines = array_merge( $lines, $sublines );
		}
		return $lines;
	}
}
