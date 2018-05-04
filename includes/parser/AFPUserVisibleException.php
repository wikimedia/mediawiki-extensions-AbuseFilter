<?php

// Exceptions that we might conceivably want to report to ordinary users
// (i.e. exceptions that don't represent bugs in the extension itself)
class AFPUserVisibleException extends AFPException {
	/** @var string */
	public $mExceptionID;
	/** @var int */
	public $mPosition;
	/** @var array */
	public $mParams;

	/**
	 * @param string $exception_id
	 * @param int $position
	 * @param array $params
	 */
	public function __construct( $exception_id, $position, $params ) {
		$this->mExceptionID = $exception_id;
		$this->mPosition = $position;
		$this->mParams = $params;

		// Exception message text for logs should be in English.
		$msg = $this->getMessageObj()->inLanguage( 'en' )->useDatabase( false )->text();
		parent::__construct( $msg );
	}

	/**
	 * @return Message
	 */
	public function getMessageObj() {
		// Give grep a chance to find the usages:
		// abusefilter-exception-unexpectedatend, abusefilter-exception-expectednotfound
		// abusefilter-exception-unrecognisedkeyword, abusefilter-exception-unexpectedtoken
		// abusefilter-exception-unclosedstring, abusefilter-exception-invalidoperator
		// abusefilter-exception-unrecognisedtoken, abusefilter-exception-noparams
		// abusefilter-exception-dividebyzero, abusefilter-exception-unrecognisedvar
		// abusefilter-exception-notenoughargs, abusefilter-exception-regexfailure
		// abusefilter-exception-overridebuiltin, abusefilter-exception-outofbounds
		// abusefilter-exception-notarray, abusefilter-exception-unclosedcomment
		// abusefilter-exception-invalidiprange, abusefilter-exception-disabledvar
		return wfMessage(
			'abusefilter-exception-' . $this->mExceptionID,
			$this->mPosition, ...$this->mParams
		);
	}
}
