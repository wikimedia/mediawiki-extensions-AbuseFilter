const Page = require( 'wdio-mediawiki/Page' );

class ViewListPage extends Page {
	get title() { return browser.element( '#firstHeading' ); }
	get newFilterButton() { return browser.element( '.oo-ui-buttonElement a' ); }
	open() {
		super.openTitle( 'Special:AbuseFilter' );
	}
}
module.exports = new ViewListPage();
