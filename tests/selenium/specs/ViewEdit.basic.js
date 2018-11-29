var assert = require( 'assert' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	ViewEditPage = require( '../pageobjects/viewedit.page' );

describe( 'Special:AbuseFilter/new', function () {
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
			'',
			'the hidden rules editor should be empty'
		);
	} );
} );
