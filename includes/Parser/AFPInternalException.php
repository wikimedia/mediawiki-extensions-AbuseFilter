<?php

namespace MediaWiki\Extension\AbuseFilter\Parser;

/**
 * Exceptions from the AbuseFilter parser that should not be reported to the user, because they indicate
 * programming errors or unexpected situations.
 */
class AFPInternalException extends AFPException {
}
