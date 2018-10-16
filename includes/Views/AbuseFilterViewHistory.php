<?php

class AbuseFilterViewHistory extends AbuseFilterView {
	/**
	 * @param SpecialAbuseFilter $page
	 * @param array $params
	 */
	public function __construct( SpecialAbuseFilter $page, $params ) {
		parent::__construct( $page, $params );
		$this->mFilter = $page->mFilter;
	}

	/**
	 * Shows the page
	 */
	public function show() {
		$out = $this->getOutput();
		$out->enableOOUI();
		$filter = $this->getRequest()->getText( 'filter' ) ?: $this->mFilter;

		if ( $filter ) {
			$out->setPageTitle( $this->msg( 'abusefilter-history' )->numParams( $filter ) );
		} else {
			$out->setPageTitle( $this->msg( 'abusefilter-filter-log' ) );
		}

		// Check perms. abusefilter-modify is a superset of abusefilter-view-private
		if ( $filter && AbuseFilter::filterHidden( $filter )
			&& !$this->getUser()->isAllowedAny( 'abusefilter-modify', 'abusefilter-view-private' )
		) {
			$out->addWikiMsg( 'abusefilter-history-error-hidden' );
			return;
		}

		// Useful links
		$links = [];
		if ( $filter ) {
			$links['abusefilter-history-backedit'] = $this->getTitle( $filter )->getFullURL();
		}

		foreach ( $links as $msg => $title ) {
			$links[$msg] =
				new OOUI\ButtonWidget( [
					'label' => $this->msg( $msg )->text(),
					'href' => $title
				] );
		}

		$backlinks =
			new OOUI\HorizontalLayout( [
				'items' => $links
			] );
		$out->addHTML( $backlinks );

		// For user
		$user = User::getCanonicalName( $this->getRequest()->getText( 'user' ), 'valid' );
		if ( $user ) {
			$out->addSubtitle(
				$this->msg(
					'abusefilter-history-foruser',
					// We don't really need to get a user ID
					Linker::userLink( 1, $user ),
					// For GENDER
					$user
				)->text()
			);
		}

		$formDescriptor = [
			'user' => [
				'type' => 'user',
				'name' => 'user',
				'default' => $user,
				'size' => '45',
				'label-message' => 'abusefilter-history-select-user'
			],
			'filter' => [
				'type' => 'text',
				'name' => 'filter',
				'default' => $filter,
				'size' => '45',
				'label-message' => 'abusefilter-history-select-filter'
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm->setSubmitTextMsg( 'abusefilter-history-select-submit' )
			->setWrapperLegendMsg( 'abusefilter-history-select-legend' )
			->setAction( $this->getTitle( 'history' )->getLocalURL() )
			->setMethod( 'get' )
			->prepareForm()
			->displayForm( false );

		$pager = new AbuseFilterHistoryPager( $filter, $this, $user, $this->linkRenderer );

		$out->addHTML(
			$pager->getNavigationBar() .
			$pager->getBody() .
			$pager->getNavigationBar()
		);
	}
}
