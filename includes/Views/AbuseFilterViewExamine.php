<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\RCVariableGenerator;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;

class AbuseFilterViewExamine extends AbuseFilterView {
	/**
	 * @var string The user whose entries we're examinating
	 */
	public $mSearchUser;
	/**
	 * @var string The start time of the search period
	 */
	public $mSearchPeriodStart;
	/**
	 * @var string The end time of the search period
	 */
	public $mSearchPeriodEnd;
	/**
	 * @var string The ID of the filter we're examinating
	 */
	public $mTestFilter;

	/**
	 * Shows the page
	 */
	public function show() {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'abusefilter-examine' ) );
		$out->addHelpLink( 'Extension:AbuseFilter/Rules format' );
		$out->addWikiMsg( 'abusefilter-examine-intro' );

		$this->loadParameters();

		// Check if we've got a subpage
		if ( count( $this->mParams ) > 1 && is_numeric( $this->mParams[1] ) ) {
			$this->showExaminerForRC( $this->mParams[1] );
		} elseif ( count( $this->mParams ) > 2
			&& $this->mParams[1] === 'log'
			&& is_numeric( $this->mParams[2] )
		) {
			$this->showExaminerForLogEntry( $this->mParams[2] );
		} else {
			$this->showSearch();
		}
	}

	/**
	 * Shows the search form
	 */
	public function showSearch() {
		$RCMaxAge = $this->getConfig()->get( 'RCMaxAge' );
		$min = wfTimestamp( TS_ISO_8601, time() - $RCMaxAge );
		$max = wfTimestampNow();
		$formDescriptor = [
			'SearchUser' => [
				'label-message' => 'abusefilter-test-user',
				'type' => 'user',
				'ipallowed' => true,
				'default' => $this->mSearchUser,
			],
			'SearchPeriodStart' => [
				'label-message' => 'abusefilter-test-period-start',
				'type' => 'datetime',
				'default' => $this->mSearchPeriodStart,
				'min' => $min,
				'max' => $max,
			],
			'SearchPeriodEnd' => [
				'label-message' => 'abusefilter-test-period-end',
				'type' => 'datetime',
				'default' => $this->mSearchPeriodEnd,
				'min' => $min,
				'max' => $max,
			],
		];
		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setWrapperLegendMsg( 'abusefilter-examine-legend' )
			->setSubmitTextMsg( 'abusefilter-examine-submit' )
			->setFormIdentifier( 'examine-select-date' )
			->setSubmitCallback( [ $this, 'showResults' ] )
			->showAlways();
	}

	/**
	 * Show search results, called as submit callback by HTMLForm
	 * @param array $formData
	 * @param HTMLForm $form
	 * @return bool
	 */
	public function showResults( array $formData, HTMLForm $form ) : bool {
		$changesList = new AbuseFilterChangesList( $this->getSkin(), $this->mTestFilter );
		$pager = new AbuseFilterExaminePager( $this, $changesList );

		$output = $changesList->beginRecentChangesList()
			. $pager->getNavigationBar()
			. $pager->getBody()
			. $pager->getNavigationBar()
			. $changesList->endRecentChangesList();

		$form->addPostText( $output );
		return true;
	}

	/**
	 * @param int $rcid
	 */
	public function showExaminerForRC( $rcid ) {
		// Get data
		$rc = RecentChange::newFromId( $rcid );
		$out = $this->getOutput();
		if ( !$rc ) {
			$out->addWikiMsg( 'abusefilter-examine-notfound' );
			return;
		}

		if ( !ChangesList::userCan( $rc, RevisionRecord::SUPPRESSED_ALL ) ) {
			$out->addWikiMsg( 'abusefilter-log-details-hidden-implicit' );
			return;
		}

		$vars = new AbuseFilterVariableHolder();
		$varGenerator = new RCVariableGenerator( $vars, $rc, $this->getUser() );
		$vars = $varGenerator->getVars();
		$out->addJsConfigVars( [
			'wgAbuseFilterVariables' => $vars ? $vars->dumpAllVars( true ) : [],
			'abuseFilterExamine' => [ 'type' => 'rc', 'id' => $rcid ]
		] );

		$this->showExaminer( $vars );
	}

	/**
	 * @param int $logid
	 */
	public function showExaminerForLogEntry( $logid ) {
		// Get data
		$dbr = wfGetDB( DB_REPLICA );
		$user = $this->getUser();
		$out = $this->getOutput();
		$afPermManager = AbuseFilterServices::getPermissionManager();

		$row = $dbr->selectRow(
			'abuse_filter_log',
			[
				'afl_filter',
				'afl_deleted',
				'afl_var_dump',
				'afl_rev_id'
			],
			[ 'afl_id' => $logid ],
			__METHOD__
		);

		if ( !$row ) {
			$out->addWikiMsg( 'abusefilter-examine-notfound' );
			return;
		}

		[ $filterID, $isGlobal ] = AbuseFilter::splitGlobalName( $row->afl_filter );
		$isHidden = AbuseFilterServices::getFilterLookup()->getFilter( $filterID, $isGlobal )->isHidden();
		if ( !$afPermManager->canSeeLogDetailsForFilter( $user, $isHidden ) ) {
			$out->addWikiMsg( 'abusefilter-log-cannot-see-details' );
			return;
		}

		if ( $row->afl_deleted && !$afPermManager->canSeeHiddenLogEntries( $user ) ) {
			$out->addWikiMsg( 'abusefilter-log-details-hidden' );
			return;
		}

		if ( SpecialAbuseLog::isHidden( $row ) === 'implicit' ) {
			$revRec = MediaWikiServices::getInstance()
				->getRevisionLookup()
				->getRevisionById( (int)$row->afl_rev_id );
			if ( !AbuseFilter::userCanViewRev( $revRec, $user ) ) {
				$out->addWikiMsg( 'abusefilter-log-details-hidden-implicit' );
				return;
			}
		}
		$vars = AbuseFilter::loadVarDump( $row->afl_var_dump );
		$out->addJsConfigVars( [
			'wgAbuseFilterVariables' => $vars->dumpAllVars( true ),
			'abuseFilterExamine' => [ 'type' => 'log', 'id' => $logid ]
		] );
		$this->showExaminer( $vars );
	}

	/**
	 * @param AbuseFilterVariableHolder|null $vars
	 */
	public function showExaminer( $vars ) {
		$output = $this->getOutput();
		$afPermManager = AbuseFilterServices::getPermissionManager();
		$output->enableOOUI();

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
		if ( $afPermManager->canViewPrivateFilters( $this->getUser() ) ) {
			$boxBuilderFactory = AbuseFilterServices::getEditBoxBuilderFactory();
			$boxBuilder = $boxBuilderFactory->newEditBoxBuilder(
				$this,
				$this->getUser(),
				$output
			);

			$tester = Xml::tags( 'h2', null, $this->msg( 'abusefilter-examine-test' )->parse() );
			$tester .= $boxBuilder->buildEditBox( $this->mTestFilter, false, false, false );
			$tester .= $this->buildFilterLoader();
			$html .= Xml::tags( 'div', [ 'id' => 'mw-abusefilter-examine-editor' ], $tester );
			$html .= Xml::tags( 'p',
				null,
				new OOUI\ButtonInputWidget(
					[
						'label' => $this->msg( 'abusefilter-examine-test-button' )->text(),
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

	/**
	 * Loads parameters from request
	 */
	public function loadParameters() {
		$request = $this->getRequest();
		$this->mSearchPeriodStart = $request->getText( 'wpSearchPeriodStart' );
		$this->mSearchPeriodEnd = $request->getText( 'wpSearchPeriodEnd' );
		$this->mTestFilter = $request->getText( 'testfilter' );

		// Normalise username
		$searchUsername = $request->getText( 'wpSearchUser' );
		$userTitle = Title::newFromText( $searchUsername, NS_USER );
		$this->mSearchUser = $userTitle ? $userTitle->getText() : '';
	}
}
