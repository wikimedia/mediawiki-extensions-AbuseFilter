import assert from 'node:assert/strict';
import { viewEditPage as ViewEditPage } from '../pageobjects/viewedit.page.js';

describe( 'Filter editing', () => {
	it( 'editing interface is not visible to logged-out users', async () => {
		await ViewEditPage.open( 'new' );
		assert( await ViewEditPage.error.isDisplayed() );
	} );
} );
