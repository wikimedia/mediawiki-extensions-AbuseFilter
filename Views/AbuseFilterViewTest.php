<?php

if (!defined( 'MEDIAWIKI' ))
	die();

class AbuseFilterViewTest extends AbuseFilterView {
	// Hard-coded for now.
	static $mChangeLimit = 100;
	
	function show( ) {
		global $wgOut, $wgUser, $wgRequest;
		
		AbuseFilter::disableConditionLimit();

		$this->loadParameters();
		
		$wgOut->setPageTitle( wfMsg( 'abusefilter-test' ) );
		$wgOut->addWikiMsg( 'abusefilter-test-intro', self::$mChangeLimit );

		$output = '';
		$output .= AbuseFilter::buildEditBox( $this->mFilter, 'wpTestFilter' ) . "\n";
		$output .= Xml::inputLabel( wfMsg( 'abusefilter-test-load-filter' ), 'wpInsertFilter', 'mw-abusefilter-load-filter', 10, '' ) . '&nbsp;' .
			Xml::element( 'input', array( 'type' => 'button', 'value' => wfMsg( 'abusefilter-test-load' ), 'id' => 'mw-abusefilter-load' ) );
		$output = Xml::tags( 'div', array( 'id' => 'mw-abusefilter-test-editor' ), $output );

		// Removed until I can distinguish between positives and negatives :)
// 		$output .= Xml::tags( 'p', null, Xml::checkLabel( wfMsg( 'abusefilter-test-shownegative' ), 'wpShowNegative', 'wpShowNegative', $this->mShowNegative ) );
		$output .= Xml::tags( 'p', null, Xml::submitButton( wfMsg( 'abusefilter-test-submit' ) ) );
		$output .= Xml::hidden( 'title', $this->getTitle("test")->getPrefixedText() );
		$output = Xml::tags( 'form', array( 'action' => $this->getTitle("test")->getLocalURL(), 'method' => 'POST' ), $output );

		$output = Xml::fieldset( wfMsg( 'abusefilter-test-legend' ), $output );

		$wgOut->addHTML( $output );

		if ($wgRequest->wasPosted()) {
			$this->doTest();
		}
	}

	function doTest() {
		// Quick syntax check.
		if ( ($result = AbuseFilter::checkSyntax( $this->mFilter )) !== true ) {
			$wgOut->addWikiMsg( 'abusefilter-test-syntaxerr' );
			return;
		}

		// Get our ChangesList
		global $wgUser, $wgOut;
		$changesList = ChangesList::newFromUser( $wgUser );
		$output = $changesList->beginRecentChangesList();

		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'recentchanges', '*', array(), __METHOD__, array( 'LIMIT' => self::$mChangeLimit, 'ORDER BY' => 'rc_timestamp asc' ) );

		$counter = 1;

		while ( $row = $dbr->fetchObject( $res ) ) {
			$vars = AbuseFilter::getVarsFromRCRow( $row );

			if (!$vars)
				continue;

			$result = AbuseFilter::checkConditions( $this->mFilter, $vars );

			if ($result || $this->mShowNegative) {
				$rc = RecentChange::newFromRow( $row );
				$rc->counter = $counter++;
				$output .= $changesList->recentChangesLine( $rc, false );
			}
		}

		$output .= $changesList->endRecentChangesList();

		$wgOut->addHTML( $output );
	}

	function loadParameters() {
		global $wgRequest;

		$this->mFilter = $wgRequest->getText( 'wpTestFilter' );
		$this->mShowNegative = $wgRequest->getBool( 'wpShowNegative' );
	}
}