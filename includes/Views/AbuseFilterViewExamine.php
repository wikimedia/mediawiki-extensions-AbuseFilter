<?php

class AbuseFilterViewExamine extends AbuseFilterView {
	public static $examineType = null;
	public static $examineId = null;

	public $mCounter, $mSearchUser, $mSearchPeriodStart, $mSearchPeriodEnd,
		$mTestFilter;

	function show() {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'abusefilter-examine' ) );
		$out->addWikiMsg( 'abusefilter-examine-intro' );

		$this->loadParameters();

		// Check if we've got a subpage
		if ( count( $this->mParams ) > 1 && is_numeric( $this->mParams[1] ) ) {
			$this->showExaminerForRC( $this->mParams[1] );
		} elseif ( count( $this->mParams ) > 2
			&& $this->mParams[1] == 'log'
			&& is_numeric( $this->mParams[2] )
		) {
			$this->showExaminerForLogEntry( $this->mParams[2] );
		} else {
			$this->showSearch();
		}
	}

	function showSearch() {
		$formDescriptor = [
			'SearchUser' => [
				'label-message' => 'abusefilter-test-user',
				'type' => 'user',
				'default' => $this->mSearchUser,
			],
			'SearchPeriodStart' => [
				'label-message' => 'abusefilter-test-period-start',
				'type' => 'text',
				'default' => $this->mSearchPeriodStart,
			],
			'SearchPeriodEnd' => [
				'label-message' => 'abusefilter-test-period-end',
				'type' => 'text',
				'default' => $this->mSearchPeriodEnd,
			],
		];
		$htmlForm = HTMLForm::factory( 'table', $formDescriptor, $this->getContext() );
		$htmlForm->setWrapperLegendMsg( 'abusefilter-examine-legend' )
			->addHiddenField( 'submit', 1 )
			->setSubmitTextMsg( 'abusefilter-examine-submit' )
			->setMethod( 'get' )
			->prepareForm()
			->displayForm( false );

		if ( $this->mSubmit ) {
			$this->showResults();
		}
	}

	function showResults() {
		$changesList = new AbuseFilterChangesList( $this->getSkin() );
		$output = $changesList->beginRecentChangesList();
		$this->mCounter = 1;

		$pager = new AbuseFilterExaminePager( $this, $changesList );

		$output .= $pager->getNavigationBar() .
					$pager->getBody() .
					$pager->getNavigationBar();

		$output .= $changesList->endRecentChangesList();

		$this->getOutput()->addHTML( $output );
	}

	function showExaminerForRC( $rcid ) {
		// Get data
		$dbr = wfGetDB( DB_REPLICA );
		$rcQuery = RecentChange::getQueryInfo();
		$row = $dbr->selectRow(
			$rcQuery['tables'],
			$rcQuery['fields'],
			[ 'rc_id' => $rcid ],
			__METHOD__,
			[],
			$rcQuery['joins']
		);
		$out = $this->getOutput();
		if ( !$row ) {
			$out->addWikiMsg( 'abusefilter-examine-notfound' );
			return;
		}

		self::$examineType = 'rc';
		self::$examineId = $rcid;

		$vars = AbuseFilter::getVarsFromRCRow( $row );
		$out->addJsConfigVars( 'wgAbuseFilterVariables', $vars->dumpAllVars( true ) );
		$this->showExaminer( $vars );
	}

	function showExaminerForLogEntry( $logid ) {
		// Get data
		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow( 'abuse_filter_log', '*', [ 'afl_id' => $logid ], __METHOD__ );
		$out = $this->getOutput();

		if ( !$row ) {
			$out->addWikiMsg( 'abusefilter-examine-notfound' );
			return;
		}

		self::$examineType = 'log';
		self::$examineId = $logid;

		if ( !SpecialAbuseLog::canSeeDetails( $row->afl_filter ) ) {
			$out->addWikiMsg( 'abusefilter-log-cannot-see-details' );
			return;
		}

		if ( $row->afl_deleted && !SpecialAbuseLog::canSeeHidden() ) {
			$out->addWikiMsg( 'abusefilter-log-details-hidden' );
			return;
		}

		$vars = AbuseFilter::loadVarDump( $row->afl_var_dump );
		$out->addJsConfigVars( 'wgAbuseFilterVariables', $vars->dumpAllVars( true ) );
		$this->showExaminer( $vars );
	}

	function showExaminer( $vars ) {
		$output = $this->getOutput();

		if ( !$vars ) {
			$output->addWikiMsg( 'abusefilter-examine-incompatible' );
			return;
		}

		if ( $vars instanceof AbuseFilterVariableHolder ) {
			$vars = $vars->exportAllVars();
		}

		$html = '';

		$output->addModules( 'ext.abuseFilter.examine' );

		// Add test bit
		if ( $this->getUser()->isAllowed( 'abusefilter-modify' ) ) {
			$tester = Xml::tags( 'h2', null, $this->msg( 'abusefilter-examine-test' )->parse() );
			$tester .= AbuseFilter::buildEditBox( $this->mTestFilter, 'wpTestFilter', false );
			$tester .=
				"\n" .
				Xml::inputLabel(
					$this->msg( 'abusefilter-test-load-filter' )->text(),
					'wpInsertFilter',
					'mw-abusefilter-load-filter',
					10,
					''
				) .
				'&#160;' .
				Xml::element(
					'input',
					[
						'type' => 'button',
						'value' => $this->msg( 'abusefilter-test-load' )->text(),
						'id' => 'mw-abusefilter-load'
					]
				);
			$html .= Xml::tags( 'div', [ 'id' => 'mw-abusefilter-examine-editor' ], $tester );
			$html .= Xml::tags( 'p',
				null,
				Xml::element( 'input',
					[
						'type' => 'button',
						'value' => $this->msg( 'abusefilter-examine-test-button' )->text(),
						'id' => 'mw-abusefilter-examine-test'
					]
				) .
				Xml::element( 'div',
					[
						'id' => 'mw-abusefilter-syntaxresult',
						'style' => 'display: none;'
					], '&#160;'
				)
			);
		}

		// Variable dump
		$html .= Xml::tags(
			'h2',
			null,
			$this->msg( 'abusefilter-examine-vars' )->parse()
		);
		$html .= AbuseFilter::buildVarDumpTable( $vars, $this->getContext() );

		$output->addHTML( $html );
	}

	function loadParameters() {
		$request = $this->getRequest();
		$this->mSearchPeriodStart = $request->getText( 'wpSearchPeriodStart' );
		$this->mSearchPeriodEnd = $request->getText( 'wpSearchPeriodEnd' );
		$this->mSubmit = $request->getCheck( 'submit' );
		$this->mTestFilter = $request->getText( 'testfilter' );

		// Normalise username
		$searchUsername = $request->getText( 'wpSearchUser' );
		$userTitle = Title::newFromText( $searchUsername, NS_USER );
		$this->mSearchUser = $userTitle ? $userTitle->getText() : '';
	}
}
