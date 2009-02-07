<?php
if ( ! defined( 'MEDIAWIKI' ) )
	die();

class SpecialAbuseLog extends SpecialPage {

	function __construct() {
		wfLoadExtensionMessages('AbuseFilter');
		parent::__construct( 'AbuseLog', 'abusefilter-log' );
	}
	
	function execute( $parameter ) {
		global $wgUser,$wgOut,$wgRequest, $wgAbuseFilterStyleVersion;

		$this->setHeaders();
		$this->loadParameters();

		$wgOut->setPageTitle( wfMsg( 'abusefilter-log' ) );
		$wgOut->setRobotPolicy( "noindex,nofollow" );
		$wgOut->setArticleRelated( false );
		$wgOut->enableClientCache( false );

		global $wgScriptPath;
		$wgOut->addExtensionStyle( $wgScriptPath . 
			"/extensions/AbuseFilter/abusefilter.css?$wgAbuseFilterStyleVersion" );

		// Are we allowed?
		$errors = $this->getTitle()->getUserPermissionsErrors( 
			'abusefilter-log', $wgUser, true, array( 'ns-specialprotected' ) );
		if ( count( $errors ) ) {
			// Go away.
			$wgOut->showPermissionsErrorPage( $errors, 'abusefilter-log' );
			return;
		}
		
		// Show the search form.
		$this->searchForm();
		
		// Show the log itself.
		$this->showList();
	}
	
	function loadParameters() {
		global $wgRequest;
		
		$this->mSearchUser = $wgRequest->getText( 'wpSearchUser' );
		$this->mSearchTitle = $wgRequest->getText( 'wpSearchTitle' );
		if ($this->canSeeDetails())
			$this->mSearchFilter = $wgRequest->getIntOrNull( 'wpSearchFilter' );
			
		$detailsid = $wgRequest->getIntOrNull( 'details' );
		
		if ($detailsid) {
			$this->showDetails( $detailsid );
		}
	}
	
	function searchForm() {
		global $wgOut, $wgUser;
		
		$output = Xml::element( 'legend', null, wfMsg( 'abusefilter-log-search' ) );
		$fields = array();
		
		// Search conditions
		$fields['abusefilter-log-search-user'] = 
			Xml::input( 'wpSearchUser', 45, $this->mSearchUser );
		if ($this->canSeeDetails()) {
			$fields['abusefilter-log-search-filter'] = 
				Xml::input( 'wpSearchFilter', 45, $this->mSearchFilter );
		}
		$fields['abusefilter-log-search-title'] = 
			Xml::input( 'wpSearchTitle', 45, $this->mSearchTitle );
		
		$form = Xml::hidden( 'title', $this->getTitle()->getPrefixedText() );
		
		$form .= Xml::buildForm( $fields, 'abusefilter-log-search-submit' );
		$output .= Xml::tags( 'form', 
			array( 'method' => 'GET', 'action' => $this->getTitle()->getLocalURL() ), 
			$form );
		$output = Xml::tags( 'fieldset', null, $output );
		
		$wgOut->addHTML( $output );
	}
	
	function showList() {
		global $wgOut;
		
		// Generate conditions list.
		$conds = array();
		
		if (!empty($this->mSearchUser))
			$conds['afl_user_text'] = $this->mSearchUser;
		if (!empty($this->mSearchFilter))
			$conds['afl_filter'] = $this->mSearchFilter;
			
		$searchTitle = Title::newFromText( $this->mSearchTitle );
		if ($this->mSearchTitle && $searchTitle) {
			$conds['afl_namespace'] = $searchTitle->getNamespace();
			$conds['afl_title'] = $searchTitle->getDBKey();
		}
		
		$pager = new AbuseLogPager( $this, $conds );
		
		$wgOut->addHTML( $pager->getNavigationBar() .
				Xml::tags( 'ul', null, $pager->getBody() ) .
				$pager->getNavigationBar() );
	}
	
	function showDetails( $id ) {
		if (!$this->canSeeDetails()) {
			return;
		}
		
		$dbr = wfGetDB( DB_SLAVE );
		
		$row = $dbr->selectRow( array('abuse_filter_log','abuse_filter'), '*', 
			array( 'afl_id' => $id, 'af_id=afl_filter' ), __METHOD__ );
		
		if (!$row)
			return;
		
		$output = '';
		
		$output .= Xml::element( 'legend', null, wfMsg( 'abusefilter-log-details-legend', $id ) );
		$output .= Xml::tags( 'p', null, $this->formatRow( $row, false ) );
		$output .= Xml::element( 'h3', null, wfMsg( 'abusefilter-log-details-vars' ) );
		
		// Build a table.
		$vars = unserialize( $row->afl_var_dump );
		
		$output .= AbuseFilter::buildVarDumpTable( $vars );
		
		if ($this->canSeePrivate()) {
			// Private stuff, like IPs.
			$header = 
				Xml::element( 'th', null, wfMsg( 'abusefilter-log-details-var' ) ) . 
				Xml::element( 'th', null, wfMsg( 'abusefilter-log-details-val' ) );
			$output .= Xml::element( 'h3', null, wfMsg( 'abusefilter-log-details-private' ) );
			$output .= 
				Xml::openElement( 'table', 
					array( 
						'class' => 'wikitable mw-abuselog-private', 
						'style' => "width: 80%;" 
					) 
				) . 
				Xml::openElement( 'tbody' );
			$output .= $header;
			
			// IP address
			$output .= 
				Xml::tags( 'tr', null, 
					Xml::element( 'td', 
						array( 'style' => 'width: 30%;' ), 
						wfMsg('abusefilter-log-details-ip' ) 
					) . 
					Xml::element( 'td', null, $row->afl_ip ) 
				);
			
			$output .= Xml::closeElement( 'tbody' ) . Xml::closeElement( 'table' );
		}
		
		$output = Xml::tags( 'fieldset', null, $output );
		
		global $wgOut;
		$wgOut->addHTML( $output );
	}
	
	function canSeeDetails() {
		global $wgUser;
		return !count($this->getTitle()->getUserPermissionsErrors( 
			'abusefilter-log-detail', $wgUser, true, array( 'ns-specialprotected' ) ));
	}
	
	function canSeePrivate() {
		global $wgUser;
		return !count(
			$this->getTitle()->getUserPermissionsErrors( 
				'abusefilter-private', $wgUser, true, array( 'ns-specialprotected' ) ));
	}
	
	function formatRow( $row, $li = true ) {
		global $wgLang,$wgUser;
		
		## One-time setup
		static $sk=null;
		
		if (is_null($sk)) {
			$sk = $wgUser->getSkin();
		}
		
		$title = Title::makeTitle( $row->afl_namespace, $row->afl_title );
		
		$user = $sk->userLink( $row->afl_user, $row->afl_user_text ) .
			$sk->userToolLinks( $row->afl_user, $row->afl_user_text );
		
		$description = '';
		
		$timestamp = $wgLang->timeanddate( $row->afl_timestamp );
		
		$actions_taken = $row->afl_actions;
		if ( !strlen( trim( $actions_taken) ) ) {
			$actions_taken = wfMsg( 'abusefilter-log-noactions' );
		} else {
			$actions = explode(',', $actions_taken);
			$displayActions = array();

			foreach( $actions as $action ) {
				$displayActions[] = AbuseFilter::getActionDisplay( $action );;
			}
			$actions_taken = implode( ', ', $displayActions );
		}

		global $wgOut;
		$parsed_comments = $wgOut->parseInline( $row->af_public_comments );
		
		if ($this->canSeeDetails()) {
			$examineTitle = SpecialPage::getTitleFor( 'AbuseFilter', "examine/log/".$row->afl_id );
			$detailsLink = $sk->makeKnownLinkObj( 
				$this->getTitle(), 
				wfMsg( 'abusefilter-log-detailslink' ), 
				'details='.$row->afl_id );
			$examineLink = $sk->link( 
				$examineTitle, 
				wfMsgExt( 'abusefilter-changeslist-examine', 'parseinline' ), 
				array() );
			
			$description = wfMsgExt( 'abusefilter-log-detailedentry', 
				array( 'parseinline', 'replaceafter' ),
				array( 
					$timestamp, 
					$user, 
					$row->afl_filter, 
					$row->afl_action, 
					$sk->makeKnownLinkObj( $title ), 
					$actions_taken, 
					$parsed_comments, 
					$detailsLink, 
					$examineLink 
				) 
			);
		} else {
			$description = wfMsgExt( 
				'abusefilter-log-entry', 
				array( 'parseinline', 'replaceafter' ), 
				array( 
					$timestamp, 
					$user, 
					$row->afl_action, 
					$sk->makeKnownLinkObj( $title ), 
					$actions_taken, 
					$parsed_comments 
				) 
			);
		}
		
		return $li ? Xml::tags( 'li', null, $description ) : $description;
	}
	
}

class AbuseLogPager extends ReverseChronologicalPager {
	public $mForm, $mConds;

	function __construct( $form, $conds = array(), $details = false ) {
		$this->mForm = $form;
		$this->mConds = $conds;
		parent::__construct();
	}

	function formatRow( $row ) {
		return $this->mForm->formatRow( $row );
	}

	function getQueryInfo() {
		$conds = $this->mConds;
		
		$conds[] = 'af_id=afl_filter';
		
		return array(
			'tables' => array('abuse_filter_log','abuse_filter'),
			'fields' => '*',
			'conds' => $conds,
		);
	}

	function getIndexField() {
		return 'afl_timestamp';
	}
}
