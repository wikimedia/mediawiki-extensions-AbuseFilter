<?php

class SpecialAbuseLog extends SpecialPage {
	/**
	 * @var User
	 */
	protected $mSearchUser;

	protected $mSearchPeriodStart;

	protected $mSearchPeriodEnd;

	/**
	 * @var Title
	 */
	protected $mSearchTitle;

	/**
	 * @var string
	 */
	protected $mSearchAction;

	/**
	 * @var string
	 */
	protected $mSearchActionTaken;

	protected $mSearchWiki;

	protected $mSearchFilter;

	protected $mSearchEntries;

	protected $mSearchImpact;

	public function __construct() {
		parent::__construct( 'AbuseLog', 'abusefilter-log' );
	}

	/**
	 * @return bool
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * Main routine
	 *
	 * $parameter string is converted into the $args array, which can come in
	 * three shapes:
	 *
	 * An array of size 2: only if the URL is like Special:AbuseLog/private/id
	 * where id is the log identifier. In this case, the private details of the
	 * log (e.g. IP address) will be shown.
	 *
	 * An array of size 1: either the URL is like Special:AbuseLog/id where
	 * the id is log identifier, in which case the details of the log except for
	 * private bits (e.g. IP address) are shown, or the URL is incomplete as in
	 * Special:AbuseLog/private (without speciying id), in which case a warning
	 * is shown to the user
	 *
	 * An array of size 0 when URL is like Special:AbuseLog or an array of size
	 * 1 when the URL is like Special:AbuseFilter/ (i.e. without anything after
	 * the slash). In this case, if the parameter `hide` was passed, it will be
	 * used as the identifier of the log entry that we want to hide; otherwise,
	 * the abuse logs are shown as a list, with a search form above the list.
	 *
	 * @param string $parameter URL parameters
	 */
	public function execute( $parameter ) {
		$out = $this->getOutput();
		$request = $this->getRequest();

		AbuseFilter::addNavigationLinks(
			$this->getContext(), 'log', $this->getLinkRenderer() );

		$this->setHeaders();
		$this->outputHeader( 'abusefilter-log-summary' );
		$this->loadParameters();

		$out->setPageTitle( $this->msg( 'abusefilter-log' ) );
		$out->setRobotPolicy( "noindex,nofollow" );
		$out->setArticleRelated( false );
		$out->enableClientCache( false );

		$out->addModuleStyles( 'ext.abuseFilter' );

		// Are we allowed?
		$errors = $this->getPageTitle()->getUserPermissionsErrors(
			'abusefilter-log', $this->getUser(), true, [ 'ns-specialprotected' ] );
		if ( count( $errors ) ) {
			$out->showPermissionsErrorPage( $errors, 'abusefilter-log' );

			return;
		}

		$detailsid = $request->getIntOrNull( 'details' );
		$hideid = $request->getIntOrNull( 'hide' );
		$args = explode( '/', $parameter );

		if ( count( $args ) === 2 && $args[0] === 'private' ) {
			$this->showPrivateDetails( $args[1] );
		} elseif ( count( $args ) === 1 && $args[0] !== '' ) {
			if ( $args[0] === 'private' ) {
				$out->addWikiMsg( 'abusefilter-invalid-request-noid' );
			} else {
				$this->showDetails( $args[0] );
			}
		} else {
			if ( $hideid ) {
				$this->showHideForm( $hideid );
			} else {
				$this->searchForm();
				$this->showList();
			}
		}
	}

	/**
	 * Loads parameters from request
	 */
	public function loadParameters() {
		$request = $this->getRequest();

		$searchUsername = trim( $request->getText( 'wpSearchUser' ) );
		$userTitle = Title::newFromText( $searchUsername, NS_USER );
		$this->mSearchUser = $userTitle ? $userTitle->getText() : null;
		if ( $this->getConfig()->get( 'AbuseFilterIsCentral' ) ) {
			$this->mSearchWiki = $request->getText( 'wpSearchWiki' );
		}

		$this->mSearchPeriodStart = $request->getText( 'wpSearchPeriodStart' );
		$this->mSearchPeriodEnd = $request->getText( 'wpSearchPeriodEnd' );
		$this->mSearchTitle = $request->getText( 'wpSearchTitle' );
		$this->mSearchFilter = null;
		$this->mSearchAction = $request->getText( 'wpSearchAction' );
		$this->mSearchActionTaken = $request->getText( 'wpSearchActionTaken' );
		if ( self::canSeeDetails() ) {
			$this->mSearchFilter = $request->getText( 'wpSearchFilter' );
		}

		$this->mSearchEntries = $request->getText( 'wpSearchEntries' );
		$this->mSearchImpact = $request->getText( 'wpSearchImpact' );
	}

	/**
	 * @return string[]
	 */
	private function getAllActions() {
		$config = $this->getConfig();
		return array_unique(
			array_merge(
				array_keys( $config->get( 'AbuseFilterActions' ) ),
				array_keys( $config->get( 'AbuseFilterCustomActionsHandlers' ) )
			)
		);
	}

	/**
	 * @return string[]
	 */
	private function getAllFilterableActions() {
		return [
			'edit',
			'move',
			'upload',
			'stashupload',
			'delete',
			'createaccount',
			'autocreateaccount',
		];
	}

	/**
	 * Builds the search form
	 */
	public function searchForm() {
		$formDescriptor = [
			'SearchUser' => [
				'label-message' => 'abusefilter-log-search-user',
				'type' => 'user',
				'ipallowed' => true,
				'default' => $this->mSearchUser,
			],
			'SearchPeriodStart' => [
				'label-message' => 'abusefilter-test-period-start',
				'type' => 'datetime',
				'default' => $this->mSearchPeriodStart
			],
			'SearchPeriodEnd' => [
				'label-message' => 'abusefilter-test-period-end',
				'type' => 'datetime',
				'default' => $this->mSearchPeriodEnd
			],
			'SearchTitle' => [
				'label-message' => 'abusefilter-log-search-title',
				'type' => 'title',
				'default' => $this->mSearchTitle,
				'required' => false
			],
			'SearchImpact' => [
				'label-message' => 'abusefilter-log-search-impact',
				'type' => 'select',
				'options' => [
					$this->msg( 'abusefilter-log-search-impact-all' )->text() => 0,
					$this->msg( 'abusefilter-log-search-impact-saved' )->text() => 1,
					$this->msg( 'abusefilter-log-search-impact-not-saved' )->text() => 2,
				],
			],
		];
		$filterableActions = $this->getAllFilterableActions();
		$actions = array_combine( $filterableActions, $filterableActions );
		$actions[ $this->msg( 'abusefilter-log-search-action-other' )->text() ] = 'other';
		$actions[ $this->msg( 'abusefilter-log-search-action-any' )->text() ] = 'any';
		$formDescriptor['SearchAction'] = [
			'label-message' => 'abusefilter-log-search-action-label',
			'type' => 'select',
			'options' => $actions,
			'default' => 'any',
		];
		$options = [
			$this->msg( 'abusefilter-log-noactions' )->text() => 'noactions',
			$this->msg( 'abusefilter-log-search-action-taken-any' )->text() => '',
		];
		foreach ( $this->getAllActions() as $action ) {
			$key = AbuseFilter::getActionDisplay( $action );
			$options[$key] = $action;
		}
		ksort( $options );
		$formDescriptor['SearchActionTaken'] = [
			'label-message' => 'abusefilter-log-search-action-taken-label',
			'type' => 'select',
			'options' => $options,
		];
		if ( self::canSeeHidden() ) {
			$formDescriptor['SearchEntries'] = [
				'type' => 'select',
				'label-message' => 'abusefilter-log-search-entries-label',
				'options' => [
					$this->msg( 'abusefilter-log-search-entries-all' )->text() => 0,
					$this->msg( 'abusefilter-log-search-entries-hidden' )->text() => 1,
					$this->msg( 'abusefilter-log-search-entries-visible' )->text() => 2,
				],
			];
		}
		if ( self::canSeeDetails() ) {
			$formDescriptor['SearchFilter'] = [
				'label-message' => 'abusefilter-log-search-filter',
				'type' => 'text',
				'default' => $this->mSearchFilter,
			];
		}
		if ( $this->getConfig()->get( 'AbuseFilterIsCentral' ) ) {
			// Add free form input for wiki name. Would be nice to generate
			// a select with unique names in the db at some point.
			$formDescriptor['SearchWiki'] = [
				'label-message' => 'abusefilter-log-search-wiki',
				'type' => 'text',
				'default' => $this->mSearchWiki,
			];
		}

		HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setWrapperLegendMsg( 'abusefilter-log-search' )
			->setSubmitTextMsg( 'abusefilter-log-search-submit' )
			->setMethod( 'get' )
			->prepareForm()
			->displayForm( false );
	}

	/**
	 * @param string $id
	 */
	public function showHideForm( $id ) {
		$output = $this->getOutput();
		if ( !$this->getUser()->isAllowed( 'abusefilter-hide-log' ) ) {
			$output->addWikiMsg( 'abusefilter-log-hide-forbidden' );

			return;
		}

		$dbr = wfGetDB( DB_REPLICA );

		$row = $dbr->selectRow(
			[ 'abuse_filter_log', 'abuse_filter' ],
			'afl_deleted',
			[ 'afl_id' => $id ],
			__METHOD__,
			[],
			[ 'abuse_filter' => [ 'LEFT JOIN', 'af_id=afl_filter' ] ]
		);

		if ( !$row ) {
			return;
		}

		$hideReasonsOther = $this->msg( 'revdelete-reasonotherlist' )->text();
		$hideReasons = $this->msg( 'revdelete-reason-dropdown' )->inContentLanguage()->text();
		$hideReasons = Xml::listDropDownOptions( $hideReasons, [ 'other' => $hideReasonsOther ] );

		$formInfo = [
			'logid' => [
				'type' => 'info',
				'default' => (string)$id,
				'label-message' => 'abusefilter-log-hide-id',
			],
			'dropdownreason' => [
				'type' => 'select',
				'options' => $hideReasons,
				'label-message' => 'abusefilter-log-hide-reason'
			],
			'reason' => [
				'type' => 'text',
				'label-message' => 'abusefilter-log-hide-reason-other',
			],
			'hidden' => [
				'type' => 'toggle',
				'default' => $row->afl_deleted,
				'label-message' => 'abusefilter-log-hide-hidden',
			],
		];

		HTMLForm::factory( 'ooui', $formInfo, $this->getContext() )
			->setTitle( $this->getPageTitle() )
			->setWrapperLegend( $this->msg( 'abusefilter-log-hide-legend' )->text() )
			->addHiddenField( 'hide', $id )
			->setSubmitCallback( [ $this, 'saveHideForm' ] )
			->show();

		// Show suppress log for this entry
		$suppressLogPage = new LogPage( 'suppress' );
		$output->addHTML( "<h2>" . $suppressLogPage->getName()->escaped() . "</h2>\n" );
		LogEventsList::showLogExtract( $output, 'suppress', $this->getPageTitle( $id ) );
	}

	/**
	 * @param array $fields
	 * @return bool
	 */
	public function saveHideForm( $fields ) {
		$logid = $this->getRequest()->getVal( 'hide' );

		$dbw = wfGetDB( DB_MASTER );

		$dbw->update(
			'abuse_filter_log',
			[ 'afl_deleted' => $fields['hidden'] ],
			[ 'afl_id' => $logid ],
			__METHOD__
		);

		$reason = $fields['dropdownreason'];
		if ( $reason === 'other' ) {
			$reason = $fields['reason'];
		} elseif ( $fields['reason'] !== '' ) {
			$reason .=
				$this->msg( 'colon-separator' )->inContentLanguage()->text() . $fields['reason'];
		}

		$action = $fields['hidden'] ? 'hide-afl' : 'unhide-afl';
		$logEntry = new ManualLogEntry( 'suppress', $action );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $this->getPageTitle( $logid ) );
		$logEntry->setComment( $reason );
		$logEntry->insert();

		$this->getOutput()->redirect( SpecialPage::getTitleFor( 'AbuseLog' )->getFullURL() );

		return true;
	}

	/**
	 * Shows the results list
	 */
	public function showList() {
		$out = $this->getOutput();

		// Generate conditions list.
		$conds = [];

		if ( $this->mSearchUser ) {
			$user = User::newFromName( $this->mSearchUser );

			if ( !$user ) {
				$conds['afl_user'] = 0;
				$conds['afl_user_text'] = $this->mSearchUser;
			} else {
				$conds['afl_user'] = $user->getId();
				$conds['afl_user_text'] = $user->getName();
			}
		}

		$dbr = wfGetDB( DB_REPLICA );
		if ( $this->mSearchPeriodStart ) {
			$conds[] = 'afl_timestamp >= ' .
				$dbr->addQuotes( $dbr->timestamp( strtotime( $this->mSearchPeriodStart ) ) );
		}

		if ( $this->mSearchPeriodEnd ) {
			$conds[] = 'afl_timestamp <= ' .
				$dbr->addQuotes( $dbr->timestamp( strtotime( $this->mSearchPeriodEnd ) ) );
		}

		if ( $this->mSearchWiki ) {
			if ( $this->mSearchWiki == wfWikiID() ) {
				$conds['afl_wiki'] = null;
			} else {
				$conds['afl_wiki'] = $this->mSearchWiki;
			}
		}

		if ( $this->mSearchFilter ) {
			$searchFilters = array_map( 'trim', explode( '|', $this->mSearchFilter ) );
			// if a filter is hidden, users who can't view private filters should
			// not be able to find log entries generated by it.
			if ( !AbuseFilterView::canViewPrivate()
				&& !$this->getUser()->isAllowed( 'abusefilter-log-private' )
			) {
				$searchedForPrivate = false;
				foreach ( $searchFilters as $index => $filter ) {
					if ( AbuseFilter::filterHidden( $filter ) ) {
						unset( $searchFilters[$index] );
						$searchedForPrivate = true;
					}
				}
				if ( $searchedForPrivate ) {
					$out->addWikiMsg( 'abusefilter-log-private-not-included' );
				}
			}
			if ( empty( $searchFilters ) ) {
				$out->addWikiMsg( 'abusefilter-log-noresults' );

				return;
			}
			$conds['afl_filter'] = $searchFilters;
		}

		$searchTitle = Title::newFromText( $this->mSearchTitle );
		if ( $this->mSearchTitle && $searchTitle ) {
			$conds['afl_namespace'] = $searchTitle->getNamespace();
			$conds['afl_title'] = $searchTitle->getDBkey();
		}

		if ( self::canSeeHidden() ) {
			if ( $this->mSearchEntries == '1' ) {
				$conds['afl_deleted'] = 1;
			} elseif ( $this->mSearchEntries == '2' ) {
				$conds[] = self::getNotDeletedCond( $dbr );
			}
		}

		if ( in_array( $this->mSearchImpact, [ '1', '2' ] ) ) {
			$unsuccessfulActionConds = $dbr->makeList( [
				'afl_rev_id' => null,
				'afl_log_id' => null,
			], LIST_AND );
			if ( $this->mSearchImpact == '1' ) {
				$conds[] = "NOT ( $unsuccessfulActionConds )";
			} else {
				$conds[] = $unsuccessfulActionConds;
			}
		}

		if ( $this->mSearchActionTaken ) {
			if ( in_array( $this->mSearchActionTaken, $this->getAllActions() ) ) {
				$list = [ 'afl_actions' => $this->mSearchActionTaken ];
				$list[] = 'afl_actions' . $dbr->buildLike(
					$this->mSearchActionTaken, ',', $dbr->anyString() );
				$list[] = 'afl_actions' . $dbr->buildLike(
					$dbr->anyString(), ',', $this->mSearchActionTaken );
				$list[] = 'afl_actions' . $dbr->buildLike(
					$dbr->anyString(),
					',', $this->mSearchActionTaken, ',',
					$dbr->anyString()
				);
				$conds[] = $dbr->makeList( $list, LIST_OR );
			} elseif ( $this->mSearchActionTaken === 'noactions' ) {
				$conds['afl_actions'] = '';
			}
		}

		if ( $this->mSearchAction ) {
			$filterableActions = $this->getAllFilterableActions();
			if ( in_array( $this->mSearchAction, $filterableActions ) ) {
				$conds['afl_action'] = $this->mSearchAction;
			} elseif ( $this->mSearchAction === 'other' ) {
				$list = $dbr->makeList( [ 'afl_action' => $filterableActions ], LIST_OR );
				$conds[] = "NOT ( $list )";
			}
		}

		$pager = new AbuseLogPager( $this, $conds );
		$pager->doQuery();
		$result = $pager->getResult();
		if ( $result && $result->numRows() !== 0 ) {
			$out->addHTML( $pager->getNavigationBar() .
				Xml::tags( 'ul', [ 'class' => 'plainlinks' ], $pager->getBody() ) .
				$pager->getNavigationBar() );
		} else {
			$out->addWikiMsg( 'abusefilter-log-noresults' );
		}
	}

	/**
	 * @param string $id
	 */
	public function showDetails( $id ) {
		$out = $this->getOutput();

		$dbr = wfGetDB( DB_REPLICA );

		$row = $dbr->selectRow(
			[ 'abuse_filter_log', 'abuse_filter' ],
			'*',
			[ 'afl_id' => $id ],
			__METHOD__,
			[],
			[ 'abuse_filter' => [ 'LEFT JOIN', 'af_id=afl_filter' ] ]
		);

		if ( !$row ) {
			$out->addWikiMsg( 'abusefilter-log-nonexistent' );

			return;
		}

		if ( AbuseFilter::decodeGlobalName( $row->afl_filter ) ) {
			$filter_hidden = null;
		} else {
			$filter_hidden = $row->af_hidden;
		}

		if ( !self::canSeeDetails( $row->afl_filter, $filter_hidden ) ) {
			$out->addWikiMsg( 'abusefilter-log-cannot-see-details' );

			return;
		}

		if ( self::isHidden( $row ) === true && !self::canSeeHidden() ) {
			$out->addWikiMsg( 'abusefilter-log-details-hidden' );

			return;
		} elseif ( self::isHidden( $row ) === 'implicit' ) {
			$rev = Revision::newFromId( $row->afl_rev_id );
			// The log is visible, but refers to a deleted revision
			if ( !$rev->userCan( Revision::SUPPRESSED_ALL, $this->getUser() ) ) {
				$out->addWikiMsg( 'abusefilter-log-details-hidden-implicit' );
				return;
			}
		}

		$output = Xml::element(
			'legend',
			null,
			$this->msg( 'abusefilter-log-details-legend' )
				->numParams( $id )
				->text()
		);
		$output .= Xml::tags( 'p', null, $this->formatRow( $row, false ) );

		// Load data
		$vars = AbuseFilter::loadVarDump( $row->afl_var_dump );
		$out->addJsConfigVars( 'wgAbuseFilterVariables', $vars->dumpAllVars( true ) );

		// Diff, if available
		if ( $vars && $vars->getVar( 'action' )->toString() == 'edit' ) {
			$old_wikitext = $vars->getVar( 'old_wikitext' )->toString();
			$new_wikitext = $vars->getVar( 'new_wikitext' )->toString();

			$diffEngine = new DifferenceEngine( $this->getContext() );

			$diffEngine->showDiffStyle();

			$formattedDiff = $diffEngine->addHeader(
				$diffEngine->generateTextDiffBody( $old_wikitext, $new_wikitext ),
				'', ''
			);

			$output .=
				Xml::tags(
					'h3',
					null,
					$this->msg( 'abusefilter-log-details-diff' )->parse()
				);

			$output .= $formattedDiff;
		}

		$output .= Xml::element( 'h3', null, $this->msg( 'abusefilter-log-details-vars' )->text() );

		// Build a table.
		$output .= AbuseFilter::buildVarDumpTable( $vars, $this->getContext() );

		if ( self::canSeePrivate() ) {
			$formDescriptor = [
				'Reason' => [
					'label-message' => 'abusefilter-view-private-reason',
					'type' => 'text',
					'size' => 45,
				],
			];

			$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
			$htmlForm->setWrapperLegendMsg( 'abusefilter-view-private' )
				->setAction( $this->getPageTitle( 'private/' . $id )->getLocalURL() )
				->setSubmitTextMsg( 'abusefilter-view-private-submit' )
				->setMethod( 'post' )
				->prepareForm();

			$output .= $htmlForm->getHTML( false );
		}

		$out->addHTML( Xml::tags( 'fieldset', null, $output ) );
	}

	/**
	 * @param string $id
	 * @return void
	 */
	public function showPrivateDetails( $id ) {
		$lang = $this->getLanguage();
		$out = $this->getOutput();
		$request = $this->getRequest();

		$dbr = wfGetDB( DB_REPLICA );

		$reason = $request->getText( 'wpReason' );

		// Make sure it is a valid request
		$token = $request->getVal( 'wpEditToken' );
		if ( !$request->wasPosted() || !$this->getUser()->matchEditToken( $token ) ) {
			$out->addHTML(
				Xml::tags(
					'p',
					null,
					Html::errorBox( $this->msg( 'abusefilter-invalid-request' )->params( $id )->parse() )
				)
			);

			return;
		}

		if ( !$this->checkReason( $reason ) ) {
			$out->addWikiMsg( 'abusefilter-noreason' );
			$this->showDetails( $id );
			return;
		}

		$row = $dbr->selectRow(
			[ 'abuse_filter_log', 'abuse_filter' ],
			[ 'afl_id', 'afl_filter', 'afl_user_text', 'afl_timestamp', 'afl_ip', 'af_id',
				 'af_public_comments', 'af_hidden' ],
			[ 'afl_id' => $id ],
			__METHOD__,
			[],
			[ 'abuse_filter' => [ 'LEFT JOIN', 'af_id=afl_filter' ] ]
		);

		if ( !$row ) {
			$out->addWikiMsg( 'abusefilter-log-nonexistent' );

			return;
		}

		if ( AbuseFilter::decodeGlobalName( $row->afl_filter ) ) {
			$filter_hidden = null;
		} else {
			$filter_hidden = $row->af_hidden;
		}

		if ( !self::canSeeDetails( $row->afl_filter, $filter_hidden ) ) {
			$out->addWikiMsg( 'abusefilter-log-cannot-see-details' );

			return;
		}

		if ( !self::canSeePrivate() ) {
			$out->addWikiMsg( 'abusefilter-log-cannot-see-private-details' );

			return;
		}

		// Log accessing private details
		if ( $this->getConfig()->get( 'AbuseFilterPrivateLog' ) ) {
			$user = $this->getUser();
			self::addLogEntry( $id, $reason, $user );
		}

		// Show private details (IP).
		$output = Xml::element(
			'legend',
			null,
			$this->msg( 'abusefilter-log-details-private' )->text()
		);

		$header =
			Xml::element( 'th', null, $this->msg( 'abusefilter-log-details-var' )->text() ) .
			Xml::element( 'th', null, $this->msg( 'abusefilter-log-details-val' )->text() );

		$output .=
			Xml::openElement( 'table',
				[
					'class' => 'wikitable mw-abuselog-private',
					'style' => 'width: 80%;'
				]
			) .
			Xml::openElement( 'tbody' );
		$output .= $header;

		// Log ID
		$linkRenderer = $this->getLinkRenderer();
		$output .=
			Xml::tags( 'tr', null,
				Xml::element( 'td',
					[ 'style' => 'width: 30%;' ],
					$this->msg( 'abusefilter-log-details-id' )->text()
				) .
				Xml::openElement( 'td' ) .
				$linkRenderer->makeKnownLink(
					$this->getPageTitle( $row->afl_id ),
					$lang->formatNum( $row->afl_id )
				) .
				Xml::closeElement( 'td' )
			);

		// Timestamp
		$output .=
			Xml::tags( 'tr', null,
				Xml::element( 'td',
					[ 'style' => 'width: 30%;' ],
					$this->msg( 'abusefilter-edit-builder-vars-timestamp-expanded' )->text()
				) .
				Xml::element( 'td',
					null,
					$lang->timeanddate( $row->afl_timestamp, true )
				)
			);

		// User
		$output .=
			Xml::tags( 'tr', null,
				Xml::element( 'td',
					[ 'style' => 'width: 30%;' ],
					$this->msg( 'abusefilter-edit-builder-vars-user-name' )->text()
				) .
				Xml::element( 'td',
					null,
					$row->afl_user_text
				)
			);

		// Filter ID
		$output .=
			Xml::tags( 'tr', null,
				Xml::element( 'td',
					[ 'style' => 'width: 30%;' ],
					$this->msg( 'abusefilter-list-id' )->text()
				) .
				Xml::openElement( 'td' ) .
				$linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( 'AbuseFilter', $row->af_id ),
					$lang->formatNum( $row->af_id )
				) .
				Xml::closeElement( 'td' )
			);

		// Filter description
		$output .=
			Xml::tags( 'tr', null,
				Xml::element( 'td',
					[ 'style' => 'width: 30%;' ],
					$this->msg( 'abusefilter-list-public' )->text()
				) .
				Xml::element( 'td',
					null,
					$row->af_public_comments
				)
			);

		// IP address
		if ( $row->afl_ip !== '' ) {
			if ( ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' ) &&
				$this->getUser()->isAllowed( 'checkuser' ) ) {
					$CULink = '&nbsp;&middot;&nbsp;' . $linkRenderer->makeKnownLink(
						SpecialPage::getTitleFor(
							'CheckUser',
							$row->afl_ip
						),
						$this->msg( 'abusefilter-log-details-checkuser' )->text()
					);
			} else {
				$CULink = '';
			}
			$output .=
				Xml::tags( 'tr', null,
					Xml::element( 'td',
						[ 'style' => 'width: 30%;' ],
						$this->msg( 'abusefilter-log-details-ip' )->text()
					) .
					Xml::tags(
						'td',
						null,
						self::getUserLinks( 0, $row->afl_ip ) . $CULink
					)
				);
		} else {
			$output .=
				Xml::tags( 'tr', null,
					Xml::element( 'td',
						[ 'style' => 'width: 30%;' ],
						$this->msg( 'abusefilter-log-details-ip' )->text()
					) .
					Xml::element(
						'td',
						null,
						$this->msg( 'abusefilter-log-ip-not-available' )->text()
					)
				);
		}

		$output .= Xml::closeElement( 'tbody' ) . Xml::closeElement( 'table' );

		$output = Xml::tags( 'fieldset', null, $output );

		$out->addHTML( $output );
	}

	/**
	 * If specifying a reason for viewing private details of abuse log is required
	 * then it makes sure that a reason is provided.
	 *
	 * @param string $reason
	 * @return bool
	 */
	protected function checkReason( $reason ) {
		return ( !$this->getConfig()->get( 'AbuseFilterForceSummary' ) || strlen( $reason ) > 0 );
	}

	/**
	 * @param int $logID int The ID of the AbuseFilter log that was accessed
	 * @param string $reason The reason provided for accessing private details
	 * @param User $user The user who accessed the private details
	 * @return void
	 */
	public static function addLogEntry( $logID, $reason, $user ) {
		$target = self::getTitleFor( 'AbuseLog', $logID );

		$logEntry = new ManualLogEntry( 'abusefilterprivatedetails', 'access' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $target );
		$logEntry->setParameters( [
			'4::logid' => $logID,
		] );
		$logEntry->setComment( $reason );

		$logEntry->insert();
	}

	/**
	 * @param string|null $filter_id
	 * @param bool|int|null $filter_hidden
	 * @return bool
	 */
	public static function canSeeDetails( $filter_id = null, $filter_hidden = null ) {
		global $wgUser;

		if ( $filter_id !== null ) {
			if ( $filter_hidden === null ) {
				$filter_hidden = AbuseFilter::filterHidden( $filter_id );
			}
			if ( $filter_hidden ) {
				return $wgUser->isAllowed( 'abusefilter-log-detail' ) && (
					AbuseFilterView::canViewPrivate() || $wgUser->isAllowed( 'abusefilter-log-private' )
				);
			}
		}

		return $wgUser->isAllowed( 'abusefilter-log-detail' );
	}

	/**
	 * @return bool
	 */
	public static function canSeePrivate() {
		global $wgUser;

		return $wgUser->isAllowed( 'abusefilter-private' );
	}

	/**
	 * @return bool
	 */
	public static function canSeeHidden() {
		global $wgUser;

		return $wgUser->isAllowed( 'abusefilter-hidden-log' );
	}

	/**
	 * @param stdClass $row
	 * @param bool $isListItem
	 * @return String
	 */
	public function formatRow( $row, $isListItem = true ) {
		$user = $this->getUser();
		$lang = $this->getLanguage();

		$actionLinks = [];

		$title = Title::makeTitle( $row->afl_namespace, $row->afl_title );

		$diffLink = false;
		$isHidden = self::isHidden( $row );

		if ( !self::canSeeHidden() && $isHidden === true ) {
			return '';
		}

		$linkRenderer = $this->getLinkRenderer();

		if ( !$row->afl_wiki ) {
			$pageLink = $linkRenderer->makeLink( $title );
			if ( $row->afl_rev_id && $title->exists() ) {
				$diffLink = $linkRenderer->makeKnownLink(
					$title,
					new HtmlArmor( $this->msg( 'abusefilter-log-diff' )->parse() ),
					[],
					[ 'diff' => 'prev', 'oldid' => $row->afl_rev_id ] );
			}
		} else {
			$pageLink = WikiMap::makeForeignLink( $row->afl_wiki, $row->afl_title );

			if ( $row->afl_rev_id ) {
				$diffUrl = WikiMap::getForeignURL( $row->afl_wiki, $row->afl_title );
				$diffUrl = wfAppendQuery( $diffUrl,
					[ 'diff' => 'prev', 'oldid' => $row->afl_rev_id ] );

				$diffLink = Linker::makeExternalLink( $diffUrl,
					$this->msg( 'abusefilter-log-diff' )->parse() );
			}
		}

		if ( !$row->afl_wiki ) {
			// Local user
			$userLink = self::getUserLinks( $row->afl_user, $row->afl_user_text );
		} else {
			$userLink = WikiMap::foreignUserLink( $row->afl_wiki, $row->afl_user_text );
			$userLink .= ' (' . WikiMap::getWikiName( $row->afl_wiki ) . ')';
		}

		$timestamp = $lang->timeanddate( $row->afl_timestamp, true );

		$actions_taken = $row->afl_actions;
		if ( !strlen( trim( $actions_taken ) ) ) {
			$actions_taken = $this->msg( 'abusefilter-log-noactions' )->escaped();
		} else {
			$actions = explode( ',', $actions_taken );
			$displayActions = [];

			foreach ( $actions as $action ) {
				$displayActions[] = AbuseFilter::getActionDisplay( $action );
			}
			$actions_taken = $lang->commaList( $displayActions );
		}

		$globalIndex = AbuseFilter::decodeGlobalName( $row->afl_filter );

		if ( $globalIndex ) {
			// Pull global filter description
			$escaped_comments = Sanitizer::escapeHtmlAllowEntities(
				AbuseFilter::getGlobalFilterDescription( $globalIndex ) );
			$filter_hidden = null;
		} else {
			$escaped_comments = Sanitizer::escapeHtmlAllowEntities(
				$row->af_public_comments );
			$filter_hidden = $row->af_hidden;
		}

		if ( self::canSeeDetails( $row->afl_filter, $filter_hidden ) ) {
			if ( $isListItem ) {
				$detailsLink = $linkRenderer->makeKnownLink(
					$this->getPageTitle( $row->afl_id ),
					$this->msg( 'abusefilter-log-detailslink' )->text()
				);
				$actionLinks[] = $detailsLink;
			}

			$examineTitle = SpecialPage::getTitleFor( 'AbuseFilter', 'examine/log/' . $row->afl_id );
			$examineLink = $linkRenderer->makeKnownLink(
				$examineTitle,
				new HtmlArmor( $this->msg( 'abusefilter-changeslist-examine' )->parse() )
			);
			$actionLinks[] = $examineLink;

			if ( $diffLink ) {
				$actionLinks[] = $diffLink;
			}

			if ( $user->isAllowed( 'abusefilter-hide-log' ) ) {
				$hideLink = $linkRenderer->makeKnownLink(
					$this->getPageTitle(),
					$this->msg( 'abusefilter-log-hidelink' )->text(),
					[],
					[ 'hide' => $row->afl_id ]
				);

				$actionLinks[] = $hideLink;
			}

			if ( $globalIndex ) {
				$globalURL = WikiMap::getForeignURL(
					$this->getConfig()->get( 'AbuseFilterCentralDB' ),
					'Special:AbuseFilter/' . $globalIndex
				);
				$linkText = $this->msg( 'abusefilter-log-detailedentry-global' )
					->numParams( $globalIndex )->escaped();
				$filterLink = Linker::makeExternalLink( $globalURL, $linkText );
			} else {
				$title = SpecialPage::getTitleFor( 'AbuseFilter', $row->afl_filter );
				$linkText = $this->msg( 'abusefilter-log-detailedentry-local' )
					->numParams( $row->afl_filter )->text();
				$filterLink = $linkRenderer->makeKnownLink( $title, $linkText );
			}
			$description = $this->msg( 'abusefilter-log-detailedentry-meta' )->rawParams(
				$timestamp,
				$userLink,
				$filterLink,
				$row->afl_action,
				$pageLink,
				$actions_taken,
				$escaped_comments,
				$lang->pipeList( $actionLinks )
			)->params( $row->afl_user_text )->parse();
		} else {
			if ( $diffLink ) {
				$msg = 'abusefilter-log-entry-withdiff';
			} else {
				$msg = 'abusefilter-log-entry';
			}
			$description = $this->msg( $msg )->rawParams(
				$timestamp,
				$userLink,
				$row->afl_action,
				$pageLink,
				$actions_taken,
				$escaped_comments,
				// Passing $7 to 'abusefilter-log-entry' will do nothing, as it's not used.
				$diffLink
			)->params( $row->afl_user_text )->parse();
		}

		if ( $isHidden === true ) {
			$description .= ' ' .
				$this->msg( 'abusefilter-log-hidden' )->parse();
			$class = 'afl-hidden';
		} elseif ( $isHidden === 'implicit' ) {
			$description .= ' ' .
				$this->msg( 'abusefilter-log-hidden-implicit' )->parse();
		}

		if ( $isListItem ) {
			return Xml::tags( 'li', isset( $class ) ? [ 'class' => $class ] : null, $description );
		} else {
			return Xml::tags( 'span', isset( $class ) ? [ 'class' => $class ] : null, $description );
		}
	}

	/**
	 * @param int $userId
	 * @param string $userName
	 * @return string
	 */
	protected static function getUserLinks( $userId, $userName ) {
		static $cache = [];

		if ( !isset( $cache[$userName][$userId] ) ) {
			$cache[$userName][$userId] = Linker::userLink( $userId, $userName ) .
				Linker::userToolLinks( $userId, $userName, true );
		}

		return $cache[$userName][$userId];
	}

	/**
	 * @param \Wikimedia\Rdbms\IDatabase $db
	 * @return string
	 */
	public static function getNotDeletedCond( $db ) {
		$deletedZeroCond = $db->makeList(
			[ 'afl_deleted' => 0 ], LIST_AND );
		$deletedNullCond = $db->makeList(
			[ 'afl_deleted' => null ], LIST_AND );
		$notDeletedCond = $db->makeList(
			[ $deletedZeroCond, $deletedNullCond ], LIST_OR );

		return $notDeletedCond;
	}

	/**
	 * Given a log entry row, decides whether or not it can be viewed by the public.
	 *
	 * @param stdClass $row The abuse_filter_log row object.
	 *
	 * @return bool|string true if the item is explicitly hidden, false if it is not.
	 *    The string 'implicit' if it is hidden because the corresponding revision is hidden.
	 */
	public static function isHidden( $row ) {
		// First, check if the entry is hidden. Since this is an oversight-level deletion,
		// it's more important than the associated revision being deleted.
		if ( $row->afl_deleted ) {
			return true;
		}
		if ( $row->afl_rev_id ) {
			$revision = Revision::newFromId( $row->afl_rev_id );
			if ( $revision && $revision->getVisibility() != 0 ) {
				return 'implicit';
			}
		}

		return false;
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'changes';
	}
}
