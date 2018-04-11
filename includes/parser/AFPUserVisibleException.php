<?php

// Exceptions that we might conceivably want to report to ordinary users
// (i.e. exceptions that don't represent bugs in the extension itself)
class AFPUserVisibleException extends AFPException {
	public $mExceptionId;
	public $mPosition;
	public $mParams;

	/**
	 * @param string $exception_id
	 * @param int $position
	 * @param array $params
	 */
	function __construct( $exception_id, $position, $params ) {
		$this->mExceptionID = $exception_id;
		$this->mPosition = $position;
		$this->mParams = $params;

		// Exception message text for logs should be in English.
		$msg = $this->getMessageObj()->inLanguage( 'en' )->useDatabase( false )->text();
		parent::__construct( $msg );
	}

	public function getMessageObj() {
		// Give grep a chance to find the usages:
		// abusefilter-exception-unexpectedatend, abusefilter-exception-expectednotfound
		// abusefilter-exception-unrecognisedkeyword, abusefilter-exception-unexpectedtoken
		// abusefilter-exception-unclosedstring, abusefilter-exception-invalidoperator
		// abusefilter-exception-unrecognisedtoken, abusefilter-exception-noparams
		// abusefilter-exception-dividebyzero, abusefilter-exception-unrecognisedvar
		// abusefilter-exception-notenoughargs, abusefilter-exception-regexfailure
		// abusefilter-exception-overridebuiltin, abusefilter-exception-outofbounds
		// abusefilter-exception-notlist, abusefilter-exception-unclosedcomment
		return wfMessage(
			'abusefilter-exception-' . $this->mExceptionID,
			array_merge( [ $this->mPosition ], $this->mParams )
		);
	}
}
