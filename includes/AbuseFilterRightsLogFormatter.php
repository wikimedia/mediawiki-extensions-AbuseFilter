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

	/**
	 * @inheritDoc
	 */
	protected function extractParameters() {
		$ret = [];
		$ret[3] = $this->entry->getTarget()->getText();
		if ( $this->entry->getSubType() === 'blockautopromote' ) {
			$parameters = $this->entry->getParameters();
			$duration = $parameters['7::duration'];
			// @fixme Temporary for back-compat, all entries will have an int here
			if ( is_int( $duration ) ) {
				$ret[4] = $this->context->getLanguage()->formatDuration( $duration );
			} else {
				$ret[4] = $duration;
			}
		}
		return $ret;
	}
}
