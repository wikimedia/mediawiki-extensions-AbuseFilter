<?php

/**
 * The default view used in Special:AbuseFilter
 */
class AbuseFilterViewList extends AbuseFilterView {
	/**
	 * Shows the page
	 */
	public function show() {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$config = $this->getConfig();

		// Show filter performance statistics
		$this->showStatus();

		$out->addWikiMsg( 'abusefilter-intro' );

		// New filter button
		if ( $this->canEdit() ) {
			$out->enableOOUI();
			$link = new OOUI\ButtonWidget( [
				'label' => $this->msg( 'abusefilter-new' )->text(),
				'href' => $this->getTitle( 'new' )->getFullURL(),
			] );
			$out->addHTML( $link );
		}

		$conds = [];
		$deleted = $request->getVal( 'deletedfilters' );
		$furtherOptions = $request->getArray( 'furtheroptions', [] );
		// Backward compatibility with old links
		if ( $request->getBool( 'hidedisabled' ) ) {
			$furtherOptions[] = 'hidedisabled';
		}
		if ( $request->getBool( 'hideprivate' ) ) {
			$furtherOptions[] = 'hideprivate';
		}
		$defaultscope = 'all';
		if ( $config->get( 'AbuseFilterCentralDB' ) !== null
				&& !$config->get( 'AbuseFilterIsCentral' ) ) {
			// Show on remote wikis as default only local filters
			$defaultscope = 'local';
		}
		$scope = $request->getVal( 'rulescope', $defaultscope );

		$searchEnabled = $this->canViewPrivate() && !(
			$config->get( 'AbuseFilterCentralDB' ) !== null &&
			!$config->get( 'AbuseFilterIsCentral' ) &&
			$scope == 'global' );

		if ( $searchEnabled ) {
			$querypattern = $request->getVal( 'querypattern' );
			$searchmode = $request->getVal( 'searchoption', 'LIKE' );
		}

		if ( $deleted == 'show' ) {
			// Nothing
		} elseif ( $deleted == 'only' ) {
			$conds['af_deleted'] = 1;
		} else {
			// hide, or anything else.
			$conds['af_deleted'] = 0;
			$deleted = 'hide';
		}
		if ( in_array( 'hidedisabled', $furtherOptions ) ) {
			$conds['af_deleted'] = 0;
			$conds['af_enabled'] = 1;
		}
		if ( in_array( 'hideprivate', $furtherOptions ) ) {
			$conds['af_hidden'] = 0;
		}

		if ( $scope == 'local' ) {
			$conds['af_global'] = 0;
		} elseif ( $scope == 'global' ) {
			$conds['af_global'] = 1;
		}

		$dbr = wfGetDB( DB_REPLICA );

		if ( !empty( $querypattern ) ) {
			if ( $searchmode !== 'LIKE' ) {
				// Check regex pattern validity
				Wikimedia\suppressWarnings();
				$validreg = preg_match( '/' . $querypattern . '/', null );
				Wikimedia\restoreWarnings();

				if ( $validreg === false ) {
					$out->addHTML(
						Xml::tags(
							'p',
							null,
							Html::errorBox( $this->msg( 'abusefilter-list-regexerror' )->parse() )
						)
					);
					$this->showList(
						[ 'af_deleted' => 0 ],
						compact(
							'deleted',
							'furtherOptions',
							'querypattern',
							'searchmode',
							'scope',
							'searchEnabled'
						)
					);
					return;
				}
				if ( $searchmode === 'RLIKE' ) {
					$conds[] = 'af_pattern RLIKE ' .
						$dbr->addQuotes( $querypattern );
				} else {
					$conds[] = 'LOWER( CAST( af_pattern AS char ) ) RLIKE ' .
						strtolower( $dbr->addQuotes( $querypattern ) );
				}
			} else {
				// Build like query escaping tokens and encapsulating in % to search everywhere
				$conds[] = 'LOWER( CAST( af_pattern AS char ) ) ' .
					$dbr->buildLike(
						$dbr->anyString(),
						strtolower( $querypattern ),
						$dbr->anyString()
					);
			}
		}

		$this->showList(
			$conds,
			compact(
				'deleted',
				'furtherOptions',
				'querypattern',
				'searchmode',
				'scope',
				'searchEnabled'
			)
		);
	}

	/**
	 * @param array $conds
	 * @param array $optarray
	 */
	public function showList( $conds = [ 'af_deleted' => 0 ], $optarray = [] ) {
		$config = $this->getConfig();
		$this->getOutput()->addHTML(
			Xml::tags( 'h2', null, $this->msg( 'abusefilter-list' )->parse() )
		);

		$deleted = $optarray['deleted'];
		$furtherOptions = $optarray['furtherOptions'];
		$scope = $optarray['scope'];

		$searchEnabled = $optarray['searchEnabled'];

		if ( $searchEnabled ) {
			$querypattern = $optarray['querypattern'];
			$searchmode = $optarray['searchmode'];
		} else {
			$querypattern = '';
			$searchmode = '';
		}

		if (
			$config->get( 'AbuseFilterCentralDB' ) !== null
			&& !$config->get( 'AbuseFilterIsCentral' )
			&& $scope == 'global'
		) {
			$pager = new GlobalAbuseFilterPager(
				$this,
				$conds,
				$this->linkRenderer
			);
		} else {
			$pager = new AbuseFilterPager(
				$this,
				$conds,
				$this->linkRenderer,
				[ $querypattern, $searchmode ]
			);
		}

		// Options form
		$formDescriptor = [];
		$formDescriptor['deletedfilters'] = [
			'name' => 'deletedfilters',
			'type' => 'radio',
			'flatlist' => true,
			'label-message' => 'abusefilter-list-options-deleted',
			'options-messages' => [
				'abusefilter-list-options-deleted-show' => 'show',
				'abusefilter-list-options-deleted-hide' => 'hide',
				'abusefilter-list-options-deleted-only' => 'only',
			],
			'default' => $deleted,
		];

		if ( $config->get( 'AbuseFilterCentralDB' ) !== null ) {
			$optionsMsg = [
				'abusefilter-list-options-scope-local' => 'local',
				'abusefilter-list-options-scope-global' => 'global',
			];
			if ( $config->get( 'AbuseFilterIsCentral' ) ) {
				// For central wiki: add third scope option
				$optionsMsg['abusefilter-list-options-scope-all'] = 'all';
			}
			$formDescriptor['rulescope'] = [
				'name' => 'rulescope',
				'type' => 'radio',
				'flatlist' => true,
				'label-message' => 'abusefilter-list-options-scope',
				'options-messages' => $optionsMsg,
				'default' => $scope,
			];
		}

		$formDescriptor['furtheroptions'] = [
			'name' => 'furtheroptions',
			'type' => 'multiselect',
			'label-message' => 'abusefilter-list-options-further-options',
			'flatlist' => true,
			'options' => [
				$this->msg( 'abusefilter-list-options-hideprivate' )->parse() => 'hideprivate',
				$this->msg( 'abusefilter-list-options-hidedisabled' )->parse() => 'hidedisabled',
			],
			'default' => $furtherOptions
		];

		// ToDo: Since this is only for saving space, we should convert it to use a 'hide-if'
		if ( $searchEnabled ) {
			$formDescriptor['querypattern'] = [
				'name' => 'querypattern',
				'type' => 'text',
				'label-message' => 'abusefilter-list-options-searchfield',
				'placeholder' => $this->msg( 'abusefilter-list-options-searchpattern' )->text(),
				'default' => $querypattern
			];

			$formDescriptor['searchoption'] = [
				'name' => 'searchoption',
				'type' => 'radio',
				'flatlist' => true,
				'label-message' => 'abusefilter-list-options-searchoptions',
				'options-messages' => [
					'abusefilter-list-options-search-like' => 'LIKE',
					'abusefilter-list-options-search-rlike' => 'RLIKE',
					'abusefilter-list-options-search-irlike' => 'IRLIKE',
				],
				'default' => $searchmode
			];
		}

		$formDescriptor['limit'] = [
			'name' => 'limit',
			'type' => 'select',
			'label-message' => 'abusefilter-list-limit',
			'options' => $pager->getLimitSelectList(),
			'default' => $pager->getLimit(),
		];

		HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->addHiddenField( 'title', $this->getTitle()->getPrefixedDBkey() )
			->setAction( $this->getTitle()->getFullURL() )
			->setWrapperLegendMsg( 'abusefilter-list-options' )
			->setSubmitTextMsg( 'abusefilter-list-options-submit' )
			->setMethod( 'get' )
			->prepareForm()
			->displayForm( false );

		$this->getOutput()->addHTML(
			$pager->getNavigationBar() .
			$pager->getBody() .
			$pager->getNavigationBar()
		);
	}

	/**
	 * Show stats
	 */
	public function showStatus() {
		$stash = ObjectCache::getMainStashInstance();
		$overflow_count = (int)$stash->get( AbuseFilter::filterLimitReachedKey() );
		$match_count = (int)$stash->get( AbuseFilter::filterMatchesKey() );
		$total_count = 0;
		foreach ( $this->getConfig()->get( 'AbuseFilterValidGroups' ) as $group ) {
			$total_count += (int)$stash->get( AbuseFilter::filterUsedKey( $group ) );
		}

		if ( $total_count > 0 ) {
			$overflow_percent = sprintf( "%.2f", 100 * $overflow_count / $total_count );
			$match_percent = sprintf( "%.2f", 100 * $match_count / $total_count );

			$status = $this->msg( 'abusefilter-status' )
				->numParams(
					$total_count,
					$overflow_count,
					$overflow_percent,
					$this->getConfig()->get( 'AbuseFilterConditionLimit' ),
					$match_count,
					$match_percent
				)->parse();

			$status = Xml::tags( 'div', [ 'class' => 'mw-abusefilter-status' ], $status );
			$this->getOutput()->addHTML( $status );
		}
	}
}
