<?php

class AbuseFilterChangesList extends OldChangesList {

	/**
	 * @var string
	 */
	private $testFilter;

	/**
	 * @param Skin $skin
	 * @param string $testFilter
	 */
	public function __construct( Skin $skin, $testFilter ) {
		parent::__construct( $skin );
		$this->testFilter = $testFilter;
	}

	/**
	 * @param string &$s
	 * @param RecentChange &$rc
	 * @param string[] &$classes
	 * @suppress PhanUndeclaredProperty for $rc->filterResult, which isn't a big deal
	 */
	public function insertExtra( &$s, &$rc, &$classes ) {
		$examineParams = [];
		if ( $this->testFilter ) {
			$examineParams['testfilter'] = $this->testFilter;
		}

		$title = SpecialPage::getTitleFor( 'AbuseFilter', 'examine/' . $rc->mAttribs['rc_id'] );
		$examineLink = $this->linkRenderer->makeLink(
			$title,
			new HtmlArmor( $this->msg( 'abusefilter-changeslist-examine' )->parse() ),
			[],
			$examineParams
		);

		$s .= ' '.$this->msg( 'parentheses' )->rawParams( $examineLink )->escaped();

		// If we have a match...
		if ( isset( $rc->filterResult ) ) {
			$class = $rc->filterResult ?
				'mw-abusefilter-changeslist-match' :
				'mw-abusefilter-changeslist-nomatch';

			$classes[] = $class;
		}
	}

	/**
	 * @param string &$s
	 * @param RecentChange &$rc
	 */
	public function insertRollback( &$s, &$rc ) {
		// Kill rollback links.
	}
}
