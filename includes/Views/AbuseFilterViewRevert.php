<?php

class AbuseFilterViewRevert extends AbuseFilterView {
	public $origPeriodStart, $origPeriodEnd, $mPeriodStart, $mPeriodEnd;
	public $mReason;

	/**
	 * Shows the page
	 */
	public function show() {
		$lang = $this->getLanguage();
		$filter = $this->mPage->mFilter;

		$user = $this->getUser();
		$out = $this->getOutput();

		if ( !$user->isAllowed( 'abusefilter-revert' ) ) {
			throw new PermissionsError( 'abusefilter-revert' );
		}

		$this->loadParameters();

		if ( $this->attemptRevert() ) {
			return;
		}

		$out->addWikiMsg( 'abusefilter-revert-intro', Message::numParam( $filter ) );
		$out->setPageTitle( $this->msg( 'abusefilter-revert-title' )->numParams( $filter ) );

		// First, the search form. Limit dates to avoid huge queries
		$RCMaxAge = $this->getConfig()->get( 'RCMaxAge' );
		$min = wfTimestamp( TS_ISO_8601, time() - $RCMaxAge );
		$max = wfTimestampNow();
		$filterLink =
			$this->linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'AbuseFilter', intval( $filter ) ),
				$lang->formatNum( intval( $filter ) )
			);
		$searchFields = [];
		$searchFields['filterid'] = [
			'type' => 'info',
			'default' => $filterLink,
			'raw' => true,
			'label-message' => 'abusefilter-revert-filter'
		];
		$searchFields['periodstart'] = [
			'type' => 'datetime',
			'name' => 'wpPeriodStart',
			'default' => $this->origPeriodStart,
			'label-message' => 'abusefilter-revert-periodstart',
			'min' => $min,
			'max' => $max
		];
		$searchFields['periodend'] = [
			'type' => 'datetime',
			'name' => 'wpPeriodEnd',
			'default' => $this->origPeriodEnd,
			'label-message' => 'abusefilter-revert-periodend',
			'min' => $min,
			'max' => $max
		];

		HTMLForm::factory( 'ooui', $searchFields, $this->getContext() )
			->addHiddenField( 'submit', 1 )
			->setAction( $this->getTitle( "revert/$filter" )->getLocalURL() )
			->setWrapperLegendMsg( 'abusefilter-revert-search-legend' )
			->setSubmitTextMsg( 'abusefilter-revert-search' )
			->setMethod( 'post' )
			->prepareForm()
			->displayForm( false );

		if ( $this->mSubmit ) {
			// Add a summary of everything that will be reversed.
			$out->addWikiMsg( 'abusefilter-revert-preview-intro' );

			// Look up all of them.
			$results = $this->doLookup();
			$list = [];

			foreach ( $results as $result ) {
				$displayActions = array_map(
					[ 'AbuseFilter', 'getActionDisplay' ],
					$result['actions'] );

				$msg = $this->msg( 'abusefilter-revert-preview-item' )
					->params(
						$lang->timeanddate( $result['timestamp'], true )
					)->rawParams(
						Linker::userLink( $result['userid'], $result['user'] )
					)->params(
						$result['action']
					)->rawParams(
						$this->linkRenderer->makeLink( $result['title'] )
					)->params(
						$lang->commaList( $displayActions )
					)->rawParams(
						$this->linkRenderer->makeLink(
							SpecialPage::getTitleFor( 'AbuseLog' ),
							$this->msg( 'abusefilter-log-detailslink' )->text(),
							[],
							[ 'details' => $result['id'] ]
						)
					)->params( $result['user'] )->parse();
				$list[] = Xml::tags( 'li', null, $msg );
			}

			$out->addHTML( Xml::tags( 'ul', null, implode( "\n", $list ) ) );

			// Add a button down the bottom.
			$confirmForm = [];
			$confirmForm['edittoken'] = [
				'type' => 'hidden',
				'name' => 'editToken',
				'default' => $user->getEditToken( "abusefilter-revert-$filter" )
			];
			$confirmForm['title'] = [
				'type' => 'hidden',
				'name' => 'title',
				'default' => $this->getTitle( "revert/$filter" )->getPrefixedDBkey()
			];
			$confirmForm['wpPeriodStart'] = [
				'type' => 'hidden',
				'name' => 'wpPeriodStart',
				'default' => $this->origPeriodStart
			];
			$confirmForm['wpPeriodEnd'] = [
				'type' => 'hidden',
				'name' => 'wpPeriodEnd',
				'default' => $this->origPeriodEnd
			];
			$confirmForm['reason'] = [
				'type' => 'text',
				'label-message' => 'abusefilter-revert-reasonfield',
				'name' => 'wpReason',
				'id' => 'wpReason',
			];
			HTMLForm::factory( 'ooui', $confirmForm, $this->getContext() )
				->setAction( $this->getTitle( "revert/$filter" )->getLocalURL() )
				->setWrapperLegendMsg( 'abusefilter-revert-confirm-legend' )
				->setSubmitTextMsg( 'abusefilter-revert-confirm' )
				->setMethod( 'post' )
				->prepareForm()
				->displayForm( false );

		}
	}

	/**
	 * @return array
	 */
	public function doLookup() {
		$periodStart = $this->mPeriodStart;
		$periodEnd = $this->mPeriodEnd;
		$filter = $this->mPage->mFilter;

		$conds = [ 'afl_filter' => $filter ];

		$dbr = wfGetDB( DB_REPLICA );

		if ( $periodStart ) {
			$conds[] = 'afl_timestamp >= ' . $dbr->addQuotes( $dbr->timestamp( $periodStart ) );
		}
		if ( $periodEnd ) {
			$conds[] = 'afl_timestamp <= ' . $dbr->addQuotes( $dbr->timestamp( $periodEnd ) );
		}

		// All but afl_filter, afl_ip, afl_deleted, afl_patrolled_by, afl_rev_id and afl_log_id
		$selectFields = [
			'afl_id',
			'afl_user',
			'afl_user_text',
			'afl_action',
			'afl_actions',
			'afl_var_dump',
			'afl_timestamp',
			'afl_namespace',
			'afl_title',
			'afl_wiki',
		];
		$res = $dbr->select( 'abuse_filter_log', $selectFields, $conds, __METHOD__ );

		$results = [];
		foreach ( $res as $row ) {
			// Don't revert if there was no action, or the action was global
			if ( !$row->afl_actions || $row->afl_wiki != null ) {
				continue;
			}

			$actions = explode( ',', $row->afl_actions );
			$reversibleActions = [ 'block', 'blockautopromote', 'degroup' ];
			$currentReversibleActions = array_intersect( $actions, $reversibleActions );
			if ( count( $currentReversibleActions ) ) {
				$results[] = [
					'id' => $row->afl_id,
					'actions' => $currentReversibleActions,
					'user' => $row->afl_user_text,
					'userid' => $row->afl_user,
					'vars' => AbuseFilter::loadVarDump( $row->afl_var_dump ),
					'title' => Title::makeTitle( $row->afl_namespace, $row->afl_title ),
					'action' => $row->afl_action,
					'timestamp' => $row->afl_timestamp
				];
			}
		}

		return $results;
	}

	/**
	 * Loads parameters from request
	 */
	public function loadParameters() {
		$request = $this->getRequest();

		$this->origPeriodStart = $request->getText( 'wpPeriodStart' );
		$this->mPeriodStart = strtotime( $this->origPeriodStart );
		$this->origPeriodEnd = $request->getText( 'wpPeriodEnd' );
		$this->mPeriodEnd = strtotime( $this->origPeriodEnd );
		$this->mSubmit = $request->getVal( 'submit' );
		$this->mReason = $request->getVal( 'wpReason' );
	}

	/**
	 * @return bool
	 */
	public function attemptRevert() {
		$filter = $this->mPage->mFilter;
		$token = $this->getRequest()->getVal( 'editToken' );
		if ( !$this->getUser()->matchEditToken( $token, "abusefilter-revert-$filter" ) ) {
			return false;
		}

		$results = $this->doLookup();
		foreach ( $results as $result ) {
			$actions = $result['actions'];
			foreach ( $actions as $action ) {
				$this->revertAction( $action, $result );
			}
		}
		$this->getOutput()->wrapWikiMsg(
			'<p class="success">$1</p>',
			[
				'abusefilter-revert-success',
				$filter,
				$this->getLanguage()->formatNum( $filter )
			]
		);

		return true;
	}

	/**
	 * @param string $action
	 * @param array $result
	 * @return bool
	 * @throws MWException
	 */
	public function revertAction( $action, $result ) {
		switch ( $action ) {
			case 'block':
				$block = Block::newFromTarget( $result['user'] );
				if ( !( $block && $block->getBy() == AbuseFilter::getFilterUser()->getId() ) ) {
					// Not blocked by abuse filter
					return false;
				}
				$block->delete();
				$logEntry = new ManualLogEntry( 'block', 'unblock' );
				$logEntry->setTarget( Title::makeTitle( NS_USER, $result['user'] ) );
				$logEntry->setComment(
					$this->msg(
						'abusefilter-revert-reason', $this->mPage->mFilter, $this->mReason
					)->inContentLanguage()->text()
				);
				$logEntry->setPerformer( $this->getUser() );
				$logEntry->publish( $logEntry->insert() );
				return true;
			case 'blockautopromote':
				ObjectCache::getMainStashInstance()->delete(
					AbuseFilter::autoPromoteBlockKey( User::newFromId( $result['userid'] ) )
				);
				return true;
			case 'degroup':
				// Pull the user's groups from the vars.
				$oldGroups = $result['vars']->getVar( 'user_groups' )->toNative();
				$oldGroups = array_diff(
					$oldGroups,
					array_intersect( $oldGroups, User::getImplicitGroups() )
				);

				$rows = [];
				foreach ( $oldGroups as $group ) {
					$rows[] = [
						'ug_user' => $result['userid'],
						'ug_group' => $group
					];
				}

				// Cheat a little bit. User::addGroup repeatedly is too slow.
				$user = User::newFromId( $result['userid'] );
				$currentGroups = $user->getGroups();
				$newGroups = array_merge( $oldGroups, $currentGroups );

				// Don't do anything if there are no groups to add.
				if ( !count( array_diff( $newGroups, $currentGroups ) ) ) {
					return false;
				}

				$dbw = wfGetDB( DB_MASTER );
				$dbw->insert( 'user_groups', $rows, __METHOD__, [ 'IGNORE' ] );
				$user->invalidateCache();

				$logEntry = new ManualLogEntry( 'rights', 'rights' );
				$logEntry->setTarget( $user->getUserPage() );
				$logEntry->setPerformer( $this->getUser() );
				$logEntry->setComment(
					$this->msg(
						'abusefilter-revert-reason',
						$this->mPage->mFilter,
						$this->mReason
					)->inContentLanguage()->text()
				);
				$logEntry->setParameters( [
					'4::oldgroups' => $currentGroups,
					'5::newgroups' => $newGroups
				] );
				$logEntry->publish( $logEntry->insert() );

				return true;
		}

		throw new MWException( 'Invalid action' . $action );
	}
}
