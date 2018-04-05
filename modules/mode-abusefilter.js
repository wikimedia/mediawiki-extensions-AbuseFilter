/* global ace, mw */
ace.define( 'ace/mode/abusefilter_highlight_rules', [ 'require', 'exports', 'module', 'ace/lib/oop', 'ace/mode/text_highlight_rules' ], function ( require, exports, module ) {
	'use strict';

	var oop = require( 'ace/lib/oop' ),
		TextHighlightRules = require( './text_highlight_rules' ).TextHighlightRules,
		AFHighlightRules = function () {

			var keywords = ( mw.config.get( 'aceConfig' ).keywords ),
				constants = ( 'true|false|null' ),
				functions = ( mw.config.get( 'aceConfig' ).functions ),
				variables = ( mw.config.get( 'aceConfig' ).variables ),
				deprecated = ( '' ), // Template for deprecated vars, already registered within ace settings.
				keywordMapper = this.createKeywordMapper(
					{
						'keyword': keywords,
						'support.function': functions,
						'constant.language': constants,
						'variable.language': variables,
						'keyword.deprecated': deprecated
					},
					'identifier'
				),
				decimalInteger = '(?:(?:[1-9]\\d*)|(?:0))',
				hexInteger = '(?:0[xX][\\dA-Fa-f]+)',
				integer = '(?:' + decimalInteger + '|' + hexInteger + ')',
				fraction = '(?:\\.\\d+)',
				intPart = '(?:\\d+)',
				pointFloat = '(?:(?:' + intPart + '?' + fraction + ')|(?:' + intPart + '\\.))',
				floatNumber = '(?:' + pointFloat + ')';

			this.$rules = {
				'start': [ {
					token: 'comment',
					regex: '\\/\\*',
					next: 'comment'
				}, {
					token: 'string', // " string
					regex: '"(?:[^\\\\]|\\\\.)*?"'
				}, {
					token: 'string', // ' string
					regex: "'(?:[^\\\\]|\\\\.)*?'"
				}, {
					token: 'constant.numeric', // float
					regex: floatNumber
				}, {
					token: 'constant.numeric', // integer
					regex: integer + '\\b'
				}, {
					token: keywordMapper,
					regex: '[a-zA-Z_$][a-zA-Z0-9_$]*\\b'
				}, {
					token: 'keyword.operator',
					regex: '\\+|\\-|\\*\\*|\\*|\\/|%|\\^|&|\\||<|>|<=|=>|==|!=|===|!==|:=|=|!'
				}, {
					token: 'paren.lparen',
					regex: '[\\[\\(]'
				}, {
					token: 'paren.rparen',
					regex: '[\\]\\)]'
				}, {
					token: 'text',
					regex: '\\s+|\\w+'
				} ],
				'comment': [ {
					token: 'comment',
					regex: '\\*\\/',
					next: 'start'
				}, {
					defaultToken: 'comment'
				} ]
			};

			this.normalizeRules();
		};

	oop.inherits( AFHighlightRules, TextHighlightRules );

	exports.AFHighlightRules = AFHighlightRules;
} );

ace.define( 'ace/mode/abusefilter', [ 'require', 'exports', 'module', 'ace/lib/oop', 'ace/mode/text', 'ace/mode/abusefilter_highlight_rules' ], function ( require, exports, module ) {
	'use strict';

	var oop = require( 'ace/lib/oop' ),
		TextMode = require( './text' ).Mode,
		AFHighlightRules = require( './abusefilter_highlight_rules' ).AFHighlightRules,
		MatchingBraceOutdent = require( './matching_brace_outdent' ).MatchingBraceOutdent,
		Mode = function () {
			this.HighlightRules = AFHighlightRules;
			this.$behaviour = this.$defaultBehaviour;
			this.$outdent = new MatchingBraceOutdent();
		};
	oop.inherits( Mode, TextMode );

	( function () {
		this.blockComment = {
			start: '/*',
			end: '*/'
		};
		this.getNextLineIndent = function ( state, line, tab ) {
			var indent = this.$getIndent( line );
			return indent;
		};
		this.checkOutdent = function ( state, line, input ) {
			return this.$outdent.checkOutdent( line, input );
		};
		this.autoOutdent = function ( state, doc, row ) {
			this.$outdent.autoOutdent( doc, row );
		};

		this.$id = 'ace/mode/abusefilter';
	} )
		.call( Mode.prototype );

	exports.Mode = Mode;
} );
