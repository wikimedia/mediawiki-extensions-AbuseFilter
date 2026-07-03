'use strict';

module.exports = {
	clearMocks: true,
	collectCoverage: true,
	collectCoverageFrom: [
		'modules/**/components/*.{js,vue}'
	],
	coveragePathIgnorePatterns: [
		'/node_modules/'
	],
	coverageProvider: 'v8',
	moduleFileExtensions: [
		'js',
		'json',
		'vue'
	],
	moduleNameMapper: {
		'codex.js$': '@wikimedia/codex'
	},
	testEnvironment: 'jsdom',
	testEnvironmentOptions: {
		customExportConditions: [ 'node', 'node-addons' ]
	},
	testPathIgnorePatterns: [
		'<rootDir>/node_modules/'
	],
	transform: {
		'^.+\\.vue$': '<rootDir>/node_modules/@vue/vue3-jest'
	}
};
