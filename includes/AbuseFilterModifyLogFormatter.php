<?php

class AbuseFilterModifyLogFormatter extends LogFormatter {

	protected function getMessageKey() {
		return 'abusefilter-logentry-modify';
	}

	/**
	 * @return array
	 */
	protected function extractParameters() {
		$parameters = $this->entry->getParameters();
		if ( $this->entry->isLegacy() ) {
			list( $historyId, $filterId ) = $parameters;
		} else {
			$historyId = $parameters['historyId'];
			$filterId = $parameters['newId'];
		}

		$detailsTitle = SpecialPage::getTitleFor(
			'AbuseFilter',
			"history/$filterId/diff/prev/$historyId"
		);

		$params = [];
		$params[3] = Message::rawParam(
			$this->makePageLink(
				$this->entry->getTarget(),
				[],
				$this->msg( 'abusefilter-log-detailedentry-local' )
					->numParams( $filterId )->escaped()
			)
		);
		$params[4] = Message::rawParam(
			$this->makePageLink(
				$detailsTitle,
				[],
				$this->msg( 'abusefilter-log-detailslink' )->escaped()
			)
		);

		return $params;
	}

}
