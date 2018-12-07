mw.libs.ve.targetLoader.addPlugin( function () {

	ve.init.mw.AbuseFilterSaveErrorHandler = function () {};

	OO.inheritClass( ve.init.mw.AbuseFilterSaveErrorHandler, ve.init.mw.SaveErrorHandler );

	ve.init.mw.AbuseFilterSaveErrorHandler.static.name = 'abuseFilter';

	ve.init.mw.AbuseFilterSaveErrorHandler.static.matchFunction = function ( data ) {
		return !!ve.getProp( data, 'visualeditoredit', 'edit', 'abusefilter' );
	};

	ve.init.mw.AbuseFilterSaveErrorHandler.static.process = function ( data, target ) {
		// Handle warnings/errors from Extension:AbuseFilter
		target.showSaveError( $( $.parseHTML( ve.getProp( data, 'visualeditoredit', 'edit', 'warning' ) ) ) );
		// Don't disable the save button. If the action is not disallowed the user may save the
		// edit by pressing Save again. The AbuseFilter API currently has no way to distinguish
		// between filter triggers that are and aren't disallowing the action.
		// Emit event for tracking. TODO: This is a bad design
		target.emit( 'saveErrorAbuseFilter' );
	};

	ve.init.mw.saveErrorHandlerFactory.register( ve.init.mw.AbuseFilterSaveErrorHandler );

} );
