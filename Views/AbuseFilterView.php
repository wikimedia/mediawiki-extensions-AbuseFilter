<?php

abstract class AbuseFilterView extends ContextSource {
	public $mFilter, $mHistoryID, $mSubmit;

	/**
	 * @param $page SpecialAbuseFilter
	 * @param $params array
	 */
	function __construct( $page, $params ) {
		$this->mPage = $page;
		$this->mParams = $params;
		$this->setContext( $this->mPage->getContext() );
	}

	/**
	 * @param string $subpage
	 * @return Title
	 */
	function getTitle( $subpage = '' ) {
		return $this->mPage->getPageTitle( $subpage );
	}

	abstract function show();

	/**
	 * @return bool
	 */
	public function canEdit() {
		return $this->getUser()->isAllowed( 'abusefilter-modify' );
	}

	/**
	 * @return bool
	 */
	public function canEditGlobal() {
		return $this->getUser()->isAllowed( 'abusefilter-modify-global' );
	}

	/**
	 * Whether the user can edit the given filter.
	 *
	 * @param object $row Filter row
	 *
	 * @return bool
	 */
	public function canEditFilter( $row ) {
		return (
			$this->canEdit() &&
			!( isset( $row->af_global ) && $row->af_global == 1 && !$this->canEditGlobal() )
		);
	}

	/**
	 * @static
	 * @return bool
	 */
	static function canViewPrivate() {
		global $wgUser;
		static $canView = null;

		if ( is_null( $canView ) ) {
			$canView = $wgUser->isAllowedAny( 'abusefilter-modify', 'abusefilter-view-private' );
		}

		return $canView;
	}
}

class AbuseFilterChangesList extends OldChangesList {
	/**
	 * @param $s
	 * @param $rc
	 * @param $classes array
	 */
	public function insertExtra( &$s, &$rc, &$classes ) {
		if ( (int)$rc->getAttribute( 'rc_deleted' ) !== 0 ) {
			$bitfield = 0;
			$bitfield |= Revision::DELETED_TEXT;
			$bitfield |= Revision::DELETED_COMMENT;
			$bitfield |= Revision::DELETED_USER;
			$bitfield |= Revision::DELETED_RESTRICTED;
			$s .= ' ' . $this->msg( 'abusefilter-log-hidden-implicit' )->parse();
			if ( !$this->userCan( $rc, $bitfield ) ) {
				return;
			}
		}
		$examineParams = empty( $rc->examineParams ) ? array() : $rc->examineParams;

		$title = SpecialPage::getTitleFor( 'AbuseFilter', 'examine/' . $rc->mAttribs['rc_id'] );
		$examineLink = Linker::link(
			$title,
			$this->msg( 'abusefilter-changeslist-examine' )->parse(),
			array(),
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

	/**
	 * Insert links to user page, user talk page and eventually a blocking link.
	 *   Like the parent, but don't hide details if user can see them.
	 *
	 * @param string &$s HTML to update
	 * @param RecentChange &$rc
	 */
	public function insertUserRelatedLinks( &$s, &$rc ) {
		$links = $this->getLanguage()->getDirMark() . Linker::userLink( $rc->mAttribs['rc_user'],
				$rc->mAttribs['rc_user_text'] ) .
				Linker::userToolLinks( $rc->mAttribs['rc_user'], $rc->mAttribs['rc_user_text'] );

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
					Linker::commentBlock( $rc->mAttribs['rc_comment'], $rc->getTitle() ) . '</span>';
			} else {
				return ' <span class="history-deleted">' .
					$this->msg( 'rev-deleted-comment' )->escaped() . '</span>';
			}
		} else {
			return Linker::commentBlock( $rc->mAttribs['rc_comment'], $rc->getTitle() );
		}
	}

	/**
	 * Insert a formatted action. The same as parent, but with a different audience in LogFormatter
	 *
	 * @param RecentChange $rc
	 * @return string
	 */
	public function insertLogEntry( $rc ) {
		$formatter = LogFormatter::newFromRow( $rc->mAttribs );
		$formatter->setContext( $this->getContext() );
		$formatter->setAudience( LogFormatter::FOR_THIS_USER );
		$formatter->setShowUserToolLinks( true );
		$mark = $this->getLanguage()->getDirMark();
		return $formatter->getActionText() . " $mark" . $formatter->getComment();
	}

	// Kill rollback links.
	public function insertRollback( &$s, &$rc ) {
	}
}
