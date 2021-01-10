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
	public function getMessageObj() : Message {
		// Give grep a chance to find the usages:
		// abusefilter-warning-match-empty-regex
		return new Message(
			'abusefilter-warning-' . $this->mExceptionID,
			array_merge( [ $this->mPosition ], $this->mParams )
		);
	}
}
