<?php

class AbuseFilterViewEdit extends AbuseFilterView {
	public static $mLoadedRow = null, $mLoadedActions = null;
	/**
	 * @param SpecialAbuseFilter $page
	 * @param array $params
	 */
	public function __construct( $page, $params ) {
		parent::__construct( $page, $params );
		$this->mFilter = $page->mFilter;
		$this->mHistoryID = $page->mHistoryID;
	}

	/**
	 * Shows the page
	 */
	public function show() {
		$user = $this->getUser();
		$out = $this->getOutput();
		$request = $this->getRequest();
		$config = $this->getConfig();
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

		// Add the default warning and disallow messages in a JS variable
		$this->exposeMessages();

		if ( $filter == 'new' && !$this->canEdit() ) {
			$out->addHTML(
				Xml::tags(
					'p',
					null,
					Html::errorBox( $this->msg( 'abusefilter-edit-notallowed' )->parse() )
				)
			);
			return;
		}

		$editToken = $request->getVal( 'wpEditToken' );
		$tokenMatches = $user->matchEditToken(
			$editToken, [ 'abusefilter', $filter ], $request );

		if ( $tokenMatches && $this->canEdit() ) {
			list( $newRow, $actions ) = $this->loadRequest( $filter );
			$status = AbuseFilter::saveFilter( $this, $filter, $request, $newRow, $actions );
			if ( !$status->isGood() ) {
				$err = $status->getErrors();
				$msg = $err[0]['message'];
				$params = $err[0]['params'];
				if ( $status->isOK() ) {
					$out->addHTML(
						$this->buildFilterEditor(
							$this->msg( $msg, $params )->parseAsBlock(),
							$filter,
							$history_id
						)
					);
				} else {
					$out->addWikiMsg( $msg );
				}
			} else {
				if ( $status->getValue() === false ) {
					// No change
					$out->redirect( $this->getTitle()->getLocalURL() );
				} else {
					list( $new_id, $history_id ) = $status->getValue();
					$out->redirect(
						$this->getTitle()->getLocalURL(
							[
								'result' => 'success',
								'changedfilter' => $new_id,
								'changeid' => $history_id,
							]
						)
					);
				}
			}
		} else {
			if ( $tokenMatches ) {
				// Lost rights meanwhile
				$out->addHTML(
					Xml::tags(
						'p',
						null,
						Html::errorBox( $this->msg( 'abusefilter-edit-notallowed' )->parse() )
					)
				);
			} elseif ( $request->wasPosted() ) {
				// Warn the user to re-attempt save
				$out->addHTML(
					Html::warningBox( $this->msg( 'abusefilter-edit-token-not-match' )->escaped() )
				);
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
	 * @param string|null $error An error message to show above the filter box.
	 * @param int $filter The filter ID
	 * @param int|null $history_id The history ID of the filter, if applicable. Otherwise null
	 * @return bool|string False if there is a failure building the editor,
	 *   otherwise the HTML text for the editor.
	 */
	public function buildFilterEditor( $error, $filter, $history_id = null ) {
		if ( $filter === null ) {
			return false;
		}

		// Build the edit form
		$out = $this->getOutput();
		$out->enableOOUI();
		$out->addJsConfigVars( 'isFilterEditor', true );
		$lang = $this->getLanguage();
		$user = $this->getUser();

		// Load from request OR database.
		list( $row, $actions ) = $this->loadRequest( $filter, $history_id );

		if ( !$row ) {
			$out->addHTML(
				Xml::tags(
					'p',
					null,
					Html::errorBox( $this->msg( 'abusefilter-edit-badfilter' )->parse() )
				)
			);
			$href = $this->getTitle()->getFullURL();
			$btn = new OOUI\ButtonWidget( [
					'label' => $this->msg( 'abusefilter-return' )->text(),
					'href' => $href
			] );
			$out->addHTML( $btn );
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
			$output .= Html::errorBox( $error );
		}

		// Read-only attribute
		$readOnlyAttrib = [];

		if ( !$this->canEditFilter( $row ) ) {
			$readOnlyAttrib['disabled'] = 'disabled';
		}

		$fields = [];

		$fields['abusefilter-edit-id'] =
			$this->mFilter == 'new' ?
				$this->msg( 'abusefilter-edit-new' )->escaped() :
				$lang->formatNum( $filter );
		$fields['abusefilter-edit-description'] =
			new OOUI\TextInputWidget( [
				'name' => 'wpFilterDescription',
				'value' => $row->af_public_comments ?? ''
				] + $readOnlyAttrib
			);

		$validGroups = $this->getConfig()->get( 'AbuseFilterValidGroups' );
		if ( count( $validGroups ) > 1 ) {
			$groupSelector =
				new OOUI\DropdownInputWidget( [
					'name' => 'wpFilterGroup',
					'id' => 'mw-abusefilter-edit-group-input',
					'value' => 'default',
					'disabled' => !empty( $readOnlyAttrib )
				] );

			$options = [];
			if ( isset( $row->af_group ) && $row->af_group ) {
				$groupSelector->setValue( $row->af_group );
			}

			foreach ( $validGroups as $group ) {
				$options += [ AbuseFilter::nameGroup( $group ) => $group ];
			}

			$options = Xml::listDropDownOptionsOoui( $options );
			$groupSelector->setOptions( $options );

			$fields['abusefilter-edit-group'] = $groupSelector;
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
			$stash = ObjectCache::getMainStashInstance();
			$matches_count = (int)$stash->get( AbuseFilter::filterMatchesKey( $filter ) );
			$total = (int)$stash->get( AbuseFilter::filterUsedKey( $row->af_group ) );

			if ( $total > 0 ) {
				$matches_percent = sprintf( '%.2f', 100 * $matches_count / $total );
				if ( $this->getConfig()->get( 'AbuseFilterProfile' ) ) {
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

		$fields['abusefilter-edit-rules'] = $this->buildEditBox(
			$row->af_pattern,
			'wpFilterRules',
			true
		);
		$fields['abusefilter-edit-notes'] =
			new OOUI\MultilineTextInputWidget( [
				'name' => 'wpFilterNotes',
				'value' => isset( $row->af_comments ) ? $row->af_comments . "\n" : "\n",
				'rows' => 15
				] + $readOnlyAttrib
			);

		// Build checkboxes
		$checkboxes = [ 'hidden', 'enabled', 'deleted' ];
		$flags = '';

		if ( $this->getConfig()->get( 'AbuseFilterIsCentral' ) ) {
			$checkboxes[] = 'global';
		}

		if ( isset( $row->af_throttled ) && $row->af_throttled ) {
			$filterActions = explode( ',', $row->af_actions );
			$throttledActions = array_intersect_key(
				array_flip( $filterActions ),
				array_filter( $this->getConfig()->get( 'AbuseFilterRestrictions' ) )
			);

			if ( $throttledActions ) {
				$throttledActions = array_map(
					function ( $filterAction ) {
						return $this->msg( 'abusefilter-action-' . $filterAction )->text();
					},
					array_keys( $throttledActions )
				);

				$flags .= Html::warningBox(
					$this->msg( 'abusefilter-edit-throttled-warning' )
					->plaintextParams( $lang->commaList( $throttledActions ) )
					->parseAsBlock()
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

			$checkboxAttribs = [
				'name' => $postVar,
				'id' => $postVar,
				'selected' => $row->$dbField ?? false,
			] + $readOnlyAttrib;
			$labelAttribs = [
				'label' => $this->msg( $message )->text(),
				'align' => 'inline',
			];

			if ( $checkboxId == 'global' && !$this->canEditGlobal() ) {
				$checkboxAttribs['disabled'] = 'disabled';
			}

			// Set readonly on deleted if the filter isn't disabled
			if ( $checkboxId == 'deleted' && $row->af_enabled == 1 ) {
				$checkboxAttribs['disabled'] = 'disabled';
			}

			// Add infusable where needed
			if ( $checkboxId == 'deleted' || $checkboxId == 'enabled' ) {
				$checkboxAttribs['infusable'] = true;
				if ( $checkboxId == 'deleted' ) {
					$labelAttribs['id'] = $postVar . 'Label';
					$labelAttribs['infusable'] = true;
				}
			}

			$checkbox =
				new OOUI\FieldLayout(
					new OOUI\CheckboxInputWidget( $checkboxAttribs ),
					$labelAttribs
				);
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
			$fields['abusefilter-edit-lastmod'] =
				$this->msg( 'abusefilter-edit-lastmod-text' )
				->rawParams(
					$this->getLinkToLatestDiff(
						$filter,
						$lang->timeanddate( $row->af_timestamp, true )
					),
					$userLink,
					$this->getLinkToLatestDiff(
						$filter,
						$lang->date( $row->af_timestamp, true )
					),
					$this->getLinkToLatestDiff(
						$filter,
						$lang->time( $row->af_timestamp, true )
					)
				)->params(
					wfEscapeWikiText( $row->af_user_text )
				)->parse();
			$history_display = new HtmlArmor( $this->msg( 'abusefilter-edit-viewhistory' )->parse() );
			$fields['abusefilter-edit-history'] =
				$this->linkRenderer->makeKnownLink( $this->getTitle( 'history/' . $filter ), $history_display );
		}

		// Add export
		$exportText = FormatJson::encode( [ 'row' => $row, 'actions' => $actions ] );
		$tools .= Xml::tags( 'a', [ 'href' => '#', 'id' => 'mw-abusefilter-export-link' ],
			$this->msg( 'abusefilter-edit-export' )->parse() );
		$tools .=
			new OOUI\MultilineTextInputWidget( [
				'id' => 'mw-abusefilter-export',
				'readOnly' => true,
				'value' => $exportText,
				'rows' => 10
			] );

		$fields['abusefilter-edit-tools'] = $tools;

		$form = Xml::buildForm( $fields );
		$form = Xml::fieldset( $this->msg( 'abusefilter-edit-main' )->text(), $form );
		$form .= Xml::fieldset(
			$this->msg( 'abusefilter-edit-consequences' )->text(),
			$this->buildConsequenceEditor( $row, $actions )
		);

		if ( $this->canEditFilter( $row ) ) {
			$form .=
				new OOUI\ButtonInputWidget( [
					'type' => 'submit',
					'label' => $this->msg( 'abusefilter-edit-save' )->text(),
					'useInputTag' => true,
					'accesskey' => 's',
					'flags' => [ 'progressive', 'primary' ]
				] );
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
	 * @return string HTML text for an action editor.
	 */
	public function buildConsequenceEditor( $row, $actions ) {
		$enabledActions = array_filter(
			$this->getConfig()->get( 'AbuseFilterActions' )
		);

		$setActions = [];
		foreach ( $enabledActions as $action => $_ ) {
			$setActions[$action] = array_key_exists( $action, $actions );
		}

		$output = '';

		foreach ( $enabledActions as $action => $_ ) {
			Wikimedia\suppressWarnings();
			$params = $actions[$action]['parameters'];
			Wikimedia\restoreWarnings();
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
	 * @return string|\OOUI\FieldLayout
	 */
	public function buildConsequenceSelector( $action, $set, $parameters, $row ) {
		$config = $this->getConfig();
		$actions = $config->get( 'AbuseFilterActions' );
		if ( empty( $actions[$action] ) ) {
			return '';
		}

		$readOnlyAttrib = [];

		if ( !$this->canEditFilter( $row ) ) {
			$readOnlyAttrib['disabled'] = 'disabled';
		}

		switch ( $action ) {
			case 'throttle':
				// Throttling is only available via object caching
				if ( $config->get( 'MainCacheType' ) === CACHE_NONE ) {
					return '';
				}
				$throttleSettings =
					new OOUI\FieldLayout(
						new OOUI\CheckboxInputWidget( [
							'name' => 'wpFilterActionThrottle',
							'id' => 'mw-abusefilter-action-checkbox-throttle',
							'selected' => $set,
							'classes' => [ 'mw-abusefilter-action-checkbox' ]
						] + $readOnlyAttrib
						),
						[
							'label' => $this->msg( 'abusefilter-edit-action-throttle' )->text(),
							'align' => 'inline'
						]
					);
				$throttleFields = [];

				if ( $set ) {
					array_shift( $parameters );
					$throttleRate = explode( ',', $parameters[0] );
					$throttleCount = $throttleRate[0];
					$throttlePeriod = $throttleRate[1];

					$throttleGroups = array_slice( $parameters, 1 );
				} else {
					$throttleCount = 3;
					$throttlePeriod = 60;

					$throttleGroups = [ 'user' ];
				}

				$throttleFields['abusefilter-edit-throttle-count'] =
					new OOUI\FieldLayout(
						new OOUI\TextInputWidget( [
							'type' => 'number',
							'name' => 'wpFilterThrottleCount',
							'value' => $throttleCount
							] + $readOnlyAttrib
						),
						[
							'label' => $this->msg( 'abusefilter-edit-throttle-count' )->text()
						]
					);
				$throttleFields['abusefilter-edit-throttle-period'] =
					new OOUI\FieldLayout(
						new OOUI\TextInputWidget( [
							'type' => 'number',
							'name' => 'wpFilterThrottlePeriod',
							'value' => $throttlePeriod
							] + $readOnlyAttrib
						),
						[
							'label' => $this->msg( 'abusefilter-edit-throttle-period' )->text()
						]
					);

				$throttleConfig = [
					'values' => $throttleGroups,
					'label' => $this->msg( 'abusefilter-edit-throttle-groups' )->parse(),
					'disabled' => $readOnlyAttrib
				];
				$this->getOutput()->addJsConfigVars( 'throttleConfig', $throttleConfig );

				$hiddenGroups =
					new OOUI\FieldLayout(
						new OOUI\MultilineTextInputWidget( [
							'name' => 'wpFilterThrottleGroups',
							'value' => implode( "\n", $throttleGroups ),
							'rows' => 5,
							'placeholder' => $this->msg( 'abusefilter-edit-throttle-hidden-placeholder' )->text(),
							'infusable' => true,
							'id' => 'mw-abusefilter-hidden-throttle-field'
						] + $readOnlyAttrib
						),
						[
							'label' => new OOUI\HtmlSnippet(
								$this->msg( 'abusefilter-edit-throttle-groups' )->parse()
							),
							'align' => 'top',
							'id' => 'mw-abusefilter-hidden-throttle'
						]
					);

				$throttleFields['abusefilter-edit-throttle-groups'] = $hiddenGroups;

				$throttleSettings .=
					Xml::tags(
						'div',
						[ 'id' => 'mw-abusefilter-throttle-parameters' ],
						new OOUI\FieldsetLayout( [ 'items' => $throttleFields ] )
					);
				return $throttleSettings;
			case 'disallow':
			case 'warn':
				$output = '';
				$formName = $action === 'warn' ? 'wpFilterActionWarn' : 'wpFilterActionDisallow';
				$checkbox =
					new OOUI\FieldLayout(
						new OOUI\CheckboxInputWidget( [
							'name' => $formName,
							// mw-abusefilter-action-checkbox-warn, mw-abusefilter-action-checkbox-disallow
							'id' => "mw-abusefilter-action-checkbox-$action",
							'selected' => $set,
							'classes' => [ 'mw-abusefilter-action-checkbox' ]
						] + $readOnlyAttrib
						),
						[
							// abusefilter-edit-action-warn, abusefilter-edit-action-disallow
							'label' => $this->msg( "abusefilter-edit-action-$action" )->text(),
							'align' => 'inline'
						]
					);
				$output .= $checkbox;
				$defaultWarnMsg = $config->get( 'AbuseFilterDefaultWarningMessage' );
				$defaultDisallowMsg = $config->get( 'AbuseFilterDefaultDisallowMessage' );

				if ( $set && isset( $parameters[0] ) ) {
					$msg = $parameters[0];
				} elseif (
					$row &&
					isset( $row->af_group ) && $row->af_group && (
						( $action === 'warn' && isset( $defaultWarnMsg[$row->af_group] ) ) ||
						( $action === 'disallow' && isset( $defaultDisallowMsg[$row->af_group] ) )
					)
				) {
					$msg = $action === 'warn' ? $defaultWarnMsg[$row->af_group] :
						$defaultDisallowMsg[$row->af_group];
				} else {
					$msg = $action === 'warn' ? 'abusefilter-warning' : 'abusefilter-disallowed';
				}

				$fields["abusefilter-edit-$action-message"] =
					$this->getExistingSelector( $msg, !empty( $readOnlyAttrib ), $action );
				$otherFieldName = $action === 'warn' ? 'wpFilterWarnMessageOther'
					: 'wpFilterDisallowMessageOther';

				$fields["abusefilter-edit-$action-other-label"] =
					new OOUI\FieldLayout(
						new OOUI\TextInputWidget( [
							'name' => $otherFieldName,
							'value' => $msg,
							// mw-abusefilter-warn-message-other, mw-abusefilter-disallow-message-other
							'id' => "mw-abusefilter-$action-message-other",
							'infusable' => true
							] + $readOnlyAttrib
						),
						[
							'label' => new OOUI\HtmlSnippet(
								// abusefilter-edit-warn-other-label, abusefilter-edit-disallow-other-label
								$this->msg( "abusefilter-edit-$action-other-label" )->parse()
							)
						]
					);

				$previewButton =
					new OOUI\ButtonInputWidget( [
						// abusefilter-edit-warn-preview, abusefilter-edit-disallow-preview
						'label' => $this->msg( "abusefilter-edit-$action-preview" )->text(),
						// mw-abusefilter-warn-preview-button, mw-abusefilter-disallow-preview-button
						'id' => "mw-abusefilter-$action-preview-button",
						'infusable' => true,
						'flags' => 'progressive'
						]
					);

				$buttonGroup = $previewButton;
				if ( $this->getUser()->isAllowed( 'editinterface' ) ) {
					$editButton =
						new OOUI\ButtonInputWidget( [
							// abusefilter-edit-warn-edit, abusefilter-edit-disallow-edit
							'label' => $this->msg( "abusefilter-edit-$action-edit" )->text(),
							// mw-abusefilter-warn-edit-button, mw-abusefilter-disallow-edit-button
							'id' => "mw-abusefilter-$action-edit-button"
							]
						);
					$buttonGroup =
						new OOUI\Widget( [
							'content' =>
								new OOUI\HorizontalLayout( [
									'items' => [ $previewButton, $editButton ],
									'classes' => [
										'mw-abusefilter-preview-buttons',
										'mw-abusefilter-javascript-tools'
									]
								] )
						] );
				}
				$previewHolder = Xml::tags(
					'div',
					[
						// mw-abusefilter-warn-preview, mw-abusefilter-disallow-preview
						'id' => "mw-abusefilter-$action-preview",
						'style' => 'display:none'
					],
					''
				);
				$fields["abusefilter-edit-$action-actions"] = $buttonGroup;
				$output .=
					Xml::tags(
						'div',
						// mw-abusefilter-warn-parameters, mw-abusefilter-disallow-parameters
						[ 'id' => "mw-abusefilter-$action-parameters" ],
						new OOUI\FieldsetLayout( [ 'items' => $fields ] )
					) . $previewHolder;

				return $output;
			case 'tag':
				if ( $set ) {
					$tags = $parameters;
				} else {
					$tags = [];
				}
				$output = '';

				$checkbox =
					new OOUI\FieldLayout(
						new OOUI\CheckboxInputWidget( [
							'name' => 'wpFilterActionTag',
							'id' => 'mw-abusefilter-action-checkbox-tag',
							'selected' => $set,
							'classes' => [ 'mw-abusefilter-action-checkbox' ]
						] + $readOnlyAttrib
						),
						[
							'label' => $this->msg( 'abusefilter-edit-action-tag' )->text(),
							'align' => 'inline'
						]
					);
				$output .= $checkbox;

				$tagConfig = [
					'values' => $tags,
					'label' => $this->msg( 'abusefilter-edit-tag-tag' )->parse(),
					'disabled' => $readOnlyAttrib
				];
				$this->getOutput()->addJsConfigVars( 'tagConfig', $tagConfig );

				$hiddenTags =
					new OOUI\FieldLayout(
						new OOUI\MultilineTextInputWidget( [
							'name' => 'wpFilterTags',
							'value' => implode( ',', $tags ),
							'rows' => 5,
							'placeholder' => $this->msg( 'abusefilter-edit-tag-hidden-placeholder' )->text(),
							'infusable' => true,
							'id' => 'mw-abusefilter-hidden-tag-field'
						] + $readOnlyAttrib
						),
						[
							'label' => new OOUI\HtmlSnippet(
								$this->msg( 'abusefilter-edit-tag-tag' )->parse()
							),
							'align' => 'top',
							'id' => 'mw-abusefilter-hidden-tag'
						]
					);
				$output .=
					Xml::tags( 'div',
						[ 'id' => 'mw-abusefilter-tag-parameters' ],
						$hiddenTags
					);
				return $output;
			case 'block':
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
					if ( $config->get( 'AbuseFilterAnonBlockDuration' ) ) {
						$defaultAnonDuration = $config->get( 'AbuseFilterAnonBlockDuration' );
					} else {
						$defaultAnonDuration = $config->get( 'AbuseFilterBlockDuration' );
					}
					$defaultUserDuration = $config->get( 'AbuseFilterBlockDuration' );
				}
				$suggestedBlocks = SpecialBlock::getSuggestedDurations( null, false );
				$suggestedBlocks = self::normalizeBlocks( $suggestedBlocks );

				$output = '';
				$checkbox =
					new OOUI\FieldLayout(
						new OOUI\CheckboxInputWidget( [
							'name' => 'wpFilterActionBlock',
							'id' => 'mw-abusefilter-action-checkbox-block',
							'selected' => $set,
							'classes' => [ 'mw-abusefilter-action-checkbox' ]
						] + $readOnlyAttrib
						),
						[
							'label' => $this->msg( 'abusefilter-edit-action-block' )->text(),
							'align' => 'inline'
						]
					);
				$output .= $checkbox;

				$suggestedBlocks = Xml::listDropDownOptionsOoui( $suggestedBlocks );

				$anonDuration =
					new OOUI\DropdownInputWidget( [
						'name' => 'wpBlockAnonDuration',
						'options' => $suggestedBlocks,
						'value' => $defaultAnonDuration,
						'disabled' => !$this->canEditFilter( $row )
					] );

				$userDuration =
					new OOUI\DropdownInputWidget( [
						'name' => 'wpBlockUserDuration',
						'options' => $suggestedBlocks,
						'value' => $defaultUserDuration,
						'disabled' => !$this->canEditFilter( $row )
					] );

				$blockOptions = [];
				if ( $config->get( 'BlockAllowsUTEdit' ) === true ) {
					$talkCheckbox =
						new OOUI\FieldLayout(
							new OOUI\CheckboxInputWidget( [
								'name' => 'wpFilterBlockTalk',
								'id' => 'mw-abusefilter-action-checkbox-blocktalk',
								'selected' => isset( $blockTalk ) && $blockTalk == 'blocktalk',
								'classes' => [ 'mw-abusefilter-action-checkbox' ]
							] + $readOnlyAttrib
							),
							[
								'label' => $this->msg( 'abusefilter-edit-action-blocktalk' )->text(),
								'align' => 'left'
							]
						);

					$blockOptions['abusefilter-edit-block-options'] = $talkCheckbox;
				}
				$blockOptions['abusefilter-edit-block-anon-durations'] =
					new OOUI\FieldLayout(
						$anonDuration,
						[
							'label' => $this->msg( 'abusefilter-edit-block-anon-durations' )->text()
						]
					);
				$blockOptions['abusefilter-edit-block-user-durations'] =
					new OOUI\FieldLayout(
						$userDuration,
						[
							'label' => $this->msg( 'abusefilter-edit-block-user-durations' )->text()
						]
					);

				$output .= Xml::tags(
						'div',
						[ 'id' => 'mw-abusefilter-block-parameters' ],
						new OOUI\FieldsetLayout( [ 'items' => $blockOptions ] )
					);

				return $output;

			default:
				// Give grep a chance to find the usages:
				// abusefilter-edit-action-disallow,
				// abusefilter-edit-action-blockautopromote,
				// abusefilter-edit-action-degroup,
				// abusefilter-edit-action-rangeblock,
				$message = 'abusefilter-edit-action-' . $action;
				$form_field = 'wpFilterAction' . ucfirst( $action );
				$status = $set;

				$thisAction =
					new OOUI\FieldLayout(
						new OOUI\CheckboxInputWidget( [
							'name' => $form_field,
							'id' => "mw-abusefilter-action-checkbox-$action",
							'selected' => $status,
							'classes' => [ 'mw-abusefilter-action-checkbox' ]
						] + $readOnlyAttrib
						),
						[
							'label' => $this->msg( $message )->text(),
							'align' => 'inline'
						]
					);
				$thisAction = $thisAction;
				return $thisAction;
		}
	}

	/**
	 * @param string $warnMsg
	 * @param bool $readOnly
	 * @param string $action
	 * @return \OOUI\FieldLayout
	 */
	public function getExistingSelector( $warnMsg, $readOnly = false, $action = 'warn' ) {
		if ( $action === 'warn' ) {
			$action = 'warning';
			$formId = 'warn';
			$inputName = 'wpFilterWarnMessage';
		} elseif ( $action === 'disallow' ) {
			$action = 'disallowed';
			$formId = 'disallow';
			$inputName = 'wpFilterDisallowMessage';
		} else {
			throw new MWException( "Unexpected action value $action" );
		}

		$existingSelector =
			new OOUI\DropdownInputWidget( [
				'name' => $inputName,
				// mw-abusefilter-warn-message-existing, mw-abusefilter-disallow-message-existing
				'id' => "mw-abusefilter-$formId-message-existing",
				// abusefilter-warning, abusefilter-disallowed
				'value' => $warnMsg == "abusefilter-$action" ? "abusefilter-$action" : 'other',
				'infusable' => true
			] );

		// abusefilter-warning, abusefilter-disallowed
		$options = [ "abusefilter-$action" => "abusefilter-$action" ];

		if ( $readOnly ) {
			$existingSelector->setDisabled( true );
		} else {
			// Find other messages.
			$dbr = wfGetDB( DB_REPLICA );
			$pageTitlePrefix = "Abusefilter-$action";
			$res = $dbr->select(
				'page',
				[ 'page_title' ],
				[
					'page_namespace' => 8,
					'page_title LIKE ' . $dbr->addQuotes( $pageTitlePrefix . '%' )
				],
				__METHOD__
			);

			$lang = $this->getLanguage();
			foreach ( $res as $row ) {
				if ( $lang->lcfirst( $row->page_title ) == $lang->lcfirst( $warnMsg ) ) {
					$existingSelector->setValue( $lang->lcfirst( $warnMsg ) );
				}

				if ( $row->page_title != "Abusefilter-$action" ) {
					$options += [ $lang->lcfirst( $row->page_title ) => $lang->lcfirst( $row->page_title ) ];
				}
			}
		}

		// abusefilter-edit-warn-other, abusefilter-edit-disallow-other
		$options += [ $this->msg( "abusefilter-edit-$formId-other" )->text() => 'other' ];

		$options = Xml::listDropDownOptionsOoui( $options );
		$existingSelector->setOptions( $options );

		$existingSelector =
			new OOUI\FieldLayout(
				$existingSelector,
				[
					// abusefilter-edit-warn-message, abusefilter-edit-disallow-message
					'label' => $this->msg( "abusefilter-edit-$formId-message" )->text()
				]
			);

		return $existingSelector;
	}

	/**
	 * @todo Maybe we should also check if global values belong to $durations
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
		$anonDuration = self::getAbsoluteBlockDuration( $wgAbuseFilterAnonBlockDuration );
		$userDuration = self::getAbsoluteBlockDuration( $wgAbuseFilterBlockDuration );
		foreach ( $durations as &$duration ) {
			$currentDuration = self::getAbsoluteBlockDuration( $duration );

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
	 * Converts a string duration to an absolute timestamp, i.e. unrelated to the current
	 * time, taking into account infinity durations as well. The second parameter of
	 * strtotime is set to 0 in order to convert the duration in seconds (instead of
	 * a timestamp), thus making it unaffected by the execution time of the code.
	 *
	 * @param string $duration
	 * @return string|int
	 */
	protected static function getAbsoluteBlockDuration( $duration ) {
		if ( wfIsInfinity( $duration ) ) {
			return 'infinity';
		}
		return strtotime( $duration, 0 );
	}

	/**
	 * Loads filter data from the database by ID.
	 * @param int $id The filter's ID number
	 * @return array|null Either an associative array representing the filter,
	 *  or NULL if the filter does not exist.
	 */
	public function loadFilterData( $id ) {
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
		$res = $dbr->select(
			'abuse_filter_action',
			[ 'afa_consequence', 'afa_parameters' ],
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
	 * @param int|null $history_id If any, the history ID being requested.
	 * @return array|null Array with filter data if available, otherwise null.
	 * The first element contains the abuse_filter database row,
	 *  the second element is an array of related abuse_filter_action rows.
	 */
	public function loadRequest( $filter, $history_id = null ) {
		$row = self::$mLoadedRow;
		$actions = self::$mLoadedActions;
		$request = $this->getRequest();

		if ( !is_null( $actions ) && !is_null( $row ) ) {
			return [ $row, $actions ];
		} elseif ( $request->wasPosted() ) {
			// Nothing, we do it all later
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

			$row->af_deleted = $request->getCheck( 'wpFilterDeleted' );
			$row->af_enabled = $request->getCheck( 'wpFilterEnabled' );
			$row->af_hidden = $request->getCheck( 'wpFilterHidden' );
			$row->af_global = $request->getCheck( 'wpFilterGlobal' )
				&& $this->getConfig()->get( 'AbuseFilterIsCentral' );

			// Actions
			$actions = [];
			foreach ( array_filter( $this->getConfig()->get( 'AbuseFilterActions' ) ) as $action => $_ ) {
				// Check if it's set
				$enabled = $request->getCheck( 'wpFilterAction' . ucfirst( $action ) );

				if ( $enabled ) {
					$parameters = [];

					if ( $action == 'throttle' ) {
						// We need to load the parameters
						$throttleCount = $request->getIntOrNull( 'wpFilterThrottleCount' );
						$throttlePeriod = $request->getIntOrNull( 'wpFilterThrottlePeriod' );
						// First explode with \n, which is the delimiter used in the textarea
						$rawGroups = explode( "\n", $request->getText( 'wpFilterThrottleGroups' ) );
						// Trim any space, both as an actual group and inside subgroups
						$throttleGroups = [];
						foreach ( $rawGroups as $group ) {
							if ( strpos( $group, ',' ) !== false ) {
								$subGroups = explode( ',', $group );
								$throttleGroups[] = implode( ',', array_map( 'trim', $subGroups ) );
							} elseif ( trim( $group ) !== '' ) {
								$throttleGroups[] = trim( $group );
							}
						}

						$parameters[0] = $this->mFilter;
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
					} elseif ( $action == 'disallow' ) {
						$specMsg = $request->getVal( 'wpFilterDisallowMessage' );

						if ( $specMsg == 'other' ) {
							$specMsg = $request->getVal( 'wpFilterDisallowMessageOther' );
						}

						$parameters[0] = $specMsg;
					} elseif ( $action == 'tag' ) {
						$parameters = explode( ',', trim( $request->getText( 'wpFilterTags' ) ) );
					}

					$thisAction = [ 'action' => $action, 'parameters' => $parameters ];
					$actions[$action] = $thisAction;
				}
			}
		}

		$row->af_actions = implode( ',', array_keys( array_filter( $actions ) ) );

		self::$mLoadedRow = $row;
		self::$mLoadedActions = $actions;
		return [ $row, $actions ];
	}

	/**
	 * Loads historical data in a form that the editor can understand.
	 * @param int $id History ID
	 * @return array|bool False if the history ID is not valid, otherwise array in the usual format:
	 * First element contains the abuse_filter row (as it was).
	 * Second element contains an array of abuse_filter_action rows.
	 */
	public function loadHistoryItem( $id ) {
		$dbr = wfGetDB( DB_REPLICA );

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
	 * Exports the default warning and disallow messages to a JS variable
	 */
	protected function exposeMessages() {
		$this->getOutput()->addJsConfigVars(
			'wgAbuseFilterDefaultWarningMessage',
			$this->getConfig()->get( 'AbuseFilterDefaultWarningMessage' )
		);
		$this->getOutput()->addJsConfigVars(
			'wgAbuseFilterDefaultDisallowMessage',
			$this->getConfig()->get( 'AbuseFilterDefaultDisallowMessage' )
		);
	}
}
