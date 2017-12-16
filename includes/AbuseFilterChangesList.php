<?php

class AbuseFilterChangesList extends OldChangesList {
	/**
	 * @param string &$s
	 * @param RecentChange &$rc
	 * @param array &$classes
	 */
	public function insertExtra( &$s, &$rc, &$classes ) {
		$examineParams = empty( $rc->examineParams ) ? [] : $rc->examineParams;

		$title = SpecialPage::getTitleFor( 'AbuseFilter', 'examine/' . $rc->mAttribs['rc_id'] );
		$examineLink = $this->linkRenderer->makeLink(
			$title,
			new HtmlArmor( $this->msg( 'abusefilter-changeslist-examine' )->parse() ),
			[],
			$examineParams
		);

		$s .= ' '.$this->msg( 'parentheses' )->rawParams( $examineLink )->escaped();

		# If we have a match..
		if ( isset( $rc->filterResult ) ) {
			$class = $rc->filterResult ?
				'mw-abusefilter-changeslist-match' :
				'mw-abusefilter-changeslist-nomatch';

			$classes[] = $class;
		}
	}

	public function insertRollback( &$s, &$rc ) {
		// Kill rollback links.
	}
}
