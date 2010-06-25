<?php
if ( !defined( 'MEDIAWIKI' ) )
	die();

class SpecialAbuseLog extends SpecialPage {
	public function __construct() {
		wfLoadExtensionMessages( 'AbuseFilter' );
		parent::__construct( 'AbuseLog', 'abusefilter-log' );
	}

	public function execute( $parameter ) {
		global $wgUser, $wgOut, $wgRequest, $wgAbuseFilterStyleVersion;

		AbuseFilter::addNavigationLinks( $wgOut, $wgUser->getSkin(), 'log' );

		$this->setHeaders();
		$this->outputHeader( 'abusefilter-log-summary' );
		$this->loadParameters();

		$wgOut->setPageTitle( wfMsg( 'abusefilter-log' ) );
		$wgOut->setRobotPolicy( "noindex,nofollow" );
		$wgOut->setArticleRelated( false );
		$wgOut->enableClientCache( false );

		global $wgScriptPath;
		$wgOut->addExtensionStyle( $wgScriptPath .
			"/extensions/AbuseFilter/abusefilter.css?$wgAbuseFilterStyleVersion" );

		// Are we allowed?
		$errors = $this->getTitle()->getUserPermissionsErrors(
			'abusefilter-log', $wgUser, true, array( 'ns-specialprotected' ) );
		if ( count( $errors ) ) {
			// Go away.
			$wgOut->showPermissionsErrorPage( $errors, 'abusefilter-log' );
			return;
		}

		$detailsid = $wgRequest->getIntOrNull( 'details' );
		$hideid = $wgRequest->getIntOrNull( 'hide' );
		if ( $detailsid ) {
			$this->showDetails( $detailsid );
		} elseif ( $hideid ) {
			$this->showHideForm( $hideid );
		} else {
			// Show the search form.
			$this->searchForm();

			// Show the log itself.
			$this->showList();
		}
	}

	function loadParameters() {
		global $wgRequest;

		$this->mSearchUser = $wgRequest->getText( 'wpSearchUser' );

		$t = Title::newFromText( trim( $this->mSearchUser ) );
		if ( $t ) {
			$this->mSearchUser = $t->getText(); // Username normalisation
		} else {
			$this->mSearchUser = null;
		}

		$this->mSearchTitle = $wgRequest->getText( 'wpSearchTitle' );
		$this->mSearchFilter = null;
		if ( self::canSeeDetails() ) {
			$this->mSearchFilter = $wgRequest->getIntOrNull( 'wpSearchFilter' );
		}
	}

	function searchForm() {
		global $wgOut, $wgUser;

		$output = Xml::element( 'legend', null, wfMsg( 'abusefilter-log-search' ) );
		$fields = array();

		// Search conditions
		$fields['abusefilter-log-search-user'] =
			Xml::input( 'wpSearchUser', 45, $this->mSearchUser );
		if ( self::canSeeDetails() ) {
			$fields['abusefilter-log-search-filter'] =
				Xml::input( 'wpSearchFilter', 45, $this->mSearchFilter );
		}
		$fields['abusefilter-log-search-title'] =
			Xml::input( 'wpSearchTitle', 45, $this->mSearchTitle );

		$form = Xml::hidden( 'title', $this->getTitle()->getPrefixedText() );

		$form .= Xml::buildForm( $fields, 'abusefilter-log-search-submit' );
		$output .= Xml::tags( 'form',
			array( 'method' => 'GET', 'action' => $this->getTitle()->getLocalURL() ),
			$form );
		$output = Xml::tags( 'fieldset', null, $output );

		$wgOut->addHTML( $output );
	}
	
	function showHideForm( $id ) {
		global $wgOut, $wgUser;
		
		if ( ! $wgUser->isAllowed( 'abusefilter-hide-log' ) ) {
			$wgOut->addWikiMsg( 'abusefilter-log-hide-forbidden' );
			return;
		}
		
		$dbr = wfGetDB( DB_SLAVE );

		$row = $dbr->selectRow( array( 'abuse_filter_log', 'abuse_filter' ), '*',
			array( 'afl_id' => $id ), __METHOD__, array(),
			array( 'abuse_filter' => array( 'LEFT JOIN', 'af_id=afl_filter' ) ) );

		if ( !$row ) {
			return;
		}
		
		$formInfo = array(
			'logid' => array(
				'type' => 'info',
				'default' => $id,
				'label-message' => 'abusefilter-log-hide-id',
			),
			'reason' => array(
				'type' => 'text',
				'label-message' => 'abusefilter-log-hide-reason',
			),
			'hidden' => array(
				'type' => 'toggle',
				'default' => $row->afl_deleted,
				'label-message' => 'abusefilter-log-hide-hidden',
			),
		);
		
		$form = new HTMLForm( $formInfo );
		$form->setTitle( $this->getTitle() );
		$form->addHiddenField( 'hide', $id );
		$form->setSubmitCallback( array( $this, 'saveHideForm' ) );
		$form->show();
	}
	
	function saveHideForm( $fields ) {
		global $wgRequest, $wgOut;
		$logid = $wgRequest->getVal( 'hide' );
		
		$dbw = wfGetDB( DB_MASTER );
		
		$dbw->update( 'abuse_filter_log', array( 'afl_deleted' => $fields['hidden'] ),
				array( 'afl_id' => $logid ) );
				
		$wgOut->redirect( SpecialPage::getTitleFor( 'AbuseLog' )->getFullURL() );
		
		return true;
	}

	function showList() {
		global $wgOut;

		// Generate conditions list.
		$conds = array();

		if ( $this->mSearchUser ) {
			$user = User::newFromName( $this->mSearchUser );
			
			if ( !$user ) {
				$conds[] = 'afl_ip=afl_user_text';
				$conds['afl_user'] = 0;
				$conds['afl_user_text'] = $this->mSearchUser;
			} else {			
				$conds['afl_user'] = $user->getId();
				$conds['afl_user_text'] = $user->getName();
			}
		}

		if ( $this->mSearchFilter ) {
			$conds['afl_filter'] = $this->mSearchFilter;
		}

		$searchTitle = Title::newFromText( $this->mSearchTitle );
		if ( $this->mSearchTitle && $searchTitle ) {
			$conds['afl_namespace'] = $searchTitle->getNamespace();
			$conds['afl_title'] = $searchTitle->getDBkey();
		}

		$pager = new AbuseLogPager( $this, $conds );

		$wgOut->addHTML( $pager->getNavigationBar() .
				Xml::tags( 'ul', null, $pager->getBody() ) .
				$pager->getNavigationBar() );
	}

	function showDetails( $id ) {
		if ( !self::canSeeDetails() ) {
			return;
		}

		$dbr = wfGetDB( DB_SLAVE );

		$row = $dbr->selectRow( array( 'abuse_filter_log', 'abuse_filter' ), '*',
			array( 'afl_id' => $id ), __METHOD__, array(),
			array( 'abuse_filter' => array( 'LEFT JOIN', 'af_id=afl_filter' ) ) );

		if ( !$row ) {
			return;
		}
		
		if ( $row->afl_deleted && !self::canSeeHidden() ) {
			global $wgOut;
			$wgOut->addWikiMsg( 'abusefilter-log-details-hidden' );
			return;
		}

		$output = '';

		$output .= Xml::element( 'legend', null, wfMsg( 'abusefilter-log-details-legend', $id ) );
		$output .= Xml::tags( 'p', null, $this->formatRow( $row, false ) );

		// Load data
		$vars = AbuseFilter::loadVarDump( $row->afl_var_dump );

		// Diff, if available
		if ( $vars->getVar( 'action' )->toString() == 'edit' ) {
			$old_wikitext = $vars->getVar( 'old_wikitext' )->toString();
			$new_wikitext = $vars->getVar( 'new_wikitext' )->toString();

			$diffEngine = new DifferenceEngine();

			$diffEngine->showDiffStyle();
			$formattedDiff = $diffEngine->generateDiffBody( $old_wikitext, $new_wikitext );

			static $colDescriptions = "<col class='diff-marker' />
        <col class='diff-content' />
        <col class='diff-marker' />
        <col class='diff-content' />";

			$formattedDiff =
				"<table class='diff'>$colDescriptions<tbody>$formattedDiff</tbody></table>";

			$output .=
				Xml::tags(
					'h3',
					null,
					wfMsgExt( 'abusefilter-log-details-diff', 'parseinline' )
				);

			$output .= $formattedDiff;
		}

		$output .= Xml::element( 'h3', null, wfMsg( 'abusefilter-log-details-vars' ) );

		// Build a table.
		$output .= AbuseFilter::buildVarDumpTable( $vars );

		if ( self::canSeePrivate() ) {
			// Private stuff, like IPs.
			$header =
				Xml::element( 'th', null, wfMsg( 'abusefilter-log-details-var' ) ) .
				Xml::element( 'th', null, wfMsg( 'abusefilter-log-details-val' ) );
			$output .= Xml::element( 'h3', null, wfMsg( 'abusefilter-log-details-private' ) );
			$output .=
				Xml::openElement( 'table',
					array(
						'class' => 'wikitable mw-abuselog-private',
						'style' => 'width: 80%;'
					)
				) .
				Xml::openElement( 'tbody' );
			$output .= $header;

			// IP address
			$output .=
				Xml::tags( 'tr', null,
					Xml::element( 'td',
						array( 'style' => 'width: 30%;' ),
						wfMsg( 'abusefilter-log-details-ip' )
					) .
					Xml::element( 'td', null, $row->afl_ip )
				);

			$output .= Xml::closeElement( 'tbody' ) . Xml::closeElement( 'table' );
		}

		$output = Xml::tags( 'fieldset', null, $output );

		global $wgOut;
		$wgOut->addHTML( $output );
	}

	static function canSeeDetails() {
		global $wgUser;
		return $wgUser->isAllowed( 'abusefilter-log-detail' );
	}

	static function canSeePrivate() {
		global $wgUser;
		return $wgUser->isAllowed( 'abusefilter-private' );
	}
	
	static function canSeeHidden() {
		global $wgUser;
		return $wgUser->isAllowed( 'abusefilter-hidden-log' );
	}

	function formatRow( $row, $li = true ) {
		global $wgLang, $wgUser;

		# # One-time setup
		static $sk = null;
		
		$actionLinks = array();

		if ( is_null( $sk ) ) {
			$sk = $wgUser->getSkin();
		}

		$title = Title::makeTitle( $row->afl_namespace, $row->afl_title );

		if ( !$row->afl_wiki ) {
			$pageLink = $sk->link( $title );
		} else {
			$pageLink = WikiMap::makeForeignLink( $row->afl_wiki, $row->afl_title );
		}

		if ( !$row->afl_wiki ) {
			// Local user
			$user = $sk->userLink( $row->afl_user, $row->afl_user_text ) .
				$sk->userToolLinks( $row->afl_user, $row->afl_user_text );
		} else {
			$user = WikiMap::foreignUserLink( $row->afl_wiki, $row->afl_user_text );
			$user .= ' (' . WikiMap::getWikiName( $row->afl_wiki ) . ')';
		}

		$description = '';

		$timestamp = $wgLang->timeanddate( $row->afl_timestamp, true );

		$actions_taken = $row->afl_actions;
		if ( !strlen( trim( $actions_taken ) ) ) {
			$actions_taken = wfMsg( 'abusefilter-log-noactions' );
		} else {
			$actions = explode( ',', $actions_taken );
			$displayActions = array();

			foreach ( $actions as $action ) {
				$displayActions[] = AbuseFilter::getActionDisplay( $action );
			}
			$actions_taken = $wgLang->commaList( $displayActions );
		}

		$globalIndex = AbuseFilter::decodeGlobalName( $row->afl_filter );

		global $wgOut;
		if ( $globalIndex ) {
			// Pull global filter description
			$parsed_comments =
				$wgOut->parseInline( AbuseFilter::getGlobalFilterDescription( $globalIndex ) );
		} else {
			$parsed_comments = $wgOut->parseInline( $row->af_public_comments );
		}

		if ( self::canSeeDetails() ) {
			$examineTitle = SpecialPage::getTitleFor( 'AbuseFilter', 'examine/log/' . $row->afl_id );
			$detailsLink = $sk->makeKnownLinkObj(
				$this->getTitle(),
				wfMsg( 'abusefilter-log-detailslink' ),
				'details=' . $row->afl_id
			);
			$examineLink = $sk->link(
				$examineTitle,
				wfMsgExt( 'abusefilter-changeslist-examine', 'parseinline' ),
				array()
			);
			
			$actionLinks[] = $detailsLink;
			$actionLinks[] = $examineLink;
			
			if ( $wgUser->isAllowed( 'abusefilter-hide-log' ) ) {
				$hideLink = $sk->link(
						$this->getTitle(),
						wfMsg( 'abusefilter-log-hidelink' ),
						array(),
						array( 'hide' => $row->afl_id )
					);
				
				$actionLinks[] = $hideLink;
			}

			if ( $globalIndex ) {
				global $wgAbuseFilterCentralDB;
				$globalURL =
					WikiMap::getForeignURL( $wgAbuseFilterCentralDB,
											'Special:AbuseFilter/' . $globalIndex );

				$linkText = wfMsgExt( 'abusefilter-log-detailedentry-global',
										'parseinline', array( $globalIndex ) );

				$filterLink = $sk->makeExternalLink( $globalURL, $linkText );
			} else {
				$title = SpecialPage::getTitleFor( 'AbuseFilter', $row->afl_filter );
				$linkText = wfMsgExt( 'abusefilter-log-detailedentry-local',
										'parseinline', array( $row->afl_filter ) );
				$filterLink = $sk->link( $title, $linkText );
			}
			$description = wfMsgExt( 'abusefilter-log-detailedentry-meta',
				array( 'parseinline', 'replaceafter' ),
				array(
					$timestamp,
					$user,
					$filterLink,
					$row->afl_action,
					$pageLink,
					$actions_taken,
					$parsed_comments,
					$wgLang->pipeList( $actionLinks ),
				)
			);
		} else {
			$description = wfMsgExt(
				'abusefilter-log-entry',
				array( 'parseinline', 'replaceafter' ),
				array(
					$timestamp,
					$user,
					$row->afl_action,
					$sk->link( $title ),
					$actions_taken,
					$parsed_comments
				)
			);
		}
		
		if ( $row->afl_deleted ) {
			$description .= ' '.
				wfMsgExt( 'abusefilter-log-hidden', 'parseinline' );
		}

		return $li ? Xml::tags( 'li', null, $description ) : $description;
	}
}

class AbuseLogPager extends ReverseChronologicalPager {
	public $mForm, $mConds;

	function __construct( $form, $conds = array(), $details = false ) {
		$this->mForm = $form;
		$this->mConds = $conds;
		parent::__construct();
	}

	function formatRow( $row ) {
		return $this->mForm->formatRow( $row );
	}

	function getQueryInfo() {
		$conds = $this->mConds;

		$info = array(
			'tables' => array( 'abuse_filter_log', 'abuse_filter' ),
			'fields' => '*',
			'conds' => $conds,
			'join_conds' =>
				array( 'abuse_filter' =>
					array(
						'LEFT JOIN',
						'af_id=afl_filter',
					),
				),
		);
		
		if ( ! $this->mForm->canSeeHidden() ) {
			$info['conds']['afl_deleted'] = 0;
		}
		
		return $info;
	}

	function getIndexField() {
		return 'afl_timestamp';
	}
}
