<?php

class AbuseFilterViewEdit extends AbuseFilterView {
	/**
	 * @param SpecialPage $page
	 * @param array $params
	 */
	function __construct( $page, $params ) {
		parent::__construct( $page, $params );
		$this->mFilter = $page->mFilter;
		$this->mHistoryID = $page->mHistoryID;
	}

	function show() {
		$user = $this->getUser();
		$out = $this->getOutput();
		$request = $this->getRequest();
		$out->setPageTitle( $this->msg( 'abusefilter-edit' ) );

		$filter = $this->mFilter;
		$history_id = $this->mHistoryID;

		// Add default warning messages
		$this->exposeWarningMessages();

		if ( $filter == 'new' && !$user->isAllowed( 'abusefilter-modify' ) ) {
			$out->addWikiMsg( 'abusefilter-edit-notallowed' );
			return;
		}

		$editToken = $request->getVal( 'wpEditToken' );
		$didEdit = $this->canEdit()
			&& $user->matchEditToken( $editToken, array( 'abusefilter', $filter ) );

		if ( $didEdit ) {
			// Check syntax
			$syntaxerr = AbuseFilter::checkSyntax( $request->getVal( 'wpFilterRules' ) );
			if ( $syntaxerr !== true ) {
				$out->addHTML(
					$this->buildFilterEditor(
						$this->msg(
							'abusefilter-edit-badsyntax',
							array( $syntaxerr[0] )
						)->parseAsBlock(),
						$filter, $history_id
					)
				);
				return;
			}

			$dbw = wfGetDB( DB_MASTER );

			list( $newRow, $actions ) = $this->loadRequest( $filter );

			$differences = AbuseFilter::compareVersions(
				array( $newRow, $actions ),
				array( $newRow->mOriginalRow, $newRow->mOriginalActions )
			);

			// Don't allow adding a new global rule, or updating a
			// rule that is currently global, without permissions.
			if ( ( $newRow->af_global == 1 || $newRow->mOriginalRow->af_global == 1 )
				&& !$this->canEditGlobal()
			) {
				$out->addWikiMsg( 'abusefilter-edit-notallowed-global' );
				return;
			}

			$origActions = $newRow->mOriginalActions;
			unset( $newRow->mOriginalRow );
			unset( $newRow->mOriginalActions );

			// Check for non-changes
			if ( !count( $differences ) ) {
				$out->redirect( $this->getTitle()->getLocalURL() );
				return;
			}

			// Check for restricted actions
			global $wgAbuseFilterRestrictedActions;
			$allActions = array_keys( array_merge(
						array_filter( $actions ),
						array_filter( $origActions )
					) );

			if (
				count( array_intersect(
						$wgAbuseFilterRestrictedActions,
						$allActions
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
				$bad = false;
				foreach ( $actions['tag']['parameters'] as $tag ) {
					$t = Title::makeTitleSafe( NS_MEDIAWIKI, 'tag-' . $tag );
					if ( !$t ) {
						$bad = true;
					}

					if ( $bad ) {
						$out->addHTML(
							$this->buildFilterEditor(
								$this->msg( 'abusefilter-edit-bad-tags' )->parseAsBlock(),
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

			$dbw->begin( __METHOD__ );

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

			$dbw->replace( 'abuse_filter', array( 'af_id' ), $newRow, __METHOD__ );

			if ( $is_new ) {
				$new_id = $dbw->insertId();
			}

			// Actions
			global $wgAbuseFilterAvailableActions;
			$deadActions = array();
			$actionsRows = array();
			foreach ( $wgAbuseFilterAvailableActions as $action ) {
				// Check if it's set
				$enabled = isset( $actions[$action] ) && (bool)$actions[$action];

				if ( $enabled ) {
					$parameters = $actions[$action]['parameters'];

					$thisRow = array(
						'afa_filter' => $new_id,
						'afa_consequence' => $action,
						'afa_parameters' => implode( "\n", $parameters )
					);
					$actionsRows[] = $thisRow;
				} else {
					$deadActions[] = $action;
				}
			}

			// Create a history row
			$afh_row = array();

			foreach ( AbuseFilter::$history_mappings as $af_col => $afh_col ) {
				$afh_row[$afh_col] = $newRow[$af_col];
			}

			// Actions
			$displayActions = array();
			foreach ( $actions as $action ) {
				$displayActions[$action['action']] = $action['parameters'];
			}
			$afh_row['afh_actions'] = serialize( $displayActions );

			$afh_row['afh_changed_fields'] = implode( ',', $differences );

			// Flags
			$flags = array();
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
					array( 'afa_filter' => $filter ),
					__METHOD__
				);
			}
			$dbw->insert( 'abuse_filter_action', $actionsRows, __METHOD__ );

			$dbw->commit( __METHOD__ );

			// Reset Memcache if this was a global rule
			if ( $newRow['af_global'] ) {
				global $wgMemc;
				$group = 'default';
				if ( isset( $newRow['af_group'] ) && $newRow['af_group'] != '' ) {
					$group = $newRow['af_group'];
				}

				$memcacheRules = array();
				$res = $dbw->select(
					'abuse_filter',
					'*',
					array(
						'af_enabled' => 1,
						'af_deleted' => 0,
						'af_global' => 1,
						'af_group' => $group,
					),
					__METHOD__
				);
				foreach ( $res as $row ) {
					$memcacheRules[] = $row;
				}

				$wgMemc->set( AbuseFilter::getGlobalRulesKey( $group ), $memcacheRules );
			}

			// Logging

			$lp = new LogPage( 'abusefilter' );

			$lp->addEntry( 'modify', $this->getTitle( $new_id ), '', array( $history_id, $new_id ) );

			// Special-case stuff for tags -- purge the tag list cache.
			if ( isset( $actions['tag'] ) ) {
				global $wgMemc;
				$wgMemc->delete( wfMemcKey( 'valid-tags' ) );
			}

			AbuseFilter::resetFilterProfile( $new_id );

			$out->redirect(
				$this->getTitle()->getLocalURL( 'result=success&changedfilter=' . $new_id ) );
		} else {
			if ( $history_id ) {
				$out->addWikiMsg(
					'abusefilter-edit-oldwarning', $this->mHistoryID, $this->mFilter );
			}

			$out->addHTML( $this->buildFilterEditor( null, $this->mFilter, $history_id ) );

			if ( $history_id ) {
				$out->addWikiMsg(
					'abusefilter-edit-oldwarning', $this->mHistoryID, $this->mFilter );
			}
		}
	}

	/**
	 * Builds the full form for edit filters.
	 * Loads data either from the database or from the HTTP request.
	 * The request takes precedence over the database
	 * @param $error string An error message to show above the filter box.
	 * @param $filter int The filter ID
	 * @param $history_id int The history ID of the filter, if applicable. Otherwise null
	 * @return bool|string False if there is a failure building the editor, otherwise the HTML text for the editor.
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

		if ( !$row ) {
			$out->addWikiMsg( 'abusefilter-edit-badfilter' );
			$out->addHTML( Linker::link( $this->getTitle(), $this->msg( 'abusefilter-return' )->text() ) );
			return false;
		}

		$out->addSubtitle( $this->msg(
			$filter === 'new' ? 'abusefilter-edit-subtitle-new' : 'abusefilter-edit-subtitle',
			$this->getLanguage()->formatNum( $filter ), $history_id
		)->text() );

		// Hide hidden filters.
		if ( ( ( isset( $row->af_hidden ) && $row->af_hidden ) ||
				AbuseFilter::filterHidden( $filter ) )
			&& !$this->canViewPrivate() ) {
			return $this->msg( 'abusefilter-edit-denied' )->text();
		}

		$output = '';
		if ( $error ) {
			$out->addHTML( "<span class=\"error\">$error</span>" );
		}

		// Read-only attribute
		$readOnlyAttrib = array();
		$cbReadOnlyAttrib = array(); // For checkboxes

		if ( !$this->canEdit() || ( isset( $row->af_global ) && $row->af_global == 1 && !$this->canEditGlobal() ) ) {
			$readOnlyAttrib['readonly'] = 'readonly';
			$cbReadOnlyAttrib['disabled'] = 'disabled';
		}

		$fields = array();

		$fields['abusefilter-edit-id'] =
			$this->mFilter == 'new' ? $this->msg( 'abusefilter-edit-new' )->text() : $lang->formatNum( $filter );
		$fields['abusefilter-edit-description'] =
			Xml::input(
				'wpFilterDescription',
				45,
				isset( $row->af_public_comments ) ? $row->af_public_comments : '',
				$readOnlyAttrib
			);

		global $wgAbuseFilterValidGroups;
		if ( count($wgAbuseFilterValidGroups) > 1 ) {
			$groupSelector = new XmlSelect(
				'wpFilterGroup',
				'mw-abusefilter-edit-group-input',
				'default'
			);

			if ( isset( $row->af_group ) && $row->af_group ) {
				$groupSelector->setDefault($row->af_group);
			}

			foreach( $wgAbuseFilterValidGroups as $group ) {
				$groupSelector->addOption( AbuseFilter::nameGroup($group), $group );
			}

			$fields['abusefilter-edit-group'] = $groupSelector->getHTML();
		}

		// Hit count display
		if ( !empty( $row->af_hit_count ) ) {
			$count_display = $this->msg( 'abusefilter-hitcount' )
				->numParams( (int) $row->af_hit_count )->escaped();
			$hitCount = Linker::linkKnown(
				SpecialPage::getTitleFor( 'AbuseLog' ),
				$count_display,
				array(),
				array( 'wpSearchFilter' => $row->af_id )
			);

			$fields['abusefilter-edit-hitcount'] = $hitCount;
		}

		if ( $filter !== 'new' ) {
			// Statistics
			global $wgMemc;
			$matches_count = $wgMemc->get( AbuseFilter::filterMatchesKey( $filter ) );
			$total = $wgMemc->get( AbuseFilter::filterUsedKey( $row->af_group ) );

			if ( $total > 0 ) {
				$matches_percent = sprintf( '%.2f', 100 * $matches_count / $total );
				list( $timeProfile, $condProfile ) = AbuseFilter::getFilterProfile( $filter );

				$fields['abusefilter-edit-status-label'] = $this->msg( 'abusefilter-edit-status' )
					->numParams( $total, $matches_count, $matches_percent, $timeProfile, $condProfile )
					->escaped();
			}
		}

		$fields['abusefilter-edit-rules'] = AbuseFilter::buildEditBox(
			$row->af_pattern,
			'wpFilterRules',
			true,
			$this->canEdit()
		);
		$fields['abusefilter-edit-notes'] = Xml::textarea(
			'wpFilterNotes',
			( isset( $row->af_comments ) ? $row->af_comments . "\n" : "\n" ),
			40, 5,
			$readOnlyAttrib
		);

		// Build checkboxen
		$checkboxes = array( 'hidden', 'enabled', 'deleted' );
		$flags = '';

		global $wgAbuseFilterIsCentral;
		if ( $wgAbuseFilterIsCentral ) {
			$checkboxes[] = 'global';
		}

		if ( isset( $row->af_throttled ) && $row->af_throttled ) {
			global $wgAbuseFilterEmergencyDisableThreshold;

			// determine emergency disable value for this action
			$emergencyDisableThreshold = AbuseFilter::getEmergencyValue( $wgAbuseFilterEmergencyDisableThreshold, $row->af_group );

			$threshold_percent = sprintf( '%.2f', $emergencyDisableThreshold * 100 );
			$flags .= $out->parse(
				$this->msg( 'abusefilter-edit-throttled' )->numParams( $threshold_percent )->text()
			);
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

		if ( $filter != 'new' && $user->isAllowed( 'abusefilter-revert' ) ) {
			$tools .= Xml::tags(
				'p', null,
				Linker::link(
					$this->getTitle( 'revert/' . $filter ),
					$this->msg( 'abusefilter-edit-revert' )->text()
				)
			);
		}

		if ( $filter != 'new' ) {
			// Test link
			$tools .= Xml::tags(
				'p', null,
				Linker::link(
					$this->getTitle( "test/$filter" ),
					$this->msg( 'abusefilter-edit-test-link' )->parse()
				)
			);
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
			$history_display = $this->msg( 'abusefilter-edit-viewhistory' )->parse();
			$fields['abusefilter-edit-history'] =
				Linker::linkKnown( $this->getTitle( 'history/' . $filter ), $history_display );
		}

		// Add export
		$exportText = json_encode( array( 'row' => $row, 'actions' => $actions ) );
		$tools .= Xml::tags( 'a', array( 'href' => '#', 'id' => 'mw-abusefilter-export-link' ),
			$this->msg( 'abusefilter-edit-export' )->parse() );
		$tools .= Xml::element( 'textarea',
			array( 'readonly' => 'readonly', 'id' => 'mw-abusefilter-export' ),
			$exportText
		);

		$fields['abusefilter-edit-tools'] = $tools;

		$form = Xml::buildForm( $fields );
		$form = Xml::fieldset( $this->msg( 'abusefilter-edit-main' )->text(), $form );
		$form .= Xml::fieldset(
			$this->msg( 'abusefilter-edit-consequences' )->text(),
			$this->buildConsequenceEditor( $row, $actions )
		);

		if ( $this->canEdit() ) {
			$form .= Xml::submitButton(
				$this->msg( 'abusefilter-edit-save' )->text(),
				array( 'accesskey' => 's' )
			);
			$form .= Html::hidden(
				'wpEditToken',
				$user->getEditToken( array( 'abusefilter', $filter ) )
			);
		}

		$form = Xml::tags( 'form',
			array(
				'action' => $this->getTitle( $filter )->getFullURL(),
				'method' => 'post'
			),
			$form
		);

		$output .= $form;

		return $output;
	}

	/**
	 * Builds the "actions" editor for a given filter.
	 * @param $row stdClass A row from the abuse_filter table.
	 * @param $actions Array of rows from the abuse_filter_action table
	 *  corresponding to the abuse filter held in $row.
	 * @return HTML text for an action editor.
	 */
	function buildConsequenceEditor( $row, $actions ) {
		global $wgAbuseFilterAvailableActions;

		$setActions = array();
		foreach ( $wgAbuseFilterAvailableActions as $action ) {
			$setActions[$action] = array_key_exists( $action, $actions );
		}

		$output = '';

		foreach ( $wgAbuseFilterAvailableActions as $action ) {
			$output .= $this->buildConsequenceSelector(
				$action, $setActions[$action], @$actions[$action]['parameters'], $row );
		}

		return $output;
	}

	/**
	 * @param $action string The action to build an editor for
	 * @param $set bool Whether or not the action is activated
	 * @param $parameters array Action parameters
	 * @param $row stdClass abuse_filter row object
	 * @return string
	 */
	function buildConsequenceSelector( $action, $set, $parameters, $row ) {
		global $wgAbuseFilterAvailableActions;

		if ( !in_array( $action, $wgAbuseFilterAvailableActions ) ) {
			return '';
		}

		$readOnlyAttrib = array();
		$cbReadOnlyAttrib = array(); // For checkboxes

		if ( !$this->canEdit() ) {
			$readOnlyAttrib['readonly'] = 'readonly';
			$cbReadOnlyAttrib['disabled'] = 'disabled';
		}

		switch( $action ) {
			case 'throttle':
				$throttleSettings = Xml::checkLabel(
					$this->msg( 'abusefilter-edit-action-throttle' )->text(),
					'wpFilterActionThrottle',
					"mw-abusefilter-action-checkbox-$action",
					$set,
					array(  'class' => 'mw-abusefilter-action-checkbox' ) + $cbReadOnlyAttrib );
				$throttleFields = array();

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
						array( 'id' => 'mw-abusefilter-throttle-parameters' ),
						Xml::buildForm( $throttleFields )
					);
				return $throttleSettings;
			case 'flag':
				$checkbox = Xml::checkLabel(
					$this->msg( 'abusefilter-edit-action-flag' )->text(),
					'wpFilterActionFlag',
					"mw-abusefilter-action-checkbox-$action",
					true,
					array( 'disabled' => '1', 'class' => 'mw-abusefilter-action-checkbox' ) );
				return Xml::tags( 'p', null, $checkbox );
			case 'warn':
				global $wgAbuseFilterDefaultWarningMessage;
				$output = '';
				$checkbox = Xml::checkLabel(
					$this->msg( 'abusefilter-edit-action-warn' )->text(),
					'wpFilterActionWarn',
					"mw-abusefilter-action-checkbox-$action",
					$set,
					array( 'class' => 'mw-abusefilter-action-checkbox' ) + $cbReadOnlyAttrib );
				$output .= Xml::tags( 'p', null, $checkbox );
				if ( $set ) {
					$warnMsg = $parameters[0];
				} elseif (
					$row &&
					isset( $row->af_group ) && $row->af_group &&
					isset($wgAbuseFilterDefaultWarningMessage[$row->af_group] )
				) {
					$warnMsg = $wgAbuseFilterDefaultWarningMessage[$row->af_group];
				} else {
					$warnMsg = 'abusefilter-warning';
				}

				$warnFields['abusefilter-edit-warn-message'] =
					$this->getExistingSelector( $warnMsg );
				$warnFields['abusefilter-edit-warn-other-label'] =
					Xml::input(
						'wpFilterWarnMessageOther',
						45,
						$warnMsg ? $warnMsg : 'abusefilter-warning-',
						array( 'id' => 'mw-abusefilter-warn-message-other' ) + $cbReadOnlyAttrib
					);

				$previewButton = Xml::element(
					'input',
					array(
						'type' => 'button',
						'id' => 'mw-abusefilter-warn-preview-button',
						'value' => $this->msg( 'abusefilter-edit-warn-preview' )->text()
					)
				);
				$editButton = Xml::element(
					'input',
					array(
						'type' => 'button',
						'id' => 'mw-abusefilter-warn-edit-button',
						'value' => $this->msg( 'abusefilter-edit-warn-edit' )->text()
					)
				);
				$previewHolder = Xml::element(
					'div',
					array( 'id' => 'mw-abusefilter-warn-preview' ), ''
				);
				$warnFields['abusefilter-edit-warn-actions'] =
					Xml::tags( 'p', null, "$previewButton $editButton" ) . "\n$previewHolder";
				$output .=
					Xml::tags(
						'div',
						array( 'id' => 'mw-abusefilter-warn-parameters' ),
						Xml::buildForm( $warnFields )
					);
				return $output;
			case 'tag':
				if ( $set ) {
					$tags = $parameters;
				} else {
					$tags = array();
				}
				$output = '';

				$checkbox = Xml::checkLabel(
					$this->msg( 'abusefilter-edit-action-tag' )->text(),
					'wpFilterActionTag',
					"mw-abusefilter-action-checkbox-$action",
					$set,
					array( 'class' => 'mw-abusefilter-action-checkbox' ) + $cbReadOnlyAttrib
				);
				$output .= Xml::tags( 'p', null, $checkbox );

				$tagFields['abusefilter-edit-tag-tag'] =
					Xml::textarea( 'wpFilterTags', implode( "\n", $tags ), 40, 5, $readOnlyAttrib );
				$output .=
					Xml::tags( 'div',
						array( 'id' => 'mw-abusefilter-tag-parameters' ),
						Xml::buildForm( $tagFields )
					);
				return $output;
			default:
				// Give grep a chance to find the usages:
				// abusefilter-edit-action-warn, abusefilter-edit-action-disallow
				// abusefilter-edit-action-flag, abusefilter-edit-action-blockautopromote
				// abusefilter-edit-action-degroup, abusefilter-edit-action-block
				// abusefilter-edit-action-throttle, abusefilter-edit-action-rangeblock
				// abusefilter-edit-action-tag
				$message = 'abusefilter-edit-action-' . $action;
				$form_field = 'wpFilterAction' . ucfirst( $action );
				$status = $set;

				$thisAction = Xml::checkLabel(
					$this->msg( $message )->text(),
					$form_field,
					"mw-abusefilter-action-checkbox-$action",
					$status,
					array( 'class' => 'mw-abusefilter-action-checkbox' ) + $cbReadOnlyAttrib
				);
				$thisAction = Xml::tags( 'p', null, $thisAction );
				return $thisAction;
		}
	}

	/**
	 * @param $warnMsg
	 * @return string
	 */
	function getExistingSelector( $warnMsg ) {
		$existingSelector = new XmlSelect(
			'wpFilterWarnMessage',
			'mw-abusefilter-warn-message-existing',
			$warnMsg == 'abusefilter-warning' ? 'abusefilter-warning' : 'other'
		);

		// Find other messages.
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			'page',
			array( 'page_title' ),
			array(
				'page_namespace' => 8,
				'page_title LIKE ' . $dbr->addQuotes( 'Abusefilter-warning%' )
			),
			__METHOD__
		);

		$existingSelector->addOption( 'abusefilter-warning' );

		$lang = $this->getLanguage();
		foreach( $res as $row ) {
			if ( $lang->lcfirst( $row->page_title ) == $lang->lcfirst( $warnMsg ) ) {
				$existingSelector->setDefault( $lang->lcfirst( $warnMsg ) );
			}

			if ( $row->page_title != 'Abusefilter-warning' ) {
				$existingSelector->addOption( $lang->lcfirst( $row->page_title ) );
			}
		}

		$existingSelector->addOption( $this->msg( 'abusefilter-edit-warn-other' )->text(), 'other' );

		return $existingSelector->getHTML();
	}

	/**
	 * Loads filter data from the database by ID.
	 * @param $id int The filter's ID number
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
			return array( $obj, array() );
		}

		// Load from master to avoid unintended reversions where there's replication lag.
		$dbr = wfGetDB( DB_MASTER );

		// Load certain fields only. This prevents a condition seen on Wikimedia where
		// a schema change adding a new field caused that extra field to be selected.
		// Since the selected row may be inserted back into the database, this will cause
		// an SQL error if, say, one server has the updated schema but another does not.
		$loadFields = array(
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
		);

		// Load the main row
		$row = $dbr->selectRow( 'abuse_filter', $loadFields, array( 'af_id' => $id ), __METHOD__ );

		if ( !isset( $row ) || !isset( $row->af_id ) || !$row->af_id ) {
			return null;
		}

		// Load the actions
		$actions = array();
		$res = $dbr->select( 'abuse_filter_action',
			'*',
			array( 'afa_filter' => $id ),
			__METHOD__
		);
		foreach( $res as $actionRow ) {
			$thisAction = array();
			$thisAction['action'] = $actionRow->afa_consequence;
			$thisAction['parameters'] = explode( "\n", $actionRow->afa_parameters );

			$actions[$actionRow->afa_consequence] = $thisAction;
		}

		return array( $row, $actions );
	}

	/**
	 * Load filter data to show in the edit view.
	 * Either from the HTTP request or from the filter/history_id given.
	 * The HTTP request always takes precedence.
	 * Includes caching.
	 * @param $filter int The filter ID being requested.
	 * @param $history_id int If any, the history ID being requested.
	 * @return Array with filter data if available, otherwise null.
	 * The first element contains the abuse_filter database row,
	 *  the second element is an array of related abuse_filter_action rows.
	 */
	function loadRequest( $filter, $history_id = null ) {
		static $row = null;
		static $actions = null;
		$request = $this->getRequest();

		if ( !is_null( $actions ) && !is_null( $row ) ) {
			return array( $row, $actions );
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
			$data = json_decode( $import );

			$importRow = $data->row;
			$actions = wfObjectToArray( $data->actions );

			$copy = array(
				'af_public_comments',
				'af_pattern',
				'af_comments',
				'af_deleted',
				'af_enabled',
				'af_hidden',
			);

			foreach ( $copy as $name ) {
				$row->$name = $importRow->$name;
			}
		} else {
			$textLoads = array(
				'af_public_comments' => 'wpFilterDescription',
				'af_pattern' => 'wpFilterRules',
				'af_comments' => 'wpFilterNotes',
			);

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
			global $wgAbuseFilterAvailableActions;
			$actions = array();
			foreach ( $wgAbuseFilterAvailableActions as $action ) {
				// Check if it's set
				$enabled = $request->getBool( 'wpFilterAction' . ucfirst( $action ) );

				if ( $enabled ) {
					$parameters = array();

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

						if ( $specMsg == 'other' )
							$specMsg = $request->getVal( 'wpFilterWarnMessageOther' );

						$parameters[0] = $specMsg;
					} elseif ( $action == 'tag' ) {
						$parameters = explode( "\n", $request->getText( 'wpFilterTags' ) );
					}

					$thisAction = array( 'action' => $action, 'parameters' => $parameters );
					$actions[$action] = $thisAction;
				}
			}
		}

		$row->af_actions = implode( ',', array_keys( array_filter( $actions ) ) );

		return array( $row, $actions );
	}

	/**
	 * Loads historical data in a form that the editor can understand.
	 * @param $id int History ID
	 * @return array In the usual format:
	 * First element contains the abuse_filter row (as it was).
	 * Second element contains an array of abuse_filter_action rows.
	 */
	function loadHistoryItem( $id ) {
		$dbr = wfGetDB( DB_SLAVE );

		// Load the row.
		$row = $dbr->selectRow( 'abuse_filter_history',
			'*',
			array( 'afh_id' => $id ),
			__METHOD__
		);

		return AbuseFilter::translateFromHistory( $row );
	}

	protected function exposeWarningMessages() {
		global $wgOut, $wgAbuseFilterDefaultWarningMessage;
		$wgOut->addJsConfigVars( 'wgAbuseFilterDefaultWarningMessage', $wgAbuseFilterDefaultWarningMessage );
	}
}
