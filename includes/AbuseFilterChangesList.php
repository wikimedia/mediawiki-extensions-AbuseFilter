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
		if ( (int)$rc->getAttribute( 'rc_deleted' ) !== 0 ) {
			$s .= ' ' . $this->msg( 'abusefilter-log-hidden-implicit' )->parse();
			if ( !$this->userCan( $rc, Revision::SUPPRESSED_ALL ) ) {
				return;
			}
		}

		$examineParams = [];
		if ( $this->testFilter && strlen( $this->testFilter ) < 2000 ) {
			// Since this is GETed, don't send it if it's too long to prevent broken URLs 2000 is taken from
			// https://stackoverflow.com/questions/417142/what-is-the-maximum-length-of-a-url-
			// in-different-browsers/417184#417184
			$examineParams['testfilter'] = $this->testFilter;
		}

		$title = SpecialPage::getTitleFor( 'AbuseFilter', 'examine/' . $rc->getAttribute( 'rc_id' ) );
		$examineLink = $this->linkRenderer->makeLink(
			$title,
			new HtmlArmor( $this->msg( 'abusefilter-changeslist-examine' )->parse() ),
			[],
			$examineParams
		);

		$s .= ' ' . $this->msg( 'parentheses' )->rawParams( $examineLink )->escaped();

		// Add CSS classes for match and not match
		if ( isset( $rc->filterResult ) ) {
			$class = $rc->filterResult ?
				'mw-abusefilter-changeslist-match' :
				'mw-abusefilter-changeslist-nomatch';

			$classes[] = $class;
		}
	}

	/**
	 * Insert links to user page, user talk page and eventually a blocking link.
	 *   Like the parent, but don't hide details if user can see them.
	 *
	 * @param string &$s HTML to update
	 * @param RecentChange &$rc
	 */
	public function insertUserRelatedLinks( &$s, &$rc ) {
		$links = $this->getLanguage()->getDirMark() . Linker::userLink( $rc->getAttribute( 'rc_user' ),
				$rc->getAttribute( 'rc_user_text' ) ) .
				Linker::userToolLinks( $rc->getAttribute( 'rc_user' ), $rc->getAttribute( 'rc_user_text' ) );

		if ( $this->isDeleted( $rc, Revision::DELETED_USER ) ) {
			if ( $this->userCan( $rc, Revision::DELETED_USER ) ) {
				$s .= ' <span class="history-deleted">' . $links . '</span>';
			} else {
				$s .= ' <span class="history-deleted">' .
					$this->msg( 'rev-deleted-user' )->escaped() . '</span>';
			}
		} else {
			$s .= $links;
		}
	}

	/**
	 * Insert a formatted comment. Like the parent, but don't hide details if user can see them.
	 * @param RecentChange $rc
	 * @return string
	 */
	public function insertComment( $rc ) {
		if ( $this->isDeleted( $rc, Revision::DELETED_COMMENT ) ) {
			if ( $this->userCan( $rc, Revision::DELETED_COMMENT ) ) {
				return ' <span class="history-deleted">' .
					Linker::commentBlock( $rc->getAttribute( 'rc_comment' ), $rc->getTitle() ) . '</span>';
			} else {
				return ' <span class="history-deleted">' .
					$this->msg( 'rev-deleted-comment' )->escaped() . '</span>';
			}
		} else {
			return Linker::commentBlock( $rc->getAttribute( 'rc_comment' ), $rc->getTitle() );
		}
	}

	/**
	 * Insert a formatted action. The same as parent, but with a different audience in LogFormatter
	 *
	 * @param RecentChange $rc
	 * @return string
	 */
	public function insertLogEntry( $rc ) {
		$formatter = LogFormatter::newFromRow( $rc->getAttributes() );
		$formatter->setContext( $this->getContext() );
		$formatter->setAudience( LogFormatter::FOR_THIS_USER );
		$formatter->setShowUserToolLinks( true );
		$mark = $this->getLanguage()->getDirMark();
		return $formatter->getActionText() . " $mark" . $formatter->getComment();
	}

	/**
	 * @param string &$s
	 * @param RecentChange &$rc
	 */
	public function insertRollback( &$s, &$rc ) {
		// Kill rollback links.
	}
}
