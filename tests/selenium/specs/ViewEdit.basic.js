var assert = require( 'assert' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	ViewEditPage = require( '../pageobjects/viewedit.page' );

describe( 'Special:AbuseFilter/new', function () {
	it( 'logged-out users cannot edit filters', function () {
		ViewEditPage.open( 'new' );

		assert.ok( ViewEditPage.error );
	} );

	it( 'the main elements should be correct', function () {
		LoginPage.loginAdmin();
		ViewEditPage.open( 'new' );

		assert.equal(
			ViewEditPage.title.getText(),
			'Editing abuse filter',
			'the title should be correct'
		);

		assert.equal(
			ViewEditPage.filterId.getText(),
			'New filter',
			'the filter ID should be correct for a new filter'
		);

		assert.equal(
			ViewEditPage.hiddenEditor.getAttribute( 'value' ),
			null,
			'the hidden rules editor should be empty'
		);

		// @todo This assumes that warn is enabled in the config, but it usually is
		assert.ok(
			ViewEditPage.warnParams,
			'Warn action parameters should be on the page'
		);
	} );
} );
