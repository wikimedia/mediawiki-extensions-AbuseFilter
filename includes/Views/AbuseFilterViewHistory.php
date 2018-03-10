<?php

class AbuseFilterViewHistory extends AbuseFilterView {
	function __construct( $page, $params ) {
		parent::__construct( $page, $params );
		$this->mFilter = $page->mFilter;
	}

	function show() {
		$out = $this->getOutput();
		$filter = $this->getRequest()->getText( 'filter' ) ?: $this->mFilter;

		if ( $filter ) {
			$out->setPageTitle( $this->msg( 'abusefilter-history' )->numParams( $filter ) );
		} else {
			$out->setPageTitle( $this->msg( 'abusefilter-filter-log' ) );
		}

		# Check perms. abusefilter-modify is a superset of abusefilter-view-private
		if ( $filter && AbuseFilter::filterHidden( $filter )
			&& !$this->getUser()->isAllowedAny( 'abusefilter-modify', 'abusefilter-view-private' )
		) {
			$out->addWikiMsg( 'abusefilter-history-error-hidden' );
			return;
		}

		# Useful links
		$links = [];
		if ( $filter ) {
			$links['abusefilter-history-backedit'] = $this->getTitle( $filter );
		}

		foreach ( $links as $msg => $title ) {
			$links[$msg] = $this->linkRenderer->makeLink(
				$title,
				new HtmlArmor( $this->msg( $msg )->parse() )
			);
		}

		$backlinks = $this->getLanguage()->pipeList( $links );
		$out->addHTML( Xml::tags( 'p', null, $backlinks ) );

		# For user
		$user = User::getCanonicalName( $this->getRequest()->getText( 'user' ), 'valid' );
		if ( $user ) {
			$out->addSubtitle(
				$this->msg(
					'abusefilter-history-foruser',
					Linker::userLink( 1 /* We don't really need to get a user ID */, $user ),
					$user // For GENDER
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
		$table = $pager->getBody();

		$out->addHTML( $pager->getNavigationBar() . $table . $pager->getNavigationBar() );
	}
}
