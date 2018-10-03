/* global ace, mw */
ace.define( 'ace/mode/abusefilter_highlight_rules', [ 'require', 'exports', 'module', 'ace/lib/oop', 'ace/mode/text_highlight_rules' ], function ( require, exports ) {
	'use strict';

	var oop = require( 'ace/lib/oop' ),
		TextHighlightRules = require( './text_highlight_rules' ).TextHighlightRules,
		AFHighlightRules = function () {
			var cfg = mw.config.get( 'aceConfig' ),
				constants = ( 'true|false|null' ),
				keywordMapper = this.createKeywordMapper(
					{
						keyword: cfg.keywords,
						'support.function': cfg.functions,
						'constant.language': constants,
						'variable.language': cfg.variables,
						'invalid.deprecated': cfg.deprecated,
						'invalid.illegal': cfg.disabled
					},
					'identifier'
				),
				integer = '(?:(?:[1-9]\\d*)|(?:0))',
				fraction = '(?:\\.\\d+)',
				intPart = '(?:\\d+)',
				pointFloat = '(?:(?:' + intPart + '?' + fraction + ')|(?:' + intPart + '\\.))',
				floatNumber = '(?:' + pointFloat + ')',
				singleQuoteString = '\'(?:[^\\\\]|\\\\.)*?\'',
				doubleQuoteString = '"(?:[^\\\\]|\\\\.)*?"';

			this.$rules = {
				start: [ {
					token: 'comment',
					regex: '\\/\\*',
					next: 'comment'
				}, {
					token: 'string',
					regex: doubleQuoteString
				}, {
					token: 'string',
					regex: singleQuoteString
				}, {
					token: 'constant.numeric',
					regex: floatNumber
				}, {
					token: 'constant.numeric',
					regex: integer + '\\b'
				}, {
					token: keywordMapper,
					regex: '[a-zA-Z_][a-zA-Z0-9_]*\\b'
				}, {
					token: 'keyword.operator',
					regex: cfg.operators
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
				comment: [ {
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

ace.define( 'ace/mode/abusefilter', [ 'require', 'exports', 'module', 'ace/lib/oop', 'ace/mode/text', 'ace/mode/abusefilter_highlight_rules' ], function ( require, exports ) {
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
		this.getNextLineIndent = function ( state, line ) {
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
