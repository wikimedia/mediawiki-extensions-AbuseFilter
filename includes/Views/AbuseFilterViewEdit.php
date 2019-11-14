<?php

class AbuseFilterViewEdit extends AbuseFilterView {
	/**
	 * @param SpecialAbuseFilter $page
	 * @param array $params
	 */
	function __construct( $page, $params ) {
		parent::__construct( $page, $params );
		$this->mFilter = $page->mFilter;
		$this->mHistoryID = $page->mHistoryID;
	}

	/**
	 * Check whether a filter is allowed to use a tag
	 *
	 * @param string $tag Tag name
	 * @return Status
	 */
	protected function isAllowedTag( $tag ) {
		$tagNameStatus = ChangeTags::isTagNameValid( $tag );

		if ( !$tagNameStatus->isGood() ) {
			return $tagNameStatus;
		}

		$finalStatus = Status::newGood();

		$canAddStatus =
			ChangeTags::canAddTagsAccompanyingChange(
				[ $tag ]
			);

		if ( $canAddStatus->isGood() ) {
			return $finalStatus;
		}

		$alreadyDefinedTags = [];
		AbuseFilterHooks::onListDefinedTags( $alreadyDefinedTags );

		if ( in_array( $tag, $alreadyDefinedTags, true ) ) {
			return $finalStatus;
		}

		$canCreateTagStatus = ChangeTags::canCreateTag( $tag );
		if ( $canCreateTagStatus->isGood() ) {
			return $finalStatus;
		}

		$finalStatus->fatal( 'abusefilter-edit-bad-tags' );
		return $finalStatus;
	}

	function show() {
		$user = $this->getUser();
		$out = $this->getOutput();
		$request = $this->getRequest();
		$out->setPageTitle( $this->msg( 'abusefilter-edit' ) );
		$out->addHelpLink( 'Extension:AbuseFilter/Rules format' );

		$filter = $this->mFilter;
		$history_id = $this->mHistoryID;
		if ( $this->mHistoryID ) {
			$dbr = wfGetDB( DB_REPLICA );
			$row = $dbr->selectRow(
				'abuse_filter_history',
				'afh_id',
				[
					'afh_filter' => $filter,
				],
				__METHOD__,
				[ 'ORDER BY' => 'afh_timestamp DESC' ]
			);
			// change $history_id to null if it's current version id
			if ( $row->afh_id === $this->mHistoryID ) {
				$history_id = null;
			}
		}

		// Add default warning messages
		$this->exposeWarningMessages();

		if ( $filter == 'new' && !$this->canEdit() ) {
			$out->addWikiMsg( 'abusefilter-edit-notallowed' );
			return;
		}

		$editToken = $request->getVal( 'wpEditToken' );
		$tokenMatches = $user->matchEditToken(
			$editToken, [ 'abusefilter', $filter ], $request );

		if ( $tokenMatches && $this->canEdit() ) {
			// Check syntax
			$syntaxerr = AbuseFilter::checkSyntax( $request->getVal( 'wpFilterRules' ) );
			if ( $syntaxerr !== true ) {
				$out->addHTML(
					$this->buildFilterEditor(
						$this->msg(
							'abusefilter-edit-badsyntax',
							[ $syntaxerr[0] ]
						)->parseAsBlock(),
						$filter, $history_id
					)
				);
				return;
			}
			// Check for missing required fields (title and pattern)
			$missing = [];
			if ( !$request->getVal( 'wpFilterRules' ) ||
				trim( $request->getVal( 'wpFilterRules' ) ) === '' ) {
				$missing[] = $this->msg( 'abusefilter-edit-field-conditions' )->escaped();
			}
			if ( !$request->getVal( 'wpFilterDescription' ) ) {
				$missing[] = $this->msg( 'abusefilter-edit-field-description' )->escaped();
			}
			if ( count( $missing ) !== 0 ) {
				$missing = $this->getLanguage()->commaList( $missing );
				$out->addHTML(
					$this->buildFilterEditor(
						$this->msg(
							'abusefilter-edit-missingfields',
							$missing
						)->parseAsBlock(),
						$filter, $history_id
					)
				);
				return;
			}

			$dbw = wfGetDB( DB_MASTER );

			list( $newRow, $actions ) = $this->loadRequest( $filter );

			$differences = AbuseFilter::compareVersions(
				[ $newRow, $actions ],
				[ $newRow->mOriginalRow, $newRow->mOriginalActions ]
			);

			// Don't allow adding a new global rule, or updating a
			// rule that is currently global, without permissions.
			if ( !$this->canEditFilter( $newRow ) || !$this->canEditFilter( $newRow->mOriginalRow ) ) {
				$out->addWikiMsg( 'abusefilter-edit-notallowed-global' );
				return;
			}

			// Don't allow custom messages on global rules
			if ( $newRow->af_global == 1 &&
				$request->getVal( 'wpFilterWarnMessage' ) !== 'abusefilter-warning'
			) {
				$out->addWikiMsg( 'abusefilter-edit-notallowed-global-custom-msg' );
				return;
			}

			$origActions = $newRow->mOriginalActions;
			$wasGlobal = (bool)$newRow->mOriginalRow->af_global;

			unset( $newRow->mOriginalRow );
			unset( $newRow->mOriginalActions );

			// Check for non-changes
			if ( !count( $differences ) ) {
				$out->redirect( $this->getTitle()->getLocalURL() );
				return;
			}

			// Check for restricted actions
			global $wgAbuseFilterRestrictions;
			if ( count( array_intersect_key(
					array_filter( $wgAbuseFilterRestrictions ),
					array_merge(
						array_filter( $actions ),
						array_filter( $origActions )
					)
				) )
				&& !$user->isAllowed( 'abusefilter-modify-restricted' )
			) {
				$out->addHTML(
					$this->buildFilterEditor(
						$this->msg( 'abusefilter-edit-restricted' )->parseAsBlock(),
						$this->mFilter,
						$history_id
					)
				);
				return;
			}

			// If we've activated the 'tag' option, check the arguments for validity.
			if ( !empty( $actions['tag'] ) ) {
				foreach ( $actions['tag']['parameters'] as $tag ) {
					$status = $this->isAllowedTag( $tag );

					if ( !$status->isGood() ) {
						$out->addHTML(
							$this->buildFilterEditor(
								$status->getMessage()->parseAsBlock(),
								$this->mFilter,
								$history_id
							)
						);
						return;
					}
				}
			}

			$newRow = get_object_vars( $newRow ); // Convert from object to array

			// Set last modifier.
			$newRow['af_timestamp'] = $dbw->timestamp( wfTimestampNow() );
			$newRow['af_user'] = $user->getId();
			$newRow['af_user_text'] = $user->getName();

			$dbw->startAtomic( __METHOD__ );

			// Insert MAIN row.
			if ( $filter == 'new' ) {
				$new_id = $dbw->nextSequenceValue( 'abuse_filter_af_id_seq' );
				$is_new = true;
			} else {
				$new_id = $this->mFilter;
				$is_new = false;
			}

			// Reset throttled marker, if we're re-enabling it.
			$newRow['af_throttled'] = $newRow['af_throttled'] && !$newRow['af_enabled'];
			$newRow['af_id'] = $new_id; // ID.

			// T67807
			// integer 1's & 0's might be better understood than booleans
			$newRow['af_enabled'] = (int)$newRow['af_enabled'];
			$newRow['af_hidden'] = (int)$newRow['af_hidden'];
			$newRow['af_throttled'] = (int)$newRow['af_throttled'];
			$newRow['af_deleted'] = (int)$newRow['af_deleted'];
			$newRow['af_global'] = (int)$newRow['af_global'];

			$dbw->replace( 'abuse_filter', [ 'af_id' ], $newRow, __METHOD__ );

			if ( $is_new ) {
				$new_id = $dbw->insertId();
			}

			// Actions
			global $wgAbuseFilterActions;
			$deadActions = [];
			$actionsRows = [];
			foreach ( array_filter( $wgAbuseFilterActions ) as $action => $_ ) {
				// Check if it's set
				$enabled = isset( $actions[$action] ) && (bool)$actions[$action];

				if ( $enabled ) {
					$parameters = $actions[$action]['parameters'];

					$thisRow = [
						'afa_filter' => $new_id,
						'afa_consequence' => $action,
						'afa_parameters' => implode( "\n", $parameters )
					];
					$actionsRows[] = $thisRow;
				} else {
					$deadActions[] = $action;
				}
			}

			// Create a history row
			$afh_row = [];

			foreach ( AbuseFilter::$history_mappings as $af_col => $afh_col ) {
				$afh_row[$afh_col] = $newRow[$af_col];
			}

			// Actions
			$displayActions = [];
			foreach ( $actions as $action ) {
				$displayActions[$action['action']] = $action['parameters'];
			}
			$afh_row['afh_actions'] = serialize( $displayActions );

			$afh_row['afh_changed_fields'] = implode( ',', $differences );

			// Flags
			$flags = [];
			if ( $newRow['af_hidden'] ) {
				$flags[] = 'hidden';
			}
			if ( $newRow['af_enabled'] ) {
				$flags[] = 'enabled';
			}
			if ( $newRow['af_deleted'] ) {
				$flags[] = 'deleted';
			}
			if ( $newRow['af_global'] ) {
				$flags[] = 'global';
			}

			$afh_row['afh_flags'] = implode( ',', $flags );

			$afh_row['afh_filter'] = $new_id;
			$afh_row['afh_id'] = $dbw->nextSequenceValue( 'abuse_filter_af_id_seq' );

			// Do the update
			$dbw->insert( 'abuse_filter_history', $afh_row, __METHOD__ );
			$history_id = $dbw->insertId();
			if ( $filter != 'new' ) {
				$dbw->delete(
					'abuse_filter_action',
					[ 'afa_filter' => $filter ],
					__METHOD__
				);
			}
			$dbw->insert( 'abuse_filter_action', $actionsRows, __METHOD__ );

			$dbw->endAtomic( __METHOD__ );

			// Invalidate cache if this was a global rule
			if ( $wasGlobal || $newRow['af_global'] ) {
				$group = 'default';
				if ( isset( $newRow['af_group'] ) && $newRow['af_group'] != '' ) {
					$group = $newRow['af_group'];
				}

				$globalRulesKey = AbuseFilter::getGlobalRulesKey( $group );
				ObjectCache::getMainWANInstance()->touchCheckKey( $globalRulesKey );
			}

			// Logging
			$subtype = $filter === 'new' ? 'create' : 'modify';
			$logEntry = new ManualLogEntry( 'abusefilter', $subtype );
			$logEntry->setPerformer( $user );
			$logEntry->setTarget( $this->getTitle( $new_id ) );
			$logEntry->setParameters( [
				'historyId' => $history_id,
				'newId' => $new_id
			] );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );

			// Purge the tag list cache so the fetchAllTags hook applies tag changes
			if ( isset( $actions['tag'] ) ) {
				AbuseFilterHooks::purgeTagCache();
			}

			AbuseFilter::resetFilterProfile( $new_id );

			$out->redirect(
				$this->getTitle()->getLocalURL(
					[
						'result' => 'success',
						'changedfilter' => $new_id,
						'changeid' => $history_id,
					]
				)
			);
		} else {
			if ( $tokenMatches ) {
				// lost rights meanwhile
				$out->addWikiMsg( 'abusefilter-edit-notallowed' );
			}

			if ( $history_id ) {
				$out->addWikiMsg(
					'abusefilter-edit-oldwarning', $history_id, $filter );
			}

			$out->addHTML( $this->buildFilterEditor( null, $filter, $history_id ) );

			if ( $history_id ) {
				$out->addWikiMsg(
					'abusefilter-edit-oldwarning', $history_id, $filter );
			}
		}
	}

	/**
	 * Builds the full form for edit filters.
	 * Loads data either from the database or from the HTTP request.
	 * The request takes precedence over the database
	 * @param string $error An error message to show above the filter box.
	 * @param int $filter The filter ID
	 * @param int $history_id The history ID of the filter, if applicable. Otherwise null
	 * @return bool|string False if there is a failure building the editor,
	 *   otherwise the HTML text for the editor.
	 */
	function buildFilterEditor( $error, $filter, $history_id = null ) {
		if ( $filter === null ) {
			return false;
		}

		// Build the edit form
		$out = $this->getOutput();
		$lang = $this->getLanguage();
		$user = $this->getUser();

		// Load from request OR database.
		list( $row, $actions ) = $this->loadRequest( $filter, $history_id );

		if (
			!$row ||
			// @fixme Temporary stopgap for T237887
			( $history_id && $row->af_id !== $filter )
		) {
			$out->addWikiMsg( 'abusefilter-edit-badfilter' );
			$out->addHTML( $this->linkRenderer->makeLink( $this->getTitle(),
				$this->msg( 'abusefilter-return' )->text() ) );
			return false;
		}

		$out->addSubtitle( $this->msg(
			$filter === 'new' ? 'abusefilter-edit-subtitle-new' : 'abusefilter-edit-subtitle',
			$this->getLanguage()->formatNum( $filter ), $history_id
		)->parse() );

		// Hide hidden filters.
		if ( ( ( isset( $row->af_hidden ) && $row->af_hidden ) ||
				AbuseFilter::filterHidden( $filter ) )
			&& !$this->canViewPrivate() ) {
			return $this->msg( 'abusefilter-edit-denied' )->escaped();
		}

		$output = '';
		if ( $error ) {
			$out->addHTML( "<span class=\"error\">$error</span>" );
		}

		// Read-only attribute
		$readOnlyAttrib = [];
		$cbReadOnlyAttrib = []; // For checkboxes

		$styleAttrib = [ 'style' => 'width:95%' ];

		if ( !$this->canEditFilter( $row ) ) {
			$readOnlyAttrib['readonly'] = 'readonly';
			$cbReadOnlyAttrib['disabled'] = 'disabled';
		}

		$fields = [];

		$fields['abusefilter-edit-id'] =
			$this->mFilter == 'new' ?
				$this->msg( 'abusefilter-edit-new' )->escaped() :
				$lang->formatNum( $filter );
		$fields['abusefilter-edit-description'] =
			Xml::input(
				'wpFilterDescription',
				45,
				isset( $row->af_public_comments ) ? $row->af_public_comments : '',
				array_merge( $readOnlyAttrib, $styleAttrib )
			);

		global $wgAbuseFilterValidGroups;
		if ( count( $wgAbuseFilterValidGroups ) > 1 ) {
			$groupSelector = new XmlSelect(
				'wpFilterGroup',
				'mw-abusefilter-edit-group-input',
				'default'
			);

			if ( isset( $row->af_group ) && $row->af_group ) {
				$groupSelector->setDefault( $row->af_group );
			}

			foreach ( $wgAbuseFilterValidGroups as $group ) {
				$groupSelector->addOption( AbuseFilter::nameGroup( $group ), $group );
			}

			if ( !empty( $readOnlyAttrib ) ) {
				$groupSelector->setAttribute( 'disabled', 'disabled' );
			}

			$fields['abusefilter-edit-group'] = $groupSelector->getHTML();
		}

		// Hit count display
		if ( !empty( $row->af_hit_count ) && $user->isAllowed( 'abusefilter-log-detail' ) ) {
			$count_display = $this->msg( 'abusefilter-hitcount' )
				->numParams( (int)$row->af_hit_count )->text();
			$hitCount = $this->linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'AbuseLog' ),
				$count_display,
				[],
				[ 'wpSearchFilter' => $row->af_id ]
			);

			$fields['abusefilter-edit-hitcount'] = $hitCount;
		}

		if ( $filter !== 'new' ) {
			// Statistics
			global $wgAbuseFilterProfile;
			$stash = ObjectCache::getMainStashInstance();
			$matches_count = (int)$stash->get( AbuseFilter::filterMatchesKey( $filter ) );
			$total = (int)$stash->get( AbuseFilter::filterUsedKey( $row->af_group ) );

			if ( $total > 0 ) {
				$matches_percent = sprintf( '%.2f', 100 * $matches_count / $total );
				if ( $wgAbuseFilterProfile ) {
					list( $timeProfile, $condProfile ) = AbuseFilter::getFilterProfile( $filter );
					$fields['abusefilter-edit-status-label'] = $this->msg( 'abusefilter-edit-status-profile' )
						->numParams( $total, $matches_count, $matches_percent, $timeProfile, $condProfile )
						->escaped();
				} else {
					$fields['abusefilter-edit-status-label'] = $this->msg( 'abusefilter-edit-status' )
						->numParams( $total, $matches_count, $matches_percent )
						->parse();
				}
			}
		}

		$fields['abusefilter-edit-rules'] = AbuseFilter::buildEditBox(
			$row->af_pattern,
			'wpFilterRules',
			true,
			$this->canEditFilter( $row )
		);
		$fields['abusefilter-edit-notes'] = Xml::textarea(
			'wpFilterNotes',
			( isset( $row->af_comments ) ? $row->af_comments . "\n" : "\n" ),
			40, 15,
			$readOnlyAttrib
		);

		// Build checkboxes
		$checkboxes = [ 'hidden', 'enabled', 'deleted' ];
		$flags = '';

		global $wgAbuseFilterIsCentral;
		if ( $wgAbuseFilterIsCentral ) {
			$checkboxes[] = 'global';
		}

		if ( isset( $row->af_throttled ) && $row->af_throttled ) {
			global $wgAbuseFilterRestrictions;

			$filterActions = explode( ',', $row->af_actions );
			$throttledActions = array_intersect_key(
				array_flip( $filterActions ),
				array_filter( $wgAbuseFilterRestrictions )
			);

			if ( $throttledActions ) {
				$throttledActions = array_map(
					function ( $filterAction ) {
						return $this->msg( 'abusefilter-action-' . $filterAction )->text();
					},
					array_keys( $throttledActions )
				);

				$flags .= $out->parse(
					Html::warningBox(
						$this->msg( 'abusefilter-edit-throttled-warning' )
							->plaintextParams( $lang->commaList( $throttledActions ) )
							->escaped()
					)
				);
			}
		}

		foreach ( $checkboxes as $checkboxId ) {
			// Messages that can be used here:
			// * abusefilter-edit-enabled
			// * abusefilter-edit-deleted
			// * abusefilter-edit-hidden
			// * abusefilter-edit-global
			$message = "abusefilter-edit-$checkboxId";
			$dbField = "af_$checkboxId";
			$postVar = 'wpFilter' . ucfirst( $checkboxId );

			if ( $checkboxId == 'global' && !$this->canEditGlobal() ) {
				$cbReadOnlyAttrib['disabled'] = 'disabled';
			}

			$checkbox = Xml::checkLabel(
				$this->msg( $message )->text(),
				$postVar,
				$postVar,
				isset( $row->$dbField ) ? $row->$dbField : false,
				$cbReadOnlyAttrib
			);
			$checkbox = Xml::tags( 'p', null, $checkbox );
			$flags .= $checkbox;
		}

		$fields['abusefilter-edit-flags'] = $flags;
		$tools = '';

		if ( $filter != 'new' ) {
			if ( $user->isAllowed( 'abusefilter-revert' ) ) {
				$tools .= Xml::tags(
					'p', null,
					$this->linkRenderer->makeLink(
						$this->getTitle( "revert/$filter" ),
						new HtmlArmor( $this->msg( 'abusefilter-edit-revert' )->parse() )
					)
				);
			}

			if ( $this->canEdit() ) {
				// Test link
				$tools .= Xml::tags(
					'p', null,
					$this->linkRenderer->makeLink(
						$this->getTitle( "test/$filter" ),
						new HtmlArmor( $this->msg( 'abusefilter-edit-test-link' )->parse() )
					)
				);
			}
			// Last modification details
			$userLink =
				Linker::userLink( $row->af_user, $row->af_user_text ) .
				Linker::userToolLinks( $row->af_user, $row->af_user_text );
			$userName = $row->af_user_text;
			$fields['abusefilter-edit-lastmod'] =
				$this->msg( 'abusefilter-edit-lastmod-text' )
				->rawParams(
					$lang->timeanddate( $row->af_timestamp, true ),
					$userLink,
					$lang->date( $row->af_timestamp, true ),
					$lang->time( $row->af_timestamp, true ),
					$userName
				)->parse();
			$history_display = new HtmlArmor( $this->msg( 'abusefilter-edit-viewhistory' )->parse() );
			$fields['abusefilter-edit-history'] =
				$this->linkRenderer->makeKnownLink( $this->getTitle( 'history/' . $filter ), $history_display );
		}

		// Add export
		$exportText = FormatJson::encode( [ 'row' => $row, 'actions' => $actions ] );
		$tools .= Xml::tags( 'a', [ 'href' => '#', 'id' => 'mw-abusefilter-export-link' ],
			$this->msg( 'abusefilter-edit-export' )->parse() );
		$tools .= Xml::element( 'textarea',
			[ 'readonly' => 'readonly', 'id' => 'mw-abusefilter-export' ],
			$exportText
		);

		$fields['abusefilter-edit-tools'] = $tools;

		$form = Xml::buildForm( $fields );
		$form = Xml::fieldset( $this->msg( 'abusefilter-edit-main' )->text(), $form );
		$form .= Xml::fieldset(
			$this->msg( 'abusefilter-edit-consequences' )->text(),
			$this->buildConsequenceEditor( $row, $actions )
		);

		if ( $this->canEditFilter( $row ) ) {
			$form .= Xml::submitButton(
				$this->msg( 'abusefilter-edit-save' )->text(),
				[ 'accesskey' => 's' ]
			);
			$form .= Html::hidden(
				'wpEditToken',
				$user->getEditToken( [ 'abusefilter', $filter ] )
			);
		}

		$form = Xml::tags( 'form',
			[
				'action' => $this->getTitle( $filter )->getFullURL(),
				'method' => 'post'
			],
			$form
		);

		$output .= $form;

		return $output;
	}

	/**
	 * Builds the "actions" editor for a given filter.
	 * @param stdClass $row A row from the abuse_filter table.
	 * @param array $actions Array of rows from the abuse_filter_action table
	 *  corresponding to the abuse filter held in $row.
	 * @return HTML text for an action editor.
	 */
	function buildConsequenceEditor( $row, $actions ) {
		global $wgAbuseFilterActions;

		$enabledActions = array_filter( $wgAbuseFilterActions );

		$setActions = [];
		foreach ( $enabledActions as $action => $_ ) {
			$setActions[$action] = array_key_exists( $action, $actions );
		}

		$output = '';

		foreach ( $enabledActions as $action => $_ ) {
			MediaWiki\suppressWarnings();
			$params = $actions[$action]['parameters'];
			MediaWiki\restoreWarnings();
			$output .= $this->buildConsequenceSelector(
				$action, $setActions[$action], $params, $row );
		}

		return $output;
	}

	/**
	 * @param string $action The action to build an editor for
	 * @param bool $set Whether or not the action is activated
	 * @param array $parameters Action parameters
	 * @param stdClass $row abuse_filter row object
	 * @return string
	 */
	function buildConsequenceSelector( $action, $set, $parameters, $row ) {
		global $wgAbuseFilterActions, $wgMainCacheType;

		if ( empty( $wgAbuseFilterActions[$action] ) ) {
			return '';
		}

		$readOnlyAttrib = [];
		$cbReadOnlyAttrib = []; // For checkboxes

		if ( !$this->canEditFilter( $row ) ) {
			$readOnlyAttrib['readonly'] = 'readonly';
			$cbReadOnlyAttrib['disabled'] = 'disabled';
		}

		switch ( $action ) {
			case 'throttle':
				// Throttling is only available via object caching
				if ( $wgMainCacheType === CACHE_NONE ) {
					return '';
				}
				$throttleSettings = Xml::checkLabel(
					$this->msg( 'abusefilter-edit-action-throttle' )->text(),
					'wpFilterActionThrottle',
					"mw-abusefilter-action-checkbox-$action",
					$set,
					[ 'class' => 'mw-abusefilter-action-checkbox' ] + $cbReadOnlyAttrib );
				$throttleFields = [];

				if ( $set ) {
					array_shift( $parameters );
					$throttleRate = explode( ',', $parameters[0] );
					$throttleCount = $throttleRate[0];
					$throttlePeriod = $throttleRate[1];

					$throttleGroups = implode( "\n", array_slice( $parameters, 1 ) );
				} else {
					$throttleCount = 3;
					$throttlePeriod = 60;

					$throttleGroups = "user\n";
				}

				$throttleFields['abusefilter-edit-throttle-count'] =
					Xml::input( 'wpFilterThrottleCount', 20, $throttleCount, $readOnlyAttrib );
				$throttleFields['abusefilter-edit-throttle-period'] =
					$this->msg( 'abusefilter-edit-throttle-seconds' )
					->rawParams( Xml::input( 'wpFilterThrottlePeriod', 20, $throttlePeriod,
						$readOnlyAttrib )
					)->parse();
				$throttleFields['abusefilter-edit-throttle-groups'] =
					Xml::textarea( 'wpFilterThrottleGroups', $throttleGroups . "\n",
									40, 5, $readOnlyAttrib );
				$throttleSettings .=
					Xml::tags(
						'div',
						[ 'id' => 'mw-abusefilter-throttle-parameters' ],
						Xml::buildForm( $throttleFields )
					);
				return $throttleSettings;
			case 'warn':
				global $wgAbuseFilterDefaultWarningMessage;
				$output = '';
				$checkbox = Xml::checkLabel(
					$this->msg( 'abusefilter-edit-action-warn' )->text(),
					'wpFilterActionWarn',
					"mw-abusefilter-action-checkbox-$action",
					$set,
					[ 'class' => 'mw-abusefilter-action-checkbox' ] + $cbReadOnlyAttrib );
				$output .= Xml::tags( 'p', null, $checkbox );
				if ( $set ) {
					$warnMsg = $parameters[0];
				} elseif (
					$row &&
					isset( $row->af_group ) && $row->af_group &&
					isset( $wgAbuseFilterDefaultWarningMessage[$row->af_group] )
				) {
					$warnMsg = $wgAbuseFilterDefaultWarningMessage[$row->af_group];
				} else {
					$warnMsg = 'abusefilter-warning';
				}

				$warnFields['abusefilter-edit-warn-message'] =
					$this->getExistingSelector( $warnMsg, !empty( $readOnlyAttrib ) );
				$warnFields['abusefilter-edit-warn-other-label'] =
					Xml::input(
						'wpFilterWarnMessageOther',
						45,
						$warnMsg,
						[ 'id' => 'mw-abusefilter-warn-message-other' ] + $cbReadOnlyAttrib
					);

				$previewButton = Xml::element(
					'input',
					[
						'type' => 'button',
						'id' => 'mw-abusefilter-warn-preview-button',
						'value' => $this->msg( 'abusefilter-edit-warn-preview' )->text()
					]
				);
				$editButton = '';
				if ( $this->getUser()->isAllowed( 'editinterface' ) ) {
					$editButton .= ' ' . Xml::element(
						'input',
						[
							'type' => 'button',
							'id' => 'mw-abusefilter-warn-edit-button',
							'value' => $this->msg( 'abusefilter-edit-warn-edit' )->text()
						]
					);
				}
				$previewHolder = Xml::element(
					'div',
					[ 'id' => 'mw-abusefilter-warn-preview' ], ''
				);
				$warnFields['abusefilter-edit-warn-actions'] =
					Xml::tags( 'p', null, $previewButton . $editButton ) . "\n$previewHolder";
				$output .=
					Xml::tags(
						'div',
						[ 'id' => 'mw-abusefilter-warn-parameters' ],
						Xml::buildForm( $warnFields )
					);
				return $output;
			case 'tag':
				if ( $set ) {
					$tags = $parameters;
				} else {
					$tags = [];
				}
				$output = '';

				$checkbox = Xml::checkLabel(
					$this->msg( 'abusefilter-edit-action-tag' )->text(),
					'wpFilterActionTag',
					"mw-abusefilter-action-checkbox-$action",
					$set,
					[ 'class' => 'mw-abusefilter-action-checkbox' ] + $cbReadOnlyAttrib
				);
				$output .= Xml::tags( 'p', null, $checkbox );

				$tagFields['abusefilter-edit-tag-tag'] =
					Xml::textarea( 'wpFilterTags', implode( "\n", $tags ), 40, 5, $readOnlyAttrib );
				$output .=
					Xml::tags( 'div',
						[ 'id' => 'mw-abusefilter-tag-parameters' ],
						Xml::buildForm( $tagFields )
					);
				return $output;
			case 'block':
				global $wgBlockAllowsUTEdit, $wgAbuseFilterBlockDuration,
					$wgAbuseFilterAnonBlockDuration;

				if ( $set && count( $parameters ) === 3 ) {
					// Both blocktalk and custom block durations available
					$blockTalk = $parameters[0];
					$defaultAnonDuration = $parameters[1];
					$defaultUserDuration = $parameters[2];
				} else {
					if ( $set && count( $parameters ) === 1 ) {
						// Only blocktalk available
						$blockTalk = $parameters[0];
					}
					if ( $wgAbuseFilterAnonBlockDuration ) {
						$defaultAnonDuration = $wgAbuseFilterAnonBlockDuration;
					} else {
						$defaultAnonDuration = $wgAbuseFilterBlockDuration;
					}
					$defaultUserDuration = $wgAbuseFilterBlockDuration;
				}
				$suggestedBlocks = SpecialBlock::getSuggestedDurations();
				$suggestedBlocks = self::normalizeBlocks( $suggestedBlocks );

				$output = '';
				$checkbox = Xml::checkLabel(
					$this->msg( 'abusefilter-edit-action-block' )->text(),
					'wpFilterActionBlock',
					"mw-abusefilter-action-checkbox-block",
					$set,
					[ 'class' => 'mw-abusefilter-action-checkbox' ] + $cbReadOnlyAttrib );
				$output .= Xml::tags( 'p', null, $checkbox );
				if ( $wgBlockAllowsUTEdit === true ) {
					$talkCheckbox =
						Xml::checkLabel(
							$this->msg( 'abusefilter-edit-action-blocktalk' )->text(),
							'wpFilterBlockTalk',
							'mw-abusefilter-action-checkbox-blocktalk',
							isset( $blockTalk ) && $blockTalk == 'blocktalk',
							[ 'class' => 'mw-abusefilter-action-checkbox' ] + $cbReadOnlyAttrib
						);
				}

				$anonDuration = new XmlSelect(
					'wpBlockAnonDuration',
					false,
					'default'
				);
				$anonDuration->addOptions( $suggestedBlocks );

				$userDuration = new XmlSelect(
					'wpBlockUserDuration',
					false,
					'default'
				);
				$userDuration->addOptions( $suggestedBlocks );

				// Set defaults
				$anonDuration->setDefault( $defaultAnonDuration );
				$userDuration->setDefault( $defaultUserDuration );

				if ( !$this->canEditFilter( $row ) ) {
					$anonDuration->setAttribute( 'disabled', 'disabled' );
					$userDuration->setAttribute( 'disabled', 'disabled' );
				}

				if ( $wgBlockAllowsUTEdit === true ) {
					$durations['abusefilter-edit-block-options'] = $talkCheckbox;
				}
				$durations['abusefilter-edit-block-anon-durations'] = $anonDuration->getHTML();
				$durations['abusefilter-edit-block-user-durations'] = $userDuration->getHTML();

				$rawOutput = Xml::buildForm( $durations );

				$output .= Xml::tags(
						'div',
						[ 'id' => 'mw-abusefilter-block-parameters' ],
						$rawOutput
					);

				return $output;

			default:
				// Give grep a chance to find the usages:
				// abusefilter-edit-action-warn, abusefilter-edit-action-disallow
				// abusefilter-edit-action-blockautopromote
				// abusefilter-edit-action-degroup, abusefilter-edit-action-throttle
				// abusefilter-edit-action-rangeblock, abusefilter-edit-action-tag
				$message = 'abusefilter-edit-action-' . $action;
				$form_field = 'wpFilterAction' . ucfirst( $action );
				$status = $set;

				$thisAction = Xml::checkLabel(
					$this->msg( $message )->text(),
					$form_field,
					"mw-abusefilter-action-checkbox-$action",
					$status,
					[ 'class' => 'mw-abusefilter-action-checkbox' ] + $cbReadOnlyAttrib
				);
				$thisAction = Xml::tags( 'p', null, $thisAction );
				return $thisAction;
		}
	}

	/**
	 * @param string $warnMsg
	 * @param bool $readOnly
	 * @return string
	 */
	function getExistingSelector( $warnMsg, $readOnly = false ) {
		$existingSelector = new XmlSelect(
			'wpFilterWarnMessage',
			'mw-abusefilter-warn-message-existing',
			$warnMsg == 'abusefilter-warning' ? 'abusefilter-warning' : 'other'
		);

		$existingSelector->addOption( 'abusefilter-warning' );

		if ( $readOnly ) {
			$existingSelector->setAttribute( 'disabled', 'disabled' );
		} else {
			// Find other messages.
			$dbr = wfGetDB( DB_REPLICA );
			$res = $dbr->select(
				'page',
				[ 'page_title' ],
				[
					'page_namespace' => 8,
					'page_title LIKE ' . $dbr->addQuotes( 'Abusefilter-warning%' )
				],
				__METHOD__
			);

			$lang = $this->getLanguage();
			foreach ( $res as $row ) {
				if ( $lang->lcfirst( $row->page_title ) == $lang->lcfirst( $warnMsg ) ) {
					$existingSelector->setDefault( $lang->lcfirst( $warnMsg ) );
				}

				if ( $row->page_title != 'Abusefilter-warning' ) {
					$existingSelector->addOption( $lang->lcfirst( $row->page_title ) );
				}
			}
		}

		$existingSelector->addOption( $this->msg( 'abusefilter-edit-warn-other' )->text(), 'other' );

		return $existingSelector->getHTML();
	}

	/**
	 * @ToDo: Maybe we should also check if global values belong to $durations
	 * and determine the right point to add them if missing.
	 *
	 * @param array $durations
	 * @return array
	 */
	protected static function normalizeBlocks( $durations ) {
		global $wgAbuseFilterBlockDuration, $wgAbuseFilterAnonBlockDuration;
		// We need to have same values since it may happen that ipblocklist
		// and one (or both) of the global variables use different wording
		// for the same duration. In such case, when setting the default of
		// the dropdowns it would fail.
		foreach ( $durations as &$duration ) {
			$currentDuration = SpecialBlock::parseExpiryInput( $duration );
			$anonDuration = SpecialBlock::parseExpiryInput( $wgAbuseFilterAnonBlockDuration );
			$userDuration = SpecialBlock::parseExpiryInput( $wgAbuseFilterBlockDuration );

			if ( $duration !== $wgAbuseFilterBlockDuration &&
				$currentDuration === $userDuration ) {
				$duration = $wgAbuseFilterBlockDuration;

			} elseif ( $duration !== $wgAbuseFilterAnonBlockDuration &&
				$currentDuration === $anonDuration ) {
				$duration = $wgAbuseFilterAnonBlockDuration;
			}
		}

		return $durations;
	}

	/**
	 * Loads filter data from the database by ID.
	 * @param int $id The filter's ID number
	 * @return array|null Either an associative array representing the filter,
	 *  or NULL if the filter does not exist.
	 */
	function loadFilterData( $id ) {
		if ( $id == 'new' ) {
			$obj = new stdClass;
			$obj->af_pattern = '';
			$obj->af_enabled = 1;
			$obj->af_hidden = 0;
			$obj->af_global = 0;
			$obj->af_throttled = 0;
			return [ $obj, [] ];
		}

		// Load from master to avoid unintended reversions where there's replication lag.
		$dbr = $this->getRequest()->wasPosted()
			? wfGetDB( DB_MASTER )
			: wfGetDB( DB_REPLICA );

		// Load certain fields only. This prevents a condition seen on Wikimedia where
		// a schema change adding a new field caused that extra field to be selected.
		// Since the selected row may be inserted back into the database, this will cause
		// an SQL error if, say, one server has the updated schema but another does not.
		$loadFields = [
			'af_id',
			'af_pattern',
			'af_user',
			'af_user_text',
			'af_timestamp',
			'af_enabled',
			'af_comments',
			'af_public_comments',
			'af_hidden',
			'af_hit_count',
			'af_throttled',
			'af_deleted',
			'af_actions',
			'af_global',
			'af_group',
		];

		// Load the main row
		$row = $dbr->selectRow( 'abuse_filter', $loadFields, [ 'af_id' => $id ], __METHOD__ );

		if ( !isset( $row ) || !isset( $row->af_id ) || !$row->af_id ) {
			return null;
		}

		// Load the actions
		$actions = [];
		$res = $dbr->select( 'abuse_filter_action',
			'*',
			[ 'afa_filter' => $id ],
			__METHOD__
		);

		foreach ( $res as $actionRow ) {
			$thisAction = [];
			$thisAction['action'] = $actionRow->afa_consequence;
			$thisAction['parameters'] = array_filter( explode( "\n", $actionRow->afa_parameters ) );

			$actions[$actionRow->afa_consequence] = $thisAction;
		}

		return [ $row, $actions ];
	}

	/**
	 * Load filter data to show in the edit view.
	 * Either from the HTTP request or from the filter/history_id given.
	 * The HTTP request always takes precedence.
	 * Includes caching.
	 * @param int $filter The filter ID being requested.
	 * @param int $history_id If any, the history ID being requested.
	 * @return Array with filter data if available, otherwise null.
	 * The first element contains the abuse_filter database row,
	 *  the second element is an array of related abuse_filter_action rows.
	 */
	function loadRequest( $filter, $history_id = null ) {
		static $row = null;
		static $actions = null;
		$request = $this->getRequest();

		if ( !is_null( $actions ) && !is_null( $row ) ) {
			return [ $row, $actions ];
		} elseif ( $request->wasPosted() ) {
			# Nothing, we do it all later
		} elseif ( $history_id ) {
			return $this->loadHistoryItem( $history_id );
		} else {
			return $this->loadFilterData( $filter );
		}

		// We need some details like last editor
		list( $row, $origActions ) = $this->loadFilterData( $filter );

		$row->mOriginalRow = clone $row;
		$row->mOriginalActions = $origActions;

		// Check for importing
		$import = $request->getVal( 'wpImportText' );
		if ( $import ) {
			$data = FormatJson::decode( $import );

			$importRow = $data->row;
			$actions = wfObjectToArray( $data->actions );

			$copy = [
				'af_public_comments',
				'af_pattern',
				'af_comments',
				'af_deleted',
				'af_enabled',
				'af_hidden',
			];

			foreach ( $copy as $name ) {
				$row->$name = $importRow->$name;
			}
		} else {
			$textLoads = [
				'af_public_comments' => 'wpFilterDescription',
				'af_pattern' => 'wpFilterRules',
				'af_comments' => 'wpFilterNotes',
			];

			foreach ( $textLoads as $col => $field ) {
				$row->$col = trim( $request->getVal( $field ) );
			}

			$row->af_group = $request->getVal( 'wpFilterGroup', 'default' );

			$row->af_deleted = $request->getBool( 'wpFilterDeleted' );
			$row->af_enabled = $request->getBool( 'wpFilterEnabled' ) && !$row->af_deleted;
			$row->af_hidden = $request->getBool( 'wpFilterHidden' );
			global $wgAbuseFilterIsCentral;
			$row->af_global = $request->getBool( 'wpFilterGlobal' ) && $wgAbuseFilterIsCentral;

			// Actions
			global $wgAbuseFilterActions;
			$actions = [];
			foreach ( array_filter( $wgAbuseFilterActions ) as $action => $_ ) {
				// Check if it's set
				$enabled = $request->getBool( 'wpFilterAction' . ucfirst( $action ) );

				if ( $enabled ) {
					$parameters = [];

					if ( $action == 'throttle' ) {
						// We need to load the parameters
						$throttleCount = $request->getIntOrNull( 'wpFilterThrottleCount' );
						$throttlePeriod = $request->getIntOrNull( 'wpFilterThrottlePeriod' );
						$throttleGroups = explode( "\n",
							trim( $request->getText( 'wpFilterThrottleGroups' ) ) );

						$parameters[0] = $this->mFilter; // For now, anyway
						$parameters[1] = "$throttleCount,$throttlePeriod";
						$parameters = array_merge( $parameters, $throttleGroups );
					} elseif ( $action == 'warn' ) {
						$specMsg = $request->getVal( 'wpFilterWarnMessage' );

						if ( $specMsg == 'other' ) {
							$specMsg = $request->getVal( 'wpFilterWarnMessageOther' );
						}

						$parameters[0] = $specMsg;
					} elseif ( $action == 'block' ) {
						$parameters[0] = $request->getCheck( 'wpFilterBlockTalk' ) ?
							'blocktalk' : 'noTalkBlockSet';
						$parameters[1] = $request->getVal( 'wpBlockAnonDuration' );
						$parameters[2] = $request->getVal( 'wpBlockUserDuration' );
					} elseif ( $action == 'tag' ) {
						$parameters = explode( "\n", trim( $request->getText( 'wpFilterTags' ) ) );
					}

					$thisAction = [ 'action' => $action, 'parameters' => $parameters ];
					$actions[$action] = $thisAction;
				}
			}
		}

		$row->af_actions = implode( ',', array_keys( array_filter( $actions ) ) );

		return [ $row, $actions ];
	}

	/**
	 * Loads historical data in a form that the editor can understand.
	 * @param int $id History ID
	 * @return array|bool False if the history ID is not valid, otherwise array in the usual format:
	 * First element contains the abuse_filter row (as it was).
	 * Second element contains an array of abuse_filter_action rows.
	 */
	function loadHistoryItem( $id ) {
		$dbr = wfGetDB( DB_REPLICA );

		// Load the row.
		$row = $dbr->selectRow( 'abuse_filter_history',
			'*',
			[ 'afh_id' => $id ],
			__METHOD__
		);

		if ( !$row ) {
			return false;
		}

		return AbuseFilter::translateFromHistory( $row );
	}

	/**
	 * @return null
	 */
	protected function exposeWarningMessages() {
		global $wgOut, $wgAbuseFilterDefaultWarningMessage;
		$wgOut->addJsConfigVars(
			'wgAbuseFilterDefaultWarningMessage',
			$wgAbuseFilterDefaultWarningMessage
		);
	}
}
