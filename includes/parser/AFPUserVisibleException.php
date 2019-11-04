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

		parent::__construct( $exception_id );
	}

	/**
	 * Change the message of the exception to a localized version
	 */
	public function setLocalizedMessage() {
		$this->message = $this->getMessageObj()->text();
	}

	/**
	 * Returns the error message in English for use in logs
	 *
	 * @return string
	 */
	public function getMessageForLogs() {
		return $this->getMessageObj()->inLanguage( 'en' )->useDatabase( false )->text();
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
		// abusefilter-exception-variablevariable, abusefilter-exception-toomanyargs
		// abusefilter-exception-negativeoffset
		return wfMessage(
			'abusefilter-exception-' . $this->mExceptionID,
			$this->mPosition, ...$this->mParams
		);
	}
}
