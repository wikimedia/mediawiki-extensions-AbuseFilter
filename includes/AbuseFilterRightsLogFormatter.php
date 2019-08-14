<?php

class AbuseFilterRightsLogFormatter extends LogFormatter {

	/**
	 * This method is identical to the parent, but it's redeclared to give grep a chance
	 * to find the messages.
	 * @inheritDoc
	 */
	protected function getMessageKey() {
		$subtype = $this->entry->getSubtype();
		// Messages that can be used here:
		// * logentry-rights-blockautopromote
		// * logentry-rights-restoreautopromote
		return "logentry-rights-$subtype";
	}
}
