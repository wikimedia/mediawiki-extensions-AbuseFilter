<?php

/**
 * The default view used in Special:AbuseFilter
 */
class AbuseFilterViewList extends AbuseFilterView {
	function show() {
		global $wgAbuseFilterCentralDB, $wgAbuseFilterIsCentral;

		$out = $this->getOutput();
		$request = $this->getRequest();

		// Status info...
		$this->showStatus();

		$out->addWikiMsg( 'abusefilter-intro' );

		// New filter button
		if ( $this->canEdit() ) {
			$title = $this->getTitle( 'new' );
			$link = $this->linkRenderer->makeLink( $title, $this->msg( 'abusefilter-new' )->text() );
			$links = Xml::tags( 'p', null, $link ) . "\n";
			$out->addHTML( $links );
		}

		// Options.
		$conds = [];
		$deleted = $request->getVal( 'deletedfilters' );
		$hidedisabled = $request->getBool( 'hidedisabled' );
		$defaultscope = 'all';
		if ( isset( $wgAbuseFilterCentralDB ) && !$wgAbuseFilterIsCentral ) {
			// Show on remote wikis as default only local filters
			$defaultscope = 'local';
		}
		$scope = $request->getVal( 'rulescope', $defaultscope );

		if ( $deleted == 'show' ) {
			# Nothing
		} elseif ( $deleted == 'only' ) {
			$conds['af_deleted'] = 1;
		} else { # hide, or anything else.
			$conds['af_deleted'] = 0;
			$deleted = 'hide';
		}
		if ( $hidedisabled ) {
			$conds['af_deleted'] = 0;
			$conds['af_enabled'] = 1;
		}

		if ( $scope == 'local' ) {
			$conds['af_global'] = 0;
		} elseif ( $scope == 'global' ) {
			$conds['af_global'] = 1;
		}

		$this->showList( $conds, compact( 'deleted', 'hidedisabled', 'scope' ) );
	}

	function showList( $conds = [ 'af_deleted' => 0 ], $optarray = [] ) {
		global $wgAbuseFilterCentralDB, $wgAbuseFilterIsCentral;

		$output = '';
		$output .= Xml::element( 'h2', null,
			$this->msg( 'abusefilter-list' )->parse() );

		$pager = new AbuseFilterPager( $this, $conds, $this->linkRenderer );

		$deleted = $optarray['deleted'];
		$hidedisabled = $optarray['hidedisabled'];
		$scope = $optarray['scope'];

		# Options form
		$fields = [];
		$fields['abusefilter-list-options-deleted'] =
			Xml::radioLabel(
				$this->msg( 'abusefilter-list-options-deleted-show' )->text(),
				'deletedfilters',
				'show',
				'mw-abusefilter-deletedfilters-show',
				$deleted == 'show'
			) .
			Xml::radioLabel(
				$this->msg( 'abusefilter-list-options-deleted-hide' )->text(),
				'deletedfilters',
				'hide',
				'mw-abusefilter-deletedfilters-hide',
				$deleted == 'hide'
			) .
			Xml::radioLabel(
				$this->msg( 'abusefilter-list-options-deleted-only' )->text(),
				'deletedfilters',
				'only',
				'mw-abusefilter-deletedfilters-only',
				$deleted == 'only'
			);

		if ( isset( $wgAbuseFilterCentralDB ) ) {
			$fields['abusefilter-list-options-scope'] =
				Xml::radioLabel(
					$this->msg( 'abusefilter-list-options-scope-local' )->text(),
					'rulescope',
					'local',
					'mw-abusefilter-rulescope-local',
					$scope == 'local'
				) .
				Xml::radioLabel(
					$this->msg( 'abusefilter-list-options-scope-global' )->text(),
					'rulescope',
					'global',
					'mw-abusefilter-rulescope-global',
					$scope == 'global'
				);

			if ( $wgAbuseFilterIsCentral ) {
				// For central wiki: add third scope option
				$fields['abusefilter-list-options-scope'] .=
					Xml::radioLabel(
						$this->msg( 'abusefilter-list-options-scope-all' )->text(),
						'rulescope',
						'all',
						'mw-abusefilter-rulescope-all',
						$scope == 'all'
				);
			}
		}

		$fields['abusefilter-list-options-disabled'] =
			Xml::checkLabel(
				$this->msg( 'abusefilter-list-options-hidedisabled' )->text(),
				'hidedisabled',
				'mw-abusefilter-disabledfilters-hide',
				$hidedisabled
			);
		$fields['abusefilter-list-limit'] = $pager->getLimitSelect();

		$options = Xml::buildForm( $fields, 'abusefilter-list-options-submit' );
		$options .= Html::hidden( 'title', $this->getTitle()->getPrefixedDBkey() );
		$options = Xml::tags( 'form',
			[
				'method' => 'get',
				'action' => $this->getTitle()->getFullURL()
			],
			$options
		);
		$options = Xml::fieldset( $this->msg( 'abusefilter-list-options' )->text(), $options );

		$output .= $options;

		if ( isset( $wgAbuseFilterCentralDB ) && !$wgAbuseFilterIsCentral && $scope == 'global' ) {
			$globalPager = new GlobalAbuseFilterPager( $this, $conds, $this->linkRenderer );
			$output .=
				$globalPager->getNavigationBar() .
				$globalPager->getBody() .
				$globalPager->getNavigationBar();
		} else {
			$output .=
				$pager->getNavigationBar() .
				$pager->getBody() .
				$pager->getNavigationBar();
		}

		$this->getOutput()->addHTML( $output );
	}

	function showStatus() {
		global $wgAbuseFilterConditionLimit, $wgAbuseFilterValidGroups;

		$stash = ObjectCache::getMainStashInstance();
		$overflow_count = (int)$stash->get( AbuseFilter::filterLimitReachedKey() );
		$match_count = (int)$stash->get( AbuseFilter::filterMatchesKey() );
		$total_count = 0;
		foreach ( $wgAbuseFilterValidGroups as $group ) {
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
					$wgAbuseFilterConditionLimit,
					$match_count,
					$match_percent
				)->parse();

			$status = Xml::tags( 'div', [ 'class' => 'mw-abusefilter-status' ], $status );
			$this->getOutput()->addHTML( $status );
		}
	}
}
