/* eslint-env node */
module.exports = function ( grunt ) {
	grunt.loadNpmTasks( 'grunt-jsonlint' );
	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-eslint' );
	grunt.loadNpmTasks( 'grunt-stylelint' );

	grunt.initConfig( {
		jsonlint: {
			all: [
				'**/*.json',
				'!node_modules/**',
				'!vendor/**'
			]
		},
		banana: {
			all: 'i18n/'
		},
		eslint: {
			all: [
				'**/*.js',
				'!node_modules/**',
				'!vendor/**'
			]
		},
		stylelint: {
			all: [
				'**/*.css',
				'!node_modules/**',
				'!vendor/**'
			]
		}
	} );

	grunt.registerTask( 'test', [ 'jsonlint', 'banana', 'eslint', 'stylelint' ] );
	grunt.registerTask( 'default', 'test' );
};
