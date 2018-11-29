const Page = require( 'wdio-mediawiki/Page' );

class ViewEditPage extends Page {
	get title() { return browser.element( '#firstHeading' ); }
	get filterId() { return browser.element( '#mw-abusefilter-edit-id .mw-input' ); }
	get hiddenEditor() { return browser.element( '#wpFilterRules' ); }
	open( id ) {
		super.openTitle( 'Special:AbuseFilter/' + id );
	}
}
module.exports = new ViewEditPage();
