<?php

class AbuseFilterViewHistory extends AbuseFilterView {
	function __construct( $page, $params ) {
		parent::__construct( $page, $params );
		$this->mFilter = $page->mFilter;
	}

	function show() {
		$out = $this->getOutput();
		$filter = $this->mFilter;

		if ( $filter ) {
			$out->setPageTitle( $this->msg( 'abusefilter-history', $filter ) );
		} else {
			$out->setPageTitle( $this->msg( 'abusefilter-filter-log' ) );
		}

		# Check perms
		if ( $filter &&
				!$this->getUser()->isAllowed( 'abusefilter-modify' ) &&
				AbuseFilter::filterHidden( $filter ) ) {
			$out->addWikiMsg( 'abusefilter-history-error-hidden' );
			return;
		}

		# Useful links
		$links = array();
		if ( $filter ) {
			$links['abusefilter-history-backedit'] = $this->getTitle( $filter );
		}

		foreach ( $links as $msg => $title ) {
			$links[$msg] = Linker::link( $title, $this->msg( $msg )->parse() );
		}

		$backlinks = $this->getLanguage()->pipeList( $links );
		$out->addHTML( Xml::tags( 'p', null, $backlinks ) );

		# For user
		$user = $this->getRequest()->getText( 'user' );
		if ( $user ) {
			$out->setSubtitle(
				$this->msg(
					'abusefilter-history-foruser',
					Linker::userLink( 1 /* We don't really need to get a user ID */, $user ),
					$user // For GENDER
				)
			);
		}

		// Add filtering of changes et al.
		$fields['abusefilter-history-select-user'] = Xml::input( 'user', 45, $user );

		$filterForm = Xml::buildForm( $fields, 'abusefilter-history-select-submit' );
		$filterForm .= "\n" . Html::hidden( 'title', $this->getTitle( "history/$filter" ) );
		$filterForm = Xml::tags( 'form',
			array(
				'action' => $this->getTitle( "history/$filter" )->getLocalURL(),
				'method' => 'get'
			),
			$filterForm
		);
		$filterForm = Xml::fieldset( $this->msg( 'abusefilter-history-select-legend' )
			->text(), $filterForm );
		$out->addHTML( $filterForm );

		$pager = new AbuseFilterHistoryPager( $filter, $this, $user );
		$table = $pager->getBody();

		$out->addHTML( $pager->getNavigationBar() . $table . $pager->getNavigationBar() );
	}
}

class AbuseFilterHistoryPager extends TablePager {
	/**
	 * @param $filter
	 * @param $page ContextSource
	 * @param $user string User name
	 */
	function __construct( $filter, $page, $user ) {
		$this->mFilter = $filter;
		$this->mPage = $page;
		$this->mUser = $user;
		$this->mDefaultDirection = true;
		parent::__construct( $this->mPage->getContext() );
	}

	function getFieldNames() {
		static $headers = null;

		if ( !empty( $headers ) ) {
			return $headers;
		}

		$headers = array(
			'afh_timestamp' => 'abusefilter-history-timestamp',
			'afh_user_text' => 'abusefilter-history-user',
			'afh_public_comments' => 'abusefilter-history-public',
			'afh_flags' => 'abusefilter-history-flags',
			'afh_actions' => 'abusefilter-history-actions',
			'afh_id' => 'abusefilter-history-diff',
		);

		if ( !$this->mFilter ) {
			// awful hack
			$headers = array( 'afh_filter' => 'abusefilter-history-filterid' ) + $headers;
			unset( $headers['afh_comments'] );
		}

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		return $headers;
	}

	function formatValue( $name, $value ) {
		$lang = $this->getLanguage();

		$row = $this->mCurrentRow;

		switch( $name ) {
			case 'afh_filter':
				$formatted = $lang->formatNum ( $row->afh_filter );
				break;
			case 'afh_timestamp':
				$title = SpecialPage::getTitleFor( 'AbuseFilter',
					'history/' . $row->afh_filter . '/item/' . $row->afh_id );
				$formatted = Linker::link( $title, $lang->timeanddate( $row->afh_timestamp, true ) );
				break;
			case 'afh_user_text':
				$formatted =
					Linker::userLink( $row->afh_user, $row->afh_user_text ) . ' ' .
					Linker::userToolLinks( $row->afh_user, $row->afh_user_text );
				break;
			case 'afh_public_comments':
				$formatted = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false );
				break;
			case 'afh_flags':
				$formatted = AbuseFilter::formatFlags( $value );
				break;
			case 'afh_actions':
				$actions = unserialize( $value );

				$display_actions = '';

				foreach ( $actions as $action => $parameters ) {
					$displayAction = AbuseFilter::formatAction( $action, $parameters );
					$display_actions .= Xml::tags( 'li', null, $displayAction );
				}
				$display_actions = Xml::tags( 'ul', null, $display_actions );

				$formatted = $display_actions;
				break;
			case 'afh_filter':
				$title = $this->mPage->getTitle( strval( $value ) );
				$formatted = Linker::link( $title, $value );
				break;
			case 'afh_id':
				$formatted = '';
				if ( AbuseFilter::getFirstFilterChange( $row->afh_filter ) != $value ) {
					// Set a link to a diff with the previous version if this isn't the first edit to the filter
					$title = $this->mPage->getTitle(
								'history/' . $row->afh_filter . "/diff/prev/$value" );
					$formatted = Linker::link( $title, $this->msg( 'abusefilter-history-diff' )->parse() );
				}
				break;
			default:
				$formatted = "Unable to format $name";
				break;
		}

		$mappings = array_flip( AbuseFilter::$history_mappings ) +
			array( 'afh_actions' => 'actions', 'afh_id' => 'id' );
		$changed = explode( ',', $row->afh_changed_fields );

		$fieldChanged = false;
		if ( $name == 'afh_flags' ) {
			// This is a bit freaky, but it works.
			// Basically, returns true if any of those filters are in the $changed array.
			$filters = array( 'af_enabled', 'af_hidden', 'af_deleted', 'af_global' );
			if ( count( array_diff( $filters, $changed ) ) < count( $filters ) ) {
				$fieldChanged = true;
			}
		} elseif ( in_array( $mappings[$name], $changed ) ) {
			$fieldChanged = true;
		}

		if ( $fieldChanged ) {
			$formatted = Xml::tags( 'div',
				array( 'class' => 'mw-abusefilter-history-changed' ),
				$formatted
			);
		}

		return $formatted;
	}

	function getQueryInfo() {
		$info = array(
			'tables' => array( 'abuse_filter_history', 'abuse_filter' ),
			'fields' => array(
				'afh_filter',
				'afh_timestamp',
				'afh_user_text',
				'afh_public_comments',
				'afh_flags',
				'afh_comments',
				'afh_actions',
				'afh_id',
				'afh_user',
				'afh_changed_fields',
				'afh_pattern',
				'afh_id',
				'af_hidden'
			),
			'conds' => array(),
			'join_conds' => array(
					'abuse_filter' =>
						array(
							'LEFT JOIN',
							'afh_filter=af_id',
						),
				),
		);

		if ( $this->mUser ) {
			$info['conds']['afh_user_text'] = $this->mUser;
		}

		if ( $this->mFilter ) {
			$info['conds']['afh_filter'] = $this->mFilter;
		}

		if ( !$this->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			// Hide data the user can't see.
			$info['conds']['af_hidden'] = 0;
		}

		return $info;
	}

	function getIndexField() {
		return 'afh_timestamp';
	}

	function getDefaultSort() {
		return 'afh_timestamp';
	}

	function isFieldSortable( $name ) {
		$sortable_fields = array( 'afh_timestamp', 'afh_user_text' );
		return in_array( $name, $sortable_fields );
	}

	/**
	 * Title used for self-links. Override this if you want to be able to
	 * use a title other than $wgTitle
	 *
	 * @return Title
	 */
	function getTitle() {
		return $this->mPage->getTitle( 'history/' . $this->mFilter );
	}
}
