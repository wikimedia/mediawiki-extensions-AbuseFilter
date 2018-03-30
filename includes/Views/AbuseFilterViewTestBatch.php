<?php

class AbuseFilterViewTestBatch extends AbuseFilterView {
	// Hard-coded for now.
	protected static $mChangeLimit = 100;

	public $mShowNegative, $mTestPeriodStart, $mTestPeriodEnd, $mTestPage;
	public $mTestUser;

	function show() {
		$out = $this->getOutput();

		AbuseFilter::disableConditionLimit();

		if ( !$this->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			$out->addWikiMsg( 'abusefilter-mustbeeditor' );
			return;
		}

		$this->loadParameters();

		$out->setPageTitle( $this->msg( 'abusefilter-test' ) );
		$out->addWikiMsg( 'abusefilter-test-intro', self::$mChangeLimit );
		$out->enableOOUI();

		$output = '';
		$output .=
			AbuseFilter::buildEditBox(
				$this->mFilter,
				'wpTestFilter',
				true,
				true,
				true
			) . "\n";

		$output .= AbuseFilter::buildFilterLoader();
		$output = Xml::tags( 'div', [ 'id' => 'mw-abusefilter-test-editor' ], $output );

		$RCMaxAge = $this->getConfig()->get( 'RCMaxAge' );
		$min = wfTimestamp( TS_ISO_8601, time() - $RCMaxAge );
		$max = wfTimestampNow();

		// Search form
		$formFields = [];
		$formFields['wpTestUser'] = [
			'name' => 'wpTestUser',
			'type' => 'user',
			'ipallowed' => true,
			'label-message' => 'abusefilter-test-user',
			'default' => $this->mTestUser
		];
		$formFields['wpTestPeriodStart'] = [
			'name' => 'wpTestPeriodStart',
			'type' => 'datetime',
			'label-message' => 'abusefilter-test-period-start',
			'default' => $this->mTestPeriodStart,
			'min' => $min,
			'max' => $max
		];
		$formFields['wpTestPeriodEnd'] = [
			'name' => 'wpTestPeriodEnd',
			'type' => 'datetime',
			'label-message' => 'abusefilter-test-period-end',
			'default' => $this->mTestPeriodEnd,
			'min' => $min,
			'max' => $max
		];
		$formFields['wpTestPage'] = [
			'name' => 'wpTestPage',
			'type' => 'title',
			'label-message' => 'abusefilter-test-page',
			'default' => $this->mTestPage,
			'creatable' => true
		];
		$formFields['wpShowNegative'] = [
			'name' => 'wpShowNegative',
			'type' => 'check',
			'label-message' => 'abusefilter-test-shownegative',
			'selected' => $this->mShowNegative
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formFields, $this->getContext() )
			->addHiddenField( 'title', $this->getTitle( 'test' )->getPrefixedDBkey() )
			->setId( 'wpFilterForm' )
			->setWrapperLegendMsg( 'abusefilter-list-options' )
			->setAction( $this->getTitle( 'test' )->getLocalURL() )
			->setSubmitTextMsg( 'abusefilter-test-submit' )
			->setMethod( 'post' )
			->prepareForm();
		$htmlForm = $htmlForm->getHTML( $htmlForm );

		$output = Xml::fieldset( $this->msg( 'abusefilter-test-legend' )->text(), $output . $htmlForm );
		$out->addHTML( $output );

		if ( $this->getRequest()->wasPosted() ) {
			$this->doTest();
		}
	}

	/**
	 * @fixme this is similar to AbuseFilterExaminePager::getQueryInfo
	 */
	function doTest() {
		// Quick syntax check.
		$out = $this->getOutput();
		$result = AbuseFilter::checkSyntax( $this->mFilter );
		if ( $result !== true ) {
			$out->addWikiMsg( 'abusefilter-test-syntaxerr' );
			return;
		}
		$dbr = wfGetDB( DB_REPLICA );

		$conds = [];

		if ( (string)$this->mTestUser !== '' ) {
			$conds[] = ActorMigration::newMigration()->getWhere(
				$dbr, 'rc_user', User::newFromName( $this->mTestUser, false )
			)['conds'];
		}

		if ( $this->mTestPeriodStart ) {
			$conds[] = 'rc_timestamp >= ' .
				$dbr->addQuotes( $dbr->timestamp( strtotime( $this->mTestPeriodStart ) ) );
		}
		if ( $this->mTestPeriodEnd ) {
			$conds[] = 'rc_timestamp <= ' .
				$dbr->addQuotes( $dbr->timestamp( strtotime( $this->mTestPeriodEnd ) ) );
		}
		if ( $this->mTestPage ) {
			$title = Title::newFromText( $this->mTestPage );
			if ( $title instanceof Title ) {
				$conds['rc_namespace'] = $title->getNamespace();
				$conds['rc_title'] = $title->getDBkey();
			} else {
				$out->addWikiMsg( 'abusefilter-test-badtitle' );
				return;
			}
		}

		$conds[] = $this->buildTestConditions( $dbr );

		// Get our ChangesList
		$changesList = new AbuseFilterChangesList( $this->getSkin(), $this->mFilter );
		$output = $changesList->beginRecentChangesList();

		$rcQuery = RecentChange::getQueryInfo();
		$res = $dbr->select(
			$rcQuery['tables'],
			$rcQuery['fields'],
			array_filter( $conds ),
			__METHOD__,
			[ 'LIMIT' => self::$mChangeLimit, 'ORDER BY' => 'rc_timestamp desc' ],
			$rcQuery['joins']
		);

		$counter = 1;

		foreach ( $res as $row ) {
			$vars = AbuseFilter::getVarsFromRCRow( $row );

			if ( !$vars ) {
				continue;
			}

			$result = AbuseFilter::checkConditions( $this->mFilter, $vars );

			if ( $result || $this->mShowNegative ) {
				// Stash result in RC item
				$rc = RecentChange::newFromRow( $row );
				$rc->filterResult = $result;
				$rc->counter = $counter++;
				$output .= $changesList->recentChangesLine( $rc, false );
			}
		}

		$output .= $changesList->endRecentChangesList();

		$out->addHTML( $output );
	}

	function loadParameters() {
		$request = $this->getRequest();

		$this->mFilter = $request->getText( 'wpTestFilter' );
		$this->mShowNegative = $request->getBool( 'wpShowNegative' );
		$testUsername = $request->getText( 'wpTestUser' );
		$this->mTestPeriodEnd = $request->getText( 'wpTestPeriodEnd' );
		$this->mTestPeriodStart = $request->getText( 'wpTestPeriodStart' );
		$this->mTestPage = $request->getText( 'wpTestPage' );

		if ( !$this->mFilter
			&& count( $this->mParams ) > 1
			&& is_numeric( $this->mParams[1] )
		) {
			$dbr = wfGetDB( DB_REPLICA );
			$this->mFilter = $dbr->selectField( 'abuse_filter',
				'af_pattern',
				[ 'af_id' => $this->mParams[1] ],
				__METHOD__
			);
		}

		// Normalise username
		$userTitle = Title::newFromText( $testUsername, NS_USER );
		$this->mTestUser = $userTitle ? $userTitle->getText() : null;
	}
}
