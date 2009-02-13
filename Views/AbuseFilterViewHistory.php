<?php

if (!defined( 'MEDIAWIKI' ))
	die();

class AbuseFilterViewHistory extends AbuseFilterView {

	function __construct( $page, $params ) {
		parent::__construct( $page, $params );
		$this->mFilter = $page->mFilter;
	}

	function show() {
		global $wgRequest,$wgOut;

		global $wgUser;

		$filter = $this->mFilter;

		if ($filter)
			$wgOut->setPageTitle( wfMsg( 'abusefilter-history', $filter ) );
		else
			$wgOut->setPageTitle( wfMsg( 'abusefilter-filter-log' ) );
			
		$sk = $wgUser->getSkin();

		$links = array();
		if ($filter)
			$links['abusefilter-history-backedit'] = $this->getTitle( $filter );
		$links['abusefilter-history-backlist'] = $this->getTitle();

		foreach( $links as $msg => $title ) {
			$links[$msg] = $sk->link( $title, wfMsgExt( $msg, 'parseinline' ) );
		}
		
		$backlinks = implode( '&nbsp;&bull;&nbsp;', $links );
		$wgOut->addHTML( Xml::tags( 'p', null, $backlinks ) );

		$user = $wgRequest->getText( 'user' );
		if ($user) {
			$wgOut->setSubtitle( 
				wfMsg( 
					'abusefilter-history-foruser', 
					$sk->userLink( 1 /* We don't really need to get a user ID */, $user ) 
				) 
			);
		}

		// Add filtering of changes et al.
		$fields['abusefilter-history-select-user'] = wfInput( 'user', 45, $user );

		$filterForm = Xml::buildForm( $fields, 'abusefilter-history-select-submit' );
		$filterForm .= "\n" . Xml::hidden( 'title', $this->getTitle( "history/$filter" ) );
		$filterForm = Xml::tags( 'form', 
			array( 
				'action' => $this->getTitle( "history/$filter" )->getLocalURL(), 
				'method' => 'get' ), 
			$filterForm 
		);
		$filterForm = Xml::fieldset( wfMsg( 'abusefilter-history-select-legend' ), $filterForm );
		$wgOut->addHTML( $filterForm );

		$pager = new AbuseFilterHistoryPager( $filter, $this, $user );
		$table = $pager->getBody();

		$wgOut->addHTML( $pager->getNavigationBar() . $table . $pager->getNavigationBar() );
	}
}

class AbuseFilterHistoryPager extends TablePager {

	function __construct( $filter, $page, $user ) {
		$this->mFilter = $filter;
		$this->mPage = $page;
		$this->mUser = $user;
		$this->mDefaultDirection = true;
		parent::__construct();
	}

	function getFieldNames() {
		static $headers = null;

		if (!empty($headers)) {
			return $headers;
		}

		$headers = array( 
			'afh_timestamp' => 'abusefilter-history-timestamp', 
			'afh_user_text' => 'abusefilter-history-user', 
			'afh_public_comments' => 'abusefilter-history-public',
			'afh_flags' => 'abusefilter-history-flags', 
			'afh_pattern' => 'abusefilter-history-filter', 
			'afh_comments' => 'abusefilter-history-comments', 
			'afh_actions' => 'abusefilter-history-actions' );

		if (!$this->mFilter) {
			// awful hack
			$headers = array( 'afh_filter' => 'abusefilter-history-filterid' ) + $headers;
			unset( $headers['afh_comments'] );
		}

		$headers = array_map( 'wfMsg', $headers );

		return $headers;
	}

	function formatValue( $name, $value ) {
		global $wgOut,$wgLang;

		static $sk=null;

		if (empty($sk)) {
			global $wgUser;
			$sk = $wgUser->getSkin();
		}

		$row = $this->mCurrentRow;

		$formatted = '';

		switch($name) {
			case 'afh_timestamp':
				$title = SpecialPage::getTitleFor( 'AbuseFilter', 
					'history/'.$row->afh_filter.'/item/'.$row->afh_id );
				$formatted = $sk->link( $title, $wgLang->timeanddate( $row->afh_timestamp ) );
				break;
			case 'afh_user_text':
				$formatted = 
					$sk->userLink( $row->afh_user, $row->afh_user_text ) . ' ' . 
					$sk->userToolLinks( $row->afh_user, $row->afh_user_text );
				break;
			case 'afh_public_comments':
				$formatted = $wgOut->parse( $value );
				break;
			case 'afh_flags':
				$flags = array_filter( explode( ',', $value ) );
				$flags_display = array();
				foreach( $flags as $flag ) {
					$flags_display[] = wfMsg( "abusefilter-history-$flag" );
				}
				$formatted = implode( ', ', $flags_display );
				break;
			case 'afh_pattern':
				$formatted = htmlspecialchars( $wgLang->truncate( $value, 200 ) );
				break;
			case 'afh_comments':
				$formatted = htmlspecialchars( $wgLang->truncate( $value, 200 ) );
				break;
			case 'afh_actions':
				$actions = unserialize( $value );

				$display_actions = '';

				foreach( $actions as $action => $parameters ) {
					$display_actions .= Xml::tags( 
						'li', null, 
						wfMsgExt( 
							'abusefilter-history-action', 
							array( 'parseinline' ), 
							array( 
								AbuseFilter::getActionDisplay($action), 
								implode('; ', $parameters)
							) 
						) 
					);
				}
				$display_actions = Xml::tags( 'ul', null, $display_actions );

				$formatted = $display_actions;
				break;
			case 'afh_filter':
				$title = $this->mPage->getTitle( strval($value) );
				$formatted = $sk->link( $title, $value );
				break;
			default:
				$formatted = "Unable to format $name";
				break;
		}

		$mappings = array_flip(AbuseFilter::$history_mappings) + 
			array( 'afh_actions' => 'actions' );
		$changed = explode( ',', $row->afh_changed_fields );

		$fieldChanged = false;
		if ($name == 'afh_flags') {
			// This is a bit freaky, but it works. 
			// Basically, returns true if any of those filters are in the $changed array.
			$filters = array( 'af_enabled', 'af_hidden', 'af_deleted' );
			if ( count( array_diff( $filters, $changed ) ) < 3 ) {
				$fieldChanged = true;
			}
		} elseif ( in_array( $mappings[$name], $changed ) ) {
			$fieldChanged = true;
		}

		if ($fieldChanged) {
			$formatted = Xml::tags( 'div', 
				array( 'class' => 'mw-abusefilter-history-changed' ), 
				$formatted );
		}

		return $formatted;
	}

	function getQueryInfo() {
		$info = array(
			'tables' => 'abuse_filter_history',
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
				'afh_changed_fields' ),
			'conds' => array(),
		);

		global $wgRequest;
		if ($this->mUser) {
			$info['conds']['afh_user_text'] = $this->mUser;
		}
		if ( $this->mFilter ) {
			$info['fields'][] = 'afh_pattern';
			$info['conds']['afh_filter'] = $this->mFilter;
		}
		
		return $info;
	}

	function getIndexField() {
		return 'afh_timestamp';
	}

	function getDefaultSort() {
		return 'afh_timestamp';
	}

	function isFieldSortable($name) {
		$sortable_fields = array( 'afh_timestamp', 'afh_user_text' );
		return in_array( $name, $sortable_fields );
	}

	/**
	 * Title used for self-links. Override this if you want to be able to
	 * use a title other than $wgTitle
	 */
	function getTitle() {
		return $this->mPage->getTitle( "history/".$this->mFilter );
	}
}
