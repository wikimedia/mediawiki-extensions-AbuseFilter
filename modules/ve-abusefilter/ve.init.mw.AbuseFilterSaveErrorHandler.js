mw.libs.ve.targetLoader.addPlugin( function () {

	ve.init.mw.AbuseFilterSaveErrorHandler = function () {};

	OO.inheritClass( ve.init.mw.AbuseFilterSaveErrorHandler, ve.init.mw.SaveErrorHandler );

	ve.init.mw.AbuseFilterSaveErrorHandler.static.name = 'abuseFilter';

	ve.init.mw.AbuseFilterSaveErrorHandler.static.matchFunction = function ( data ) {
		return !!ve.getProp( data, 'visualeditoredit', 'edit', 'abusefilter' );
	};

	ve.init.mw.AbuseFilterSaveErrorHandler.static.process = function ( data, target ) {
		var
			$message = $( $.parseHTML( ve.getProp( data, 'visualeditoredit', 'edit', 'warning' ) ) ),
			isWarning = ve.getProp( data, 'visualeditoredit', 'edit', 'code' ) !== 'abusefilter-disallowed';
		// Handle warnings/errors from Extension:AbuseFilter
		target.showSaveError( $message, isWarning, isWarning );
		// Emit event for tracking. TODO: This is a bad design
		target.emit( 'saveErrorAbuseFilter' );
	};

	ve.init.mw.saveErrorHandlerFactory.register( ve.init.mw.AbuseFilterSaveErrorHandler );

} );
