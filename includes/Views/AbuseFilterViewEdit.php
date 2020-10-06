<?php

use MediaWiki\MediaWikiServices;

class AbuseFilterViewEdit extends AbuseFilterView {

	/**
	 * @var int|null The history ID of the current filter
	 */
	private $historyID;

	/**
	 * @param SpecialAbuseFilter $page
	 * @param array $params
	 */
	public function __construct( SpecialAbuseFilter $page, array $params ) {
		parent::__construct( $page, $params );
		$this->mFilter = $this->mParams['filter'];
		$this->historyID = $this->mParams['history'] ?? null;
	}

	/**
	 * Shows the page
	 */
	public function show() {
		$user = $this->getUser();
		$out = $this->getOutput();
		$request = $this->getRequest();
		$out->setPageTitle( $this->msg( 'abusefilter-edit' ) );
		$out->addHelpLink( 'Extension:AbuseFilter/Rules format' );

		$filter = $this->mFilter;
		if ( !is_numeric( $filter ) && $filter !== 'new' ) {
			$out->addHTML(
				Xml::tags(
					'p',
					null,
					Html::errorBox( $this->msg( 'abusefilter-edit-badfilter' )->parse() )
				)
			);
			return;
		}
		$history_id = $this->historyID;
		if ( $this->historyID ) {
			$dbr = wfGetDB( DB_REPLICA );
			$lastID = (int)$dbr->selectField(
				'abuse_filter_history',
				'afh_id',
				[
					'afh_filter' => $filter,
				],
				__METHOD__,
				[ 'ORDER BY' => 'afh_id DESC' ]
			);
			// change $history_id to null if it's current version id
			if ( $lastID === $this->historyID ) {
				$history_id = null;
			}
		}

		// Add the default warning and disallow messages in a JS variable
		$this->exposeMessages();

		$canEdit = AbuseFilter::canEdit( $user );

		if ( $filter === 'new' && !$canEdit ) {
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
		$isImport = $request->getRawVal( 'wpImportText' ) !== null;

		if ( $request->wasPosted() && $canEdit && $tokenMatches ) {
			$this->saveCurrentFilter( $filter, $history_id );
			return;
		}

		if ( $request->wasPosted() && !$isImport && !$tokenMatches ) {
			// Special case for when the token has expired with the page open, warn to retry
			$out->addHTML(
				Html::warningBox( $this->msg( 'abusefilter-edit-token-not-match' )->escaped() )
			);
		}

		if ( $isImport || ( $request->wasPosted() && !$tokenMatches ) ) {
			// Make sure to load from HTTP if the token doesn't match!
			$status = $this->loadRequest( $filter );
			if ( !$status->isGood() ) {
				$out->addHTML(
					Xml::tags(
						'p',
						null,
						Html::errorBox( $status->getMessage()->parse() )
					)
				);
				return;
			}
			$data = $status->getValue();
		} else {
			$data = $this->loadFromDatabase( $filter, $history_id );
		}

		list( $row, $actions ) = $data ?? [ null, [] ];

		// Either the user is just viewing the filter, they cannot edit it, they lost the
		// abusefilter-modify right with the page open, the token is invalid, or they're viewing
		// the result of importing a filter
		$this->buildFilterEditor( null, $row, $actions, $filter, $history_id );
	}

	/**
	 * @param int|string $filter The filter ID or 'new'.
	 * @param int|null $history_id The history ID of the filter, if applicable. Otherwise null
	 */
	private function saveCurrentFilter( $filter, $history_id ) : void {
		$out = $this->getOutput();
		$reqStatus = $this->loadRequest( $filter );
		if ( !$reqStatus->isGood() ) {
			// In the current implementation, this cannot happen.
			throw new LogicException( 'Should always be able to retrieve data for saving' );
		}
		list( $newRow, $actions ) = $reqStatus->getValue();
		$dbw = wfGetDB( DB_MASTER );
		$status = AbuseFilter::saveFilter( $this, $filter, $newRow, $actions, $dbw );

		if ( !$status->isGood() ) {
			$err = $status->getErrors();
			$msg = $err[0]['message'];
			$params = $err[0]['params'];
			if ( $status->isOK() ) {
				// Fixable error, show the editing interface
				$this->buildFilterEditor(
					$this->msg( $msg, $params )->parseAsBlock(),
					$newRow,
					$actions,
					$filter,
					$history_id
				);
			} else {
				// Permission-related error
				$out->addWikiMsg( $msg );
			}
		} elseif ( $status->getValue() === false ) {
			// No change
			$out->redirect( $this->getTitle()->getLocalURL() );
		} else {
			// Everything went fine!
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

	/**
	 * Builds the full form for edit filters, adding it to the OutputPage. $row and $actions can be
	 * passed in (for instance if there was a failure during save) to avoid searching the DB.
	 *
	 * @param string|null $error An error message to show above the filter box.
	 * @param stdClass|null $row abuse_filter row representing this filter, null if it doesn't exist
	 * @param array $actions Actions enabled and their parameters
	 * @param int|string $filter The filter ID or 'new'.
	 * @param int|null $history_id The history ID of the filter, if applicable. Otherwise null
	 */
	protected function buildFilterEditor(
		$error,
		?stdClass $row,
		array $actions,
		$filter,
		$history_id
	) {
		if ( $filter === null ) {
			return;
		}

		// Build the edit form
		$out = $this->getOutput();
		$out->enableOOUI();
		$out->addJsConfigVars( 'isFilterEditor', true );
		$lang = $this->getLanguage();
		$user = $this->getUser();

		if (
			$row === null ||
			// @fixme Temporary stopgap for T237887
			( $history_id && $row->af_id !== $filter )
		) {
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
			return;
		}

		$out->addSubtitle( $this->msg(
			$filter === 'new' ? 'abusefilter-edit-subtitle-new' : 'abusefilter-edit-subtitle',
			$filter === 'new' ? $filter : $this->getLanguage()->formatNum( $filter ),
			$history_id
		)->parse() );

		// Hide hidden filters.
		if (
			( $row->af_hidden || ( $filter !== 'new' && AbuseFilter::filterHidden( $filter ) ) ) &&
			!AbuseFilter::canViewPrivate( $user )
		) {
			$out->addHTML( $this->msg( 'abusefilter-edit-denied' )->escaped() );
			return;
		}

		if ( $history_id ) {
			$oldWarningMessage = AbuseFilter::canEditFilter( $user, $row )
				? 'abusefilter-edit-oldwarning'
				: 'abusefilter-edit-oldwarning-view';
			$out->addWikiMsg(
				$oldWarningMessage,
				$history_id,
				$filter
			);
		}

		if ( $error ) {
			$out->addHTML( Html::errorBox( $error ) );
		}

		$readOnly = !AbuseFilter::canEditFilter( $user, $row );

		$fields = [];

		$fields['abusefilter-edit-id'] =
			$this->mFilter === 'new' ?
				$this->msg( 'abusefilter-edit-new' )->escaped() :
				$lang->formatNum( $filter );
		$fields['abusefilter-edit-description'] =
			new OOUI\TextInputWidget( [
				'name' => 'wpFilterDescription',
				'value' => $row->af_public_comments ?? '',
				'readOnly' => $readOnly
				]
			);

		$validGroups = $this->getConfig()->get( 'AbuseFilterValidGroups' );
		if ( count( $validGroups ) > 1 ) {
			$groupSelector =
				new OOUI\DropdownInputWidget( [
					'name' => 'wpFilterGroup',
					'id' => 'mw-abusefilter-edit-group-input',
					'value' => 'default',
					'disabled' => $readOnly
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
		if ( !empty( $row->af_hit_count ) && SpecialAbuseLog::canSeeDetails( $user ) ) {
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

		if ( $filter !== 'new' && $row->af_enabled ) {
			// Statistics
			list( $totalCount, $matchesCount, $avgTime, $avgCond ) =
				AbuseFilter::getFilterProfile( $filter );

			if ( $totalCount > 0 ) {
				$matchesPercent = round( 100 * $matchesCount / $totalCount, 2 );
				$fields['abusefilter-edit-status-label'] = $this->msg( 'abusefilter-edit-status' )
					->numParams( $totalCount, $matchesCount, $matchesPercent, $avgTime, $avgCond )
					->parse();
			}
		}

		$fields['abusefilter-edit-rules'] = $this->buildEditBox(
			$row->af_pattern,
			true
		);
		$fields['abusefilter-edit-notes'] =
			new OOUI\MultilineTextInputWidget( [
				'name' => 'wpFilterNotes',
				'value' => isset( $row->af_comments ) ? $row->af_comments . "\n" : "\n",
				'rows' => 15,
				'readOnly' => $readOnly,
				'id' => 'mw-abusefilter-notes-editor'
			] );

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
				'disabled' => $readOnly
			];
			$labelAttribs = [
				'label' => $this->msg( $message )->text(),
				'align' => 'inline',
			];

			if ( $checkboxId === 'global' && !AbuseFilter::canEditGlobal( $user ) ) {
				$checkboxAttribs['disabled'] = 'disabled';
			}

			// Set readonly on deleted if the filter isn't disabled
			if ( $checkboxId === 'deleted' && $row->af_enabled == 1 ) {
				$checkboxAttribs['disabled'] = 'disabled';
			}

			// Add infusable where needed
			if ( $checkboxId === 'deleted' || $checkboxId === 'enabled' ) {
				$checkboxAttribs['infusable'] = true;
				if ( $checkboxId === 'deleted' ) {
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

		if ( $filter !== 'new' ) {
			$tools = '';
			if ( MediaWikiServices::getInstance()->getPermissionManager()
				->userHasRight( $user, 'abusefilter-revert' )
			) {
				$tools .= Xml::tags(
					'p', null,
					$this->linkRenderer->makeLink(
						$this->getTitle( "revert/$filter" ),
						new HtmlArmor( $this->msg( 'abusefilter-edit-revert' )->parse() )
					)
				);
			}

			if ( AbuseFilter::canViewPrivate( $user ) ) {
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
		}

		$form = Xml::buildForm( $fields );
		$form = Xml::fieldset( $this->msg( 'abusefilter-edit-main' )->text(), $form );
		$form .= Xml::fieldset(
			$this->msg( 'abusefilter-edit-consequences' )->text(),
			$this->buildConsequenceEditor( $row, $actions )
		);

		if ( AbuseFilter::canEditFilter( $user, $row ) ) {
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
				'method' => 'post',
				'id' => 'mw-abusefilter-editing-form'
			],
			$form
		);

		$out->addHTML( $form );

		if ( $history_id ) {
			// @phan-suppress-next-line PhanPossiblyUndeclaredVariable
			$out->addWikiMsg( $oldWarningMessage, $history_id, $filter );
		}
	}

	/**
	 * Builds the "actions" editor for a given filter.
	 * @param stdClass $row A row from the abuse_filter table.
	 * @param array[] $actions Array of rows from the abuse_filter_action table
	 *  corresponding to the abuse filter held in $row.
	 * @return string HTML text for an action editor.
	 */
	private function buildConsequenceEditor( $row, array $actions ) {
		$enabledActions = array_filter(
			$this->getConfig()->get( 'AbuseFilterActions' )
		);

		$setActions = [];
		foreach ( $enabledActions as $action => $_ ) {
			$setActions[$action] = array_key_exists( $action, $actions );
		}

		$output = '';

		foreach ( $enabledActions as $action => $_ ) {
			$params = $actions[$action] ?? null;
			$output .= $this->buildConsequenceSelector(
				$action, $setActions[$action], $row, $params );
		}

		return $output;
	}

	/**
	 * @param string $action The action to build an editor for
	 * @param bool $set Whether or not the action is activated
	 * @param stdClass $row abuse_filter row object
	 * @param string[]|null $parameters Action parameters. Null iff $set is false.
	 * @return string|\OOUI\FieldLayout
	 */
	private function buildConsequenceSelector( $action, $set, $row, ?array $parameters ) {
		$config = $this->getConfig();
		$user = $this->getUser();
		$actions = $config->get( 'AbuseFilterActions' );
		if ( empty( $actions[$action] ) ) {
			return '';
		}

		$readOnly = !AbuseFilter::canEditFilter( $user, $row );

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
							'classes' => [ 'mw-abusefilter-action-checkbox' ],
							'disabled' => $readOnly
						]
						),
						[
							'label' => $this->msg( 'abusefilter-edit-action-throttle' )->text(),
							'align' => 'inline'
						]
					);
				$throttleFields = [];

				if ( $set ) {
					// @phan-suppress-next-line PhanTypeArraySuspiciousNullable $parameters is array here
					list( $throttleCount, $throttlePeriod ) = explode( ',', $parameters[1], 2 );

					$throttleGroups = array_slice( $parameters, 2 );
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
							'value' => $throttleCount,
							'readOnly' => $readOnly
							]
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
							'value' => $throttlePeriod,
							'readOnly' => $readOnly
							]
						),
						[
							'label' => $this->msg( 'abusefilter-edit-throttle-period' )->text()
						]
					);

				$groupsHelpLink = Html::element(
					'a',
					[
						'href' => 'https://www.mediawiki.org/wiki/Special:MyLanguage/' .
							'Extension:AbuseFilter/Actions#Throttling',
						'target' => '_blank'
					],
					$this->msg( 'abusefilter-edit-throttle-groups-help-text' )->text()
				);
				$groupsHelp = $this->msg( 'abusefilter-edit-throttle-groups-help' )
						->rawParams( $groupsHelpLink )->escaped();
				$hiddenGroups =
					new OOUI\FieldLayout(
						new OOUI\MultilineTextInputWidget( [
							'name' => 'wpFilterThrottleGroups',
							'value' => implode( "\n", $throttleGroups ),
							'rows' => 5,
							'placeholder' => $this->msg( 'abusefilter-edit-throttle-hidden-placeholder' )->text(),
							'infusable' => true,
							'id' => 'mw-abusefilter-hidden-throttle-field',
							'readOnly' => $readOnly
						]
						),
						[
							'label' => new OOUI\HtmlSnippet(
								$this->msg( 'abusefilter-edit-throttle-groups' )->parse()
							),
							'align' => 'top',
							'id' => 'mw-abusefilter-hidden-throttle',
							'help' => new OOUI\HtmlSnippet( $groupsHelp ),
							'helpInline' => true
						]
					);

				$throttleFields['abusefilter-edit-throttle-groups'] = $hiddenGroups;

				$throttleConfig = [
					'values' => $throttleGroups,
					'label' => $this->msg( 'abusefilter-edit-throttle-groups' )->parse(),
					'disabled' => $readOnly,
					'help' => $groupsHelp
				];
				$this->getOutput()->addJsConfigVars( 'throttleConfig', $throttleConfig );

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
							'classes' => [ 'mw-abusefilter-action-checkbox' ],
							'disabled' => $readOnly
						]
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

				$fields = [];
				$fields["abusefilter-edit-$action-message"] =
					$this->getExistingSelector( $msg, $readOnly, $action );
				$otherFieldName = $action === 'warn' ? 'wpFilterWarnMessageOther'
					: 'wpFilterDisallowMessageOther';

				$fields["abusefilter-edit-$action-other-label"] =
					new OOUI\FieldLayout(
						new OOUI\TextInputWidget( [
							'name' => $otherFieldName,
							'value' => $msg,
							// mw-abusefilter-warn-message-other, mw-abusefilter-disallow-message-other
							'id' => "mw-abusefilter-$action-message-other",
							'infusable' => true,
							'readOnly' => $readOnly
							]
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
				if ( MediaWikiServices::getInstance()->getPermissionManager()
					->userHasRight( $user, 'editinterface' )
				) {
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
				$tags = $set ? $parameters : [];
				'@phan-var string[] $parameters';
				$output = '';

				$checkbox =
					new OOUI\FieldLayout(
						new OOUI\CheckboxInputWidget( [
							'name' => 'wpFilterActionTag',
							'id' => 'mw-abusefilter-action-checkbox-tag',
							'selected' => $set,
							'classes' => [ 'mw-abusefilter-action-checkbox' ],
							'disabled' => $readOnly
						]
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
					'disabled' => $readOnly
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
							'id' => 'mw-abusefilter-hidden-tag-field',
							'readOnly' => $readOnly
						]
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
					list( $blockTalk, $defaultAnonDuration, $defaultUserDuration ) = $parameters;
				} else {
					if ( $set && count( $parameters ) === 1 ) {
						// Only blocktalk available
						// @phan-suppress-next-line PhanTypeArraySuspiciousNullable $parameters is array here
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
							'classes' => [ 'mw-abusefilter-action-checkbox' ],
							'disabled' => $readOnly
						]
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
						'disabled' => !AbuseFilter::canEditFilter( $user, $row )
					] );

				$userDuration =
					new OOUI\DropdownInputWidget( [
						'name' => 'wpBlockUserDuration',
						'options' => $suggestedBlocks,
						'value' => $defaultUserDuration,
						'disabled' => !AbuseFilter::canEditFilter( $user, $row )
					] );

				$blockOptions = [];
				if ( $config->get( 'BlockAllowsUTEdit' ) === true ) {
					$talkCheckbox =
						new OOUI\FieldLayout(
							new OOUI\CheckboxInputWidget( [
								'name' => 'wpFilterBlockTalk',
								'id' => 'mw-abusefilter-action-checkbox-blocktalk',
								'selected' => isset( $blockTalk ) && $blockTalk === 'blocktalk',
								'classes' => [ 'mw-abusefilter-action-checkbox' ],
								'disabled' => $readOnly
							]
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
							'classes' => [ 'mw-abusefilter-action-checkbox' ],
							'disabled' => $readOnly
						]
						),
						[
							'label' => $this->msg( $message )->text(),
							'align' => 'inline'
						]
					);
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
				'value' => $warnMsg === "abusefilter-$action" ? "abusefilter-$action" : 'other',
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
			$titles = $dbr->selectFieldValues(
				'page',
				'page_title',
				[
					'page_namespace' => 8,
					'page_title LIKE ' . $dbr->addQuotes( $pageTitlePrefix . '%' )
				],
				__METHOD__
			);

			$lang = $this->getLanguage();
			foreach ( $titles as $title ) {
				if ( $lang->lcfirst( $title ) === $lang->lcfirst( $warnMsg ) ) {
					$existingSelector->setValue( $lang->lcfirst( $warnMsg ) );
				}

				if ( $title !== "Abusefilter-$action" ) {
					$options[ $lang->lcfirst( $title ) ] = $lang->lcfirst( $title );
				}
			}
		}

		// abusefilter-edit-warn-other, abusefilter-edit-disallow-other
		$options[ $this->msg( "abusefilter-edit-$formId-other" )->text() ] = 'other';

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
	protected static function normalizeBlocks( array $durations ) {
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
	 * @param int|string $id The filter's ID number, or 'new'
	 * @return array|null Either a [ DB row, actions ] array representing the filter,
	 *  or NULL if the filter does not exist.
	 */
	public function loadFilterData( $id ) {
		if ( $id === 'new' ) {
			return [
				(object)[
					'af_pattern' => '',
					'af_enabled' => 1,
					'af_hidden' => 0,
					'af_global' => 0,
					'af_throttled' => 0,
					'af_hit_count' => 0
				],
				[]
			];
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
		$res = $dbr->select(
			'abuse_filter_action',
			[ 'afa_consequence', 'afa_parameters' ],
			[ 'afa_filter' => $id ],
			__METHOD__
		);

		$actions = [];
		foreach ( $res as $actionRow ) {
			$actions[$actionRow->afa_consequence] =
				array_filter( explode( "\n", $actionRow->afa_parameters ) );
		}

		return [ $row, $actions ];
	}

	/**
	 * Load filter data to show in the edit view from the DB.
	 * @param int|string $filter The filter ID being requested or 'new'.
	 * @param int|null $history_id If any, the history ID being requested.
	 * @return array|null Array with filter data if available, otherwise null.
	 * The first element contains the abuse_filter database row,
	 *  the second element is an array of related abuse_filter_action rows.
	 */
	private function loadFromDatabase( $filter, $history_id = null ) {
		if ( $history_id ) {
			return $this->loadHistoryItem( $history_id );
		} else {
			return $this->loadFilterData( $filter );
		}
	}

	/**
	 * Load data from the already-POSTed HTTP request.
	 *
	 * @throws BadMethodCallException If called without the request being POSTed or when trying
	 *   to import a filter but $filter is not 'new'
	 * @param int|string $filter The filter ID being requested.
	 * @return Status If good, the value is the array [ row, actions ]. If not, it contains an
	 * error message.
	 */
	public function loadRequest( $filter ): Status {
		$request = $this->getRequest();
		if ( !$request->wasPosted() ) {
			// Sanity
			throw new BadMethodCallException( __METHOD__ . ' called without the request being POSTed.' );
		}

		// We need some details like last editor
		list( $origRow, $origActions ) = $this->loadFilterData( $filter );

		// Default values
		$row = (object)[
			'af_throttled' => $origRow->af_throttled,
			'af_hit_count' => $origRow->af_hit_count,
		];
		$row->mOriginalRow = $origRow;
		$row->mOriginalActions = $origActions;

		// Check for importing
		$import = $request->getVal( 'wpImportText' );
		if ( $import ) {
			if ( $filter !== 'new' ) {
				// Sanity
				throw new BadMethodCallException( __METHOD__ . ' called for importing on existing filter.' );
			}
			$data = FormatJson::decode( $import );

			if ( !$this->isValidImportData( $data ) ) {
				return Status::newFatal( 'abusefilter-import-invalid-data' );
			}

			$importRow = $data->row;
			$actions = wfObjectToArray( $data->actions );

			// Some more default values
			$row->af_group = 'default';
			$row->af_global = 0;

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
			if ( $filter !== 'new' ) {
				// These aren't needed when saving the filter, but they are otherwise (e.g. if
				// saving fails and we need to show the edit interface again).
				$row->af_id = $origRow->af_id;
				$row->af_user = $origRow->af_user;
				$row->af_user_text = $origRow->af_user_text;
				$row->af_timestamp = $origRow->af_timestamp;
			}

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

			$actions = [];
			foreach ( array_filter( $this->getConfig()->get( 'AbuseFilterActions' ) ) as $action => $_ ) {
				// Check if it's set
				$enabled = $request->getCheck( 'wpFilterAction' . ucfirst( $action ) );

				if ( $enabled ) {
					$parameters = [];

					if ( $action === 'throttle' ) {
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
					} elseif ( $action === 'warn' ) {
						$specMsg = $request->getVal( 'wpFilterWarnMessage' );

						if ( $specMsg === 'other' ) {
							$specMsg = $request->getVal( 'wpFilterWarnMessageOther' );
						}

						$parameters[0] = $specMsg;
					} elseif ( $action === 'block' ) {
						$parameters[0] = $request->getCheck( 'wpFilterBlockTalk' ) ?
							'blocktalk' : 'noTalkBlockSet';
						$parameters[1] = $request->getVal( 'wpBlockAnonDuration' );
						$parameters[2] = $request->getVal( 'wpBlockUserDuration' );
					} elseif ( $action === 'disallow' ) {
						$specMsg = $request->getVal( 'wpFilterDisallowMessage' );

						if ( $specMsg === 'other' ) {
							$specMsg = $request->getVal( 'wpFilterDisallowMessageOther' );
						}

						$parameters[0] = $specMsg;
					} elseif ( $action === 'tag' ) {
						$parameters = explode( ',', trim( $request->getText( 'wpFilterTags' ) ) );
						if ( $parameters === [ '' ] ) {
							// Since it's not possible to manually add an empty tag, this only happens
							// if the form is submitted without touching the tag input field.
							// We pass an empty array so that the widget won't show an empty tag in the topbar
							$parameters = [];
						}
					}

					$actions[$action] = $parameters;
				}
			}
		}

		$row->af_actions = implode( ',', array_keys( $actions ) );

		return Status::newGood( [ $row, $actions ] );
	}

	/**
	 * Loads historical data in a form that the editor can understand.
	 * @param int $id History ID
	 * @return array|null Null if the history ID is not valid, otherwise array in the usual format:
	 * First element contains the abuse_filter row (as it was).
	 * Second element contains an array of abuse_filter_action rows.
	 */
	private function loadHistoryItem( $id ) : ?array {
		$dbr = wfGetDB( DB_REPLICA );

		$row = $dbr->selectRow( 'abuse_filter_history',
			'*',
			[ 'afh_id' => $id ],
			__METHOD__
		);

		if ( !$row ) {
			return null;
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

	/**
	 * Perform basic validation on the JSON-decoded import data. This doesn't check if parameters
	 * are valid etc., but only if the shape of the object is right.
	 *
	 * @param mixed $data Already JSON-decoded
	 * @return bool
	 */
	private function isValidImportData( $data ) {
		global $wgAbuseFilterActions;

		if ( !is_object( $data ) ) {
			return false;
		}

		$arr = get_object_vars( $data );

		$expectedKeys = [ 'row' => true, 'actions' => true ];
		if ( count( $arr ) !== count( $expectedKeys ) || array_diff_key( $arr, $expectedKeys ) ) {
			return false;
		}

		if ( !is_object( $arr['row'] ) || !( is_object( $arr['actions'] ) || $arr['actions'] === [] ) ) {
			return false;
		}

		foreach ( $arr['actions'] as $action => $params ) {
			if ( !array_key_exists( $action, $wgAbuseFilterActions ) || !is_array( $params ) ) {
				return false;
			}
		}

		if ( !AbuseFilter::isFullAbuseFilterRow( $arr['row'] ) ) {
			return false;
		}

		return true;
	}
}
