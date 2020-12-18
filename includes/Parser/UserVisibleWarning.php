<?php

namespace MediaWiki\Extension\AbuseFilter\Parser;

use Message;

/**
 * A variant of user-visible exception that is not fatal.
 */
class UserVisibleWarning extends AFPUserVisibleException {
	/**
	 * @return Message
	 */
	public function getMessageObj() {
		// Give grep a chance to find the usages:
		// abusefilter-warning-match-empty-regex
		return wfMessage(
			'abusefilter-warning-' . $this->mExceptionID,
			$this->mPosition, ...$this->mParams
		);
	}
}
