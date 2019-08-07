<?php

class AbuseFilterRightsLogFormatter extends LogFormatter {

	/**
	 * @return string
	 */
	protected function getMessageKey() {
		$subtype = $this->entry->getSubtype();
		// Messages that can be used here:
		// * logentry-rights-blockautopromote
		// * logentry-rights-restoreautopromote
		return "logentry-rights-$subtype";
	}
}
