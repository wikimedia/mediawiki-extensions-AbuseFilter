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
		
		$sk = $wgUser->getSkin();
		$wgOut->setPageTitle( wfMsg( 'abusefilter-history', $filter ) );
		$backToFilter_label = wfMsgExt( 'abusefilter-history-backedit', array('parseinline') );
		$backToList_label = wfMsgExt( 'abusefilter-history-backlist', array('parseinline') );
		$backlinks = $sk->makeKnownLinkObj( $this->getTitle( $filter ), $backToFilter_label ) . '&nbsp;&bull;&nbsp;' .
				$sk->makeKnownLinkObj( $this->getTitle( ), $backToList_label );
		$wgOut->addHTML( Xml::tags( 'p', null, $backlinks ) );

		$pager = new AbuseFilterHistoryPager( $filter, $this );
		$table = $pager->getBody();

		$wgOut->addHTML( $pager->getNavigationBar() . $table . $pager->getNavigationBar() );
	}
}

class AbuseFilterHistoryPager extends TablePager {

	function __construct( $filter, $page ) {
		$this->mFilter = $filter;
		$this->mPage = $page;
		$this->mDefaultDirection = true;
		parent::__construct();
	}

	function getFieldNames() {
		static $headers = null;

		if (!empty($headers)) {
			return $headers;
		}

		$headers = array( 'afh_timestamp' => 'abusefilter-history-timestamp', 'afh_user_text' => 'abusefilter-history-user', 'afh_public_comments' => 'abusefilter-history-public',
					'afh_flags' => 'abusefilter-history-flags', 'afh_pattern' => 'abusefilter-history-filter', 'afh_comments' => 'abusefilter-history-comments', 'afh_actions' => 'abusefilter-history-actions' );

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

		switch($name) {
			case 'afh_timestamp':
				$title = SpecialPage::getTitleFor( 'AbuseFilter', 'history/'.$this->mFilter.'/item/'.$row->afh_id );
				return $sk->link( $title, $wgLang->timeanddate( $row->afh_timestamp ) );
			case 'afh_user_text':
				return $sk->userLink( $row->afh_user, $row->afh_user_text ) . ' ' . $sk->userToolLinks( $row->afh_user, $row->afh_user_text );
			case 'afh_public_comments':
				return $wgOut->parse( $value );
			case 'afh_flags':
				$flags = array_filter( explode( ',', $value ) );
				$flags_display = array();
				foreach( $flags as $flag ) {
					$flags_display[] = wfMsg( "abusefilter-history-$flag" );
				}
				return implode( ', ', $flags_display );
			case 'afh_pattern':
				return htmlspecialchars( $wgLang->truncate( $value, 200, '...' ) );
			case 'afh_comments':
				return htmlspecialchars( $wgLang->truncate( $value, 200, '...' ) );
			case 'afh_actions':
				$actions = unserialize( $value );

				$display_actions = '';

				foreach( $actions as $action => $parameters ) {
					$display_actions .= Xml::tags( 'li', null, wfMsgExt( 'abusefilter-history-action', array( 'parseinline' ), array($action, implode('; ', $parameters)) ) );
				}
				$display_actions = Xml::tags( 'ul', null, $display_actions );

				return $display_actions;
		}

		return "Unable to format name $name\n";
	}

	function getQueryInfo() {
		return array(
			'tables' => 'abuse_filter_history',
			'fields' => array( 'afh_timestamp', 'afh_user_text', 'afh_public_comments', 'afh_flags', 'afh_pattern', 'afh_comments', 'afh_actions', 'afh_id', 'afh_user' ),
			'conds' => array( 'afh_filter' => $this->mFilter ),
		);
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