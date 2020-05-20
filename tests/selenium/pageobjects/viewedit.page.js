const Page = require( 'wdio-mediawiki/Page' );

class ViewEditPage extends Page {
	get title() { return $( '#firstHeading' ); }
	get filterId() { return $( '#mw-abusefilter-edit-id .mw-input' ); }
	get hiddenEditor() { return $( '#wpFilterRules' ); }
	get warnParams() { return $( '#mw-abusefilter-warn-parameters' ); }
	open( id ) {
		super.openTitle( 'Special:AbuseFilter/' + id );
	}
	get error() { return $( '.errorbox' ); }
}
module.exports = new ViewEditPage();
