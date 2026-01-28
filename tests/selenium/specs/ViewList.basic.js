import assert from 'node:assert/strict';
import LoginPage from 'wdio-mediawiki/LoginPage';
import { viewListPage as ViewListPage } from '../pageobjects/viewlist.page.js';

describe( 'Special:AbuseFilter', () => {
	it( 'page should exist on installation', async () => {
		await ViewListPage.open();
		assert.equal( await ViewListPage.title.getText(), 'Abuse filter management' );
	} );
	it( 'page should have the button for creating a new filter', async () => {
		await LoginPage.loginAdmin();
		await ViewListPage.open();
		assert.equal( await ViewListPage.newFilterButton.getText(), 'Create a new filter' );
		const newFilterButton = await ViewListPage.newFilterButton.getAttribute( 'href' );
		assert.notEqual(
			( newFilterButton.indexOf( 'Special:AbuseFilter/new' ) ),
			-1
		);
	} );
} );
