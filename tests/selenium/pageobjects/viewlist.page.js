const Page = require( 'wdio-mediawiki/Page' );

class ViewListPage extends Page {
	get title() { return $( '#firstHeading' ); }
	get newFilterButton() { return $( '.oo-ui-buttonElement a' ); }
	open() {
		super.openTitle( 'Special:AbuseFilter' );
	}
}
module.exports = new ViewListPage();
