<?php

use MediaWiki\MediaWikiServices;

/**
 * Parent class for AbuseFilter special pages.
 */
abstract class AbuseFilterSpecialPage extends SpecialPage {
	/**
	 * Add topbar navigation links
	 *
	 * @param string $pageType
	 */
	protected function addNavigationLinks( $pageType ) {
		$user = $this->getUser();

		$linkDefs = [
			'home' => 'Special:AbuseFilter',
			'recentchanges' => 'Special:AbuseFilter/history',
			'examine' => 'Special:AbuseFilter/examine',
		];

		if ( MediaWikiServices::getInstance()->getPermissionManager()
			->userHasRight( $user, 'abusefilter-log' )
		) {
			$linkDefs = array_merge( $linkDefs, [
				'log' => 'Special:AbuseLog'
			] );
		}

		if ( AbuseFilter::canViewPrivate( $user ) ) {
			$linkDefs = array_merge( $linkDefs, [
				'test' => 'Special:AbuseFilter/test',
				'tools' => 'Special:AbuseFilter/tools'
			] );
		}

		$links = [];

		foreach ( $linkDefs as $name => $page ) {
			// Give grep a chance to find the usages:
			// abusefilter-topnav-home, abusefilter-topnav-recentchanges, abusefilter-topnav-test,
			// abusefilter-topnav-log, abusefilter-topnav-tools, abusefilter-topnav-examine
			$msgName = "abusefilter-topnav-$name";

			$msg = $this->msg( $msgName )->parse();
			$title = Title::newFromText( $page );

			if ( $name === $pageType ) {
				$links[] = Xml::tags( 'strong', null, $msg );
			} else {
				$links[] = $this->getLinkRenderer()->makeLink( $title, new HtmlArmor( $msg ) );
			}
		}

		$linkStr = $this->msg( 'parentheses' )
			->rawParams( $this->getLanguage()->pipeList( $links ) )
			->text();
		$linkStr = $this->msg( 'abusefilter-topnav' )->parse() . " $linkStr";

		$linkStr = Xml::tags( 'div', [ 'class' => 'mw-abusefilter-navigation' ], $linkStr );

		$this->getOutput()->setSubtitle( $linkStr );
	}
}
