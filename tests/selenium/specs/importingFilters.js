'use strict';

const assert = require( 'assert' ),
	LoginPage = require( 'wdio-mediawiki/LoginPage' ),
	ViewEditPage = require( '../pageobjects/viewedit.page' ),
	ViewListPage = require( '../pageobjects/viewlist.page' ),
	ViewImportPage = require( '../pageobjects/viewimport.page' );

describe( 'When importing a filter', function () {
	const filterSpecs = {
			name: 'My filter name',
			comments: 'Notes go here.',
			rules: 'true === false',
			enabled: 1,
			hidden: 1,
			deleted: 0
		},
		warnMessage = 'abusefilter-warning-foobar';

	function getImportData() {
		return `{"row":{"af_id":"242","af_pattern":"${filterSpecs.rules}","af_user":"1","af_user_text":\
"Daimona Eaytoy","af_timestamp":"20200924132008","af_enabled":"${filterSpecs.enabled}","af_comments":"\
${filterSpecs.comments}","af_public_comments":"${filterSpecs.name}","af_hidden":"${filterSpecs.hidden}",\
"af_hit_count":"0","af_throttled":"0","af_deleted":"${filterSpecs.deleted}","af_actions":"warn","af_global":\
"0","af_group":"default"},"actions":{"warn":["${warnMessage}"]}}`;
	}

	before( function () {
		LoginPage.loginAdmin();
	} );

	it( 'the interface should be visible', function () {
		ViewImportPage.open();
		assert( ViewImportPage.importData.isDisplayed() );
	} );

	it( 'it should redirect to ViewEdit after submission', function () {
		ViewImportPage.importText( 'SOME INVALID GIBBERISH' );
		assert( /\/new$/.test( browser.getUrl() ) );
	} );

	it( 'bad data results in an error', function () {
		assert( ViewEditPage.error.isDisplayed() );
	} );

	it( 'valid data shows the editing interface', function () {
		ViewImportPage.open();
		ViewImportPage.importText( getImportData() );
		assert( ViewEditPage.name.isDisplayed() );
	} );

	describe( 'Data on the editing interface is correct', function () {
		it( 'filter specs are copied', function () {
			assert.strictEqual( ViewEditPage.name.getValue(), filterSpecs.name );
			assert.strictEqual( ViewEditPage.comments.getValue(), filterSpecs.comments + '\n' );
			assert.strictEqual( ViewEditPage.rules.getValue(), filterSpecs.rules + '\n' );
		} );
		it( 'filter flags are copied', function () {
			assert.strictEqual( ViewEditPage.enabled.isSelected(), !!filterSpecs.enabled );
			assert.strictEqual( ViewEditPage.hidden.isSelected(), !!filterSpecs.hidden );
			assert.strictEqual( ViewEditPage.deleted.isSelected(), !!filterSpecs.deleted );
		} );
		it( 'filter actions are copied', function () {
			assert.strictEqual( ViewEditPage.warnCheckbox.isSelected(), true );
			assert.strictEqual( ViewEditPage.warnOtherMessage.getValue(), warnMessage );
		} );

		it( 'the imported data can be saved', function () {
			ViewEditPage.submit();
			assert( ViewListPage.filterSavedNotice.isDisplayed() );
		} );
	} );
} );
