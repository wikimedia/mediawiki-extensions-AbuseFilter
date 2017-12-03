<?php

class AbuseFilterViewRevert extends AbuseFilterView {
	public $origPeriodStart, $origPeriodEnd, $mPeriodStart, $mPeriodEnd,
		$mReason;

	function show() {
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

		// First, the search form.
		$searchFields = [];
		$searchFields['abusefilter-revert-filter'] =
			Xml::element( 'strong', null, $filter );
		$searchFields['abusefilter-revert-periodstart'] =
			Xml::input( 'wpPeriodStart', 45, $this->origPeriodStart );
		$searchFields['abusefilter-revert-periodend'] =
			Xml::input( 'wpPeriodEnd', 45, $this->origPeriodEnd );
		$searchForm = Xml::buildForm( $searchFields, 'abusefilter-revert-search' );
		$searchForm .= "\n" . Html::hidden( 'submit', 1 );
		$searchForm =
			Xml::tags(
				'form',
				[
					'action' => $this->getTitle( "revert/$filter" )->getLocalURL(),
					'method' => 'post'
				],
				$searchForm
			);
		$searchForm =
			Xml::fieldset( $this->msg( 'abusefilter-revert-search-legend' )->text(), $searchForm );

		$out->addHTML( $searchForm );

		if ( $this->mSubmit ) {
			// Add a summary of everything that will be reversed.
			$out->addWikiMsg( 'abusefilter-revert-preview-intro' );

			// Look up all of them.
			$results = $this->doLookup();
			$lang = $this->getLanguage();
			$list = [];

			foreach ( $results as $result ) {
				$displayActions = array_map(
					[ 'AbuseFilter', 'getActionDisplay' ],
					$result['actions'] );

				$msg = $this->msg( 'abusefilter-revert-preview-item' )
					->rawParams(
						$lang->timeanddate( $result['timestamp'], true ),
						Linker::userLink( $result['userid'], $result['user'] ),
						$result['action'],
						$this->linkRenderer->makeLink( $result['title'] ),
						$lang->commaList( $displayActions ),
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
			$confirmForm =
				Html::hidden( 'editToken', $user->getEditToken( "abusefilter-revert-$filter" ) ) .
				Html::hidden( 'title', $this->getTitle( "revert/$filter" )->getPrefixedDBkey() ) .
				Html::hidden( 'wpPeriodStart', $this->origPeriodStart ) .
				Html::hidden( 'wpPeriodEnd', $this->origPeriodEnd ) .
				Xml::inputLabel(
					$this->msg( 'abusefilter-revert-reasonfield' )->text(),
					'wpReason', 'wpReason', 45
				) .
				"\n" .
				Xml::submitButton( $this->msg( 'abusefilter-revert-confirm' )->text() );
			$confirmForm = Xml::tags(
				'form',
				[
					'action' => $this->getTitle( "revert/$filter" )->getLocalURL(),
					'method' => 'post'
				],
				$confirmForm
			);
			$out->addHTML( $confirmForm );
		}
	}

	function doLookup() {
		$periodStart = $this->mPeriodStart;
		$periodEnd = $this->mPeriodEnd;
		$filter = $this->mPage->mFilter;

		$conds = [ 'afl_filter' => $filter ];

		$dbr = wfGetDB( DB_REPLICA );

		if ( $periodStart ) {
			$conds[] = 'afl_timestamp>' . $dbr->addQuotes( $dbr->timestamp( $periodStart ) );
		}
		if ( $periodEnd ) {
			$conds[] = 'afl_timestamp<' . $dbr->addQuotes( $dbr->timestamp( $periodEnd ) );
		}

		// Database query.
		$res = $dbr->select( 'abuse_filter_log', '*', $conds, __METHOD__ );

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

	function loadParameters() {
		$request = $this->getRequest();

		$this->origPeriodStart = $request->getText( 'wpPeriodStart' );
		$this->mPeriodStart = strtotime( $this->origPeriodStart );
		$this->origPeriodEnd = $request->getText( 'wpPeriodEnd' );
		$this->mPeriodEnd = strtotime( $this->origPeriodEnd );
		$this->mSubmit = $request->getVal( 'submit' );
		$this->mReason = $request->getVal( 'wpReason' );
	}

	function attemptRevert() {
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
		$this->getOutput()->addWikiMsg(
			'abusefilter-revert-success',
			$filter,
			$this->getLanguage()->formatNum( $filter )
		);

		return true;
	}

	/**
	 * @param string $action
	 * @param array $result
	 * @return bool
	 * @throws MWException
	 */
	function revertAction( $action, $result ) {
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
				$oldGroups = $result['vars']['USER_GROUPS'];
				$oldGroups = explode( ',', $oldGroups );
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

				$log = new LogPage( 'rights' );
				$log->addEntry( 'rights', $user->getUserPage(),
					$this->msg(
						'abusefilter-revert-reason',
						$this->mPage->mFilter,
						$this->mReason
					)->inContentLanguage()->text(),
					[ implode( ',', $currentGroups ), implode( ',', $newGroups ) ]
				);

				return true;
		}

		throw new MWException( 'Invalid action' . $action );
	}
}
