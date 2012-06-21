<?php

/**
 * This class formats abuse log notifications.
 */
class AbuseLogHitFormatter extends LogFormatter {

	/**
	 * @return array
	 */
	protected function getMessageParameters() {
		$entry = $this->entry->getParameters();
		$params = parent::getMessageParameters();

		$filter_title = SpecialPage::getTitleFor( 'AbuseFilter', $entry['filter'] );
		$filter_caption = $this->msg( 'abusefilter-log-detailedentry-local' )->params( $entry['filter'] );
		$log_title = SpecialPage::getTitleFor( 'AbuseLog', $entry['log'] );
		$log_caption = $this->msg('abusefilter-log-detailslink' );

		$params[4] = $entry['action'];

		if ( $this->plaintext ) {
			$params[3] = '[[' . $filter_title->getPrefixedText() . '|' . $filter_caption . ']]';
			$params[8] = '[[' . $log_title->getPrefixedText() . '|' . $log_caption . ']]';
		} else {
			$params[3] = Message::rawParam( Linker::link(
				$filter_title,
				htmlspecialchars( $filter_caption )
			) );
			$params[8] = Message::rawParam( Linker::link(
				$log_title,
				htmlspecialchars( $log_caption )
			) );
		}

		$actions_taken = $entry['actions'];
		if ( !strlen( trim( $actions_taken ) ) ) {
			$actions_taken = $this->msg( 'abusefilter-log-noactions' );
		} else {
			$actions = explode( ',', $actions_taken );
			$displayActions = array();

			foreach ( $actions as $action ) {
				$displayActions[] = AbuseFilter::getActionDisplay( $action );
			}
			$actions_taken = $this->context->getLanguage()->commaList( $displayActions );
		}
		$params[5] = $actions_taken;

		// Bad things happen if the numbers are not in correct order
		ksort($params);
		return $params;
	}
}
