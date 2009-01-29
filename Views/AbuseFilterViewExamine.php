<?php

if (!defined( 'MEDIAWIKI' ))
	die();

class AbuseFilterViewExamine extends AbuseFilterView {

	function show( ) {
		global $wgOut, $wgUser;

		$wgOut->setPageTitle( wfMsg( 'abusefilter-examine' ) );
		$wgOut->addWikiMsg( 'abusefilter-examine-intro' );

		$this->loadParameters();

		// Check if we've got a subpage
		if ( count($this->mParams)>1 && is_numeric($this->mParams[1]) ) {
			$this->showExaminer( $this->mParams[1] );
		} else {
			$this->showSearch();
		}
	}

	function showSearch() {
		global $wgUser, $wgOut;
		
		// Add selector
		$selector = '';

		$selectFields = array(); ## Same fields as in Test
		$selectFields['abusefilter-test-user'] = wfInput( 'wpSearchUser', 45, $this->mSearchUser );
		$selectFields['abusefilter-test-period-start'] = wfInput( 'wpSearchPeriodStart', 45, $this->mSearchPeriodStart );
		$selectFields['abusefilter-test-period-end'] = wfInput( 'wpSearchPeriodEnd', 45, $this->mSearchPeriodEnd );

		$selector .= Xml::buildForm( $selectFields, 'abusefilter-examine-submit' );
		$selector .= Xml::hidden( 'submit', 1 );
		$selector .= Xml::hidden( 'title', $this->getTitle( 'examine' )->getPrefixedText() );
		$selector = Xml::tags( 'form', array( 'action' => $this->getTitle( 'examine' )->getLocalURL(), 'method' => 'GET' ), $selector );
		$selector = Xml::fieldset( wfMsg( 'abusefilter-examine-legend' ), $selector );
		$wgOut->addHTML( $selector );

		if ($this->mSubmit) {
			$this->showResults();
		}
	}

	function showResults() {
		global $wgUser, $wgOut;
		
		$dbr = wfGetDB( DB_SLAVE );

		$conds = array( 'rc_user_text' => $this->mSearchUser );
		if ( $startTS = strtotime($this->mSearchPeriodStart) ) {
			$conds[] = 'rc_timestamp>=' . $dbr->addQuotes( $dbr->timestamp( $startTS ) );
		}
		if ( $endTS = strtotime($this->mSearchPeriodEnd) ) {
			$conds[] = 'rc_timestamp<=' . $dbr->addQuotes( $dbr->timestamp( $endTS ) );
		}
		
		$res = $dbr->select( 'recentchanges', '*', array_filter($conds), __METHOD__, array( 'ORDER BY' => 'rc_timestamp DESC', 'LIMIT' => '500' ) );

		$changesList = new AbuseFilterChangesList( $wgUser->getSkin() );
		$output = $changesList->beginRecentChangesList();
		$counter = 1;

		while ( $row = $dbr->fetchObject( $res ) ) {
			$rc = RecentChange::newFromRow( $row );
			$rc->counter = $counter++;
			$output .= $changesList->recentChangesLine( $rc, false );
		}

		$output .= $changesList->endRecentChangesList();

		$wgOut->addHTML( $output );
	}

	function showExaminer( $rcid ) {
		global $wgOut, $wgUser;
		
		// Get data
		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow( 'recentchanges', '*', array( 'rc_id' => $rcid ), __METHOD__ );

		if (!$row) {
			$wgOut->addWikiMsg( 'abusefilter-examine-notfound' );
			return;
		}

		$vars = AbuseFilter::getVarsFromRCRow( $row );

		if (!$vars) {
			$wgOut->addWikiMsg( 'abusefilter-examine-incompatible' );
			return;
		}

		$output = '';

		// Send armoured as JSON -- I totally give up on trying to send it as a proper object.
		$wgOut->addInlineScript( "var wgExamineVars = ". Xml::encodeJsVar( json_encode( $vars ) ) .";" );
		$wgOut->addInlineScript( file_get_contents( dirname( __FILE__ ) . "/examine.js" ) );

		// Add messages
		$msg = array();
		$msg['match'] = wfMsg( 'abusefilter-examine-match' );
		$msg['nomatch'] = wfMsg( 'abusefilter-examine-nomatch' );
		$msg['syntaxerror'] = wfMsg( 'abusefilter-examine-syntaxerror' );
		$wgOut->addInlineScript( "var wgMessageMatch = ".Xml::encodeJsVar( $msg['match'] ) . ";\n".
					"var wgMessageNomatch = ".Xml::encodeJsVar( $msg['nomatch'] ) . ";\n".
					"var wgMessageError = ".Xml::encodeJsVar( $msg['syntaxerror'] ) . ";\n" );

		// Add test bit
		$tester = Xml::tags( 'h2', null, wfMsgExt( 'abusefilter-examine-test', 'parseinline' ) );
		$tester .= AbuseFilter::buildEditBox( '', 'wpTestFilter', false );
		$tester .= "\n" . Xml::inputLabel( wfMsg( 'abusefilter-test-load-filter' ), 'wpInsertFilter', 'mw-abusefilter-load-filter', 10, '' ) . '&nbsp;' .
			Xml::element( 'input', array( 'type' => 'button', 'value' => wfMsg( 'abusefilter-test-load' ), 'id' => 'mw-abusefilter-load' ) );
		$output .= Xml::tags( 'div', array( 'id' => 'mw-abusefilter-examine-editor' ), $tester );
		$output .= Xml::tags( 'p', null, Xml::element( 'input', array( 'type' => 'button', 'value' => wfMsg( 'abusefilter-examine-test-button' ), 'id' => 'mw-abusefilter-examine-test' ) ) .
				Xml::element( 'div', array( 'id' => 'mw-abusefilter-syntaxresult', 'style' => 'display: none;' ), '&nbsp;' ) );

		// Variable dump
		$output .= Xml::tags( 'h2', null, wfMsgExt( 'abusefilter-examine-vars', 'parseinline' ) );
		$output .= AbuseFilter::buildVarDumpTable( $vars );

		$wgOut->addHTML( $output );
	}

	function loadParameters() {
		global $wgRequest;
		$this->mSearchUser = $wgRequest->getText( 'wpSearchUser' );
		$this->mSearchPeriodStart = $wgRequest->getText( 'wpSearchPeriodStart' );
		$this->mSearchPeriodEnd = $wgRequest->getText( 'wpSearchPeriodEnd' );
		$this->mSubmit = $wgRequest->getCheck( 'submit' );
	}
}