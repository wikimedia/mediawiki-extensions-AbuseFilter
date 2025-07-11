<?php

namespace MediaWiki\Extension\AbuseFilter\Special;

use HtmlArmor;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleValue;

/**
 * Parent class for AbuseFilter special pages.
 */
abstract class AbuseFilterSpecialPage extends SpecialPage {

	/** @var AbuseFilterPermissionManager */
	protected $afPermissionManager;

	/**
	 * @param string $name
	 * @param string $restriction
	 * @param AbuseFilterPermissionManager $afPermissionManager
	 */
	public function __construct(
		$name,
		$restriction,
		AbuseFilterPermissionManager $afPermissionManager
	) {
		parent::__construct( $name, $restriction );
		$this->afPermissionManager = $afPermissionManager;
	}

	/**
	 * @inheritDoc
	 */
	public function getShortDescription( string $path = '' ): string {
		return match ( $path ) {
			'AbuseFilter' => $this->msg( 'abusefilter-topnav-home' )->text(),
			'AbuseFilter/history' => $this->msg( 'abusefilter-topnav-recentchanges' )->text(),
			'AbuseFilter/examine' => $this->msg( 'abusefilter-topnav-examine' )->text(),
			'AbuseFilter/test' => $this->msg( 'abusefilter-topnav-test' )->text(),
			'AbuseFilter/tools' => $this->msg( 'abusefilter-topnav-tools' )->text(),
			default => parent::getShortDescription( $path ),
		};
	}

	/**
	 * Get topbar navigation links definitions
	 */
	private function getNavigationLinksInternal(): array {
		$performer = $this->getAuthority();

		$linkDefs = [
			'home' => 'AbuseFilter',
			'recentchanges' => 'AbuseFilter/history',
			'examine' => 'AbuseFilter/examine',
		];

		if ( $this->afPermissionManager->canViewAbuseLog( $performer ) ) {
			$linkDefs += [
				'log' => 'AbuseLog'
			];
		}

		if ( $this->afPermissionManager->canUseTestTools( $performer ) ) {
			$linkDefs += [
				'test' => 'AbuseFilter/test',
				'tools' => 'AbuseFilter/tools'
			];
		}

		return $linkDefs;
	}

	/**
	 * Return an array of strings representing page titles that are discoverable to end users via UI.
	 *
	 * @inheritDoc
	 */
	public function getAssociatedNavigationLinks(): array {
		$links = $this->getNavigationLinksInternal();
		return array_map( static function ( $name ) {
			return 'Special:' . $name;
		}, array_values( $links ) );
	}

	/**
	 * Add topbar navigation links
	 *
	 * @param string $pageType
	 */
	protected function addNavigationLinks( $pageType ) {
		// If the current skin supports sub menus nothing to do here.
		if ( $this->getSkin()->supportsMenu( 'associated-pages' ) ) {
			return;
		}
		$linkDefs = $this->getNavigationLinksInternal();
		$links = [];
		foreach ( $linkDefs as $name => $page ) {
			// Give grep a chance to find the usages:
			// abusefilter-topnav-home, abusefilter-topnav-recentchanges, abusefilter-topnav-test,
			// abusefilter-topnav-log, abusefilter-topnav-tools, abusefilter-topnav-examine
			$msgName = "abusefilter-topnav-$name";

			$msg = $this->msg( $msgName )->parse();

			if ( $name === $pageType ) {
				$links[] = Html::rawElement( 'strong', [], $msg );
			} else {
				$links[] = $this->getLinkRenderer()->makeLink(
					new TitleValue( NS_SPECIAL, $page ),
					new HtmlArmor( $msg )
				);
			}
		}

		$linkStr = $this->msg( 'parentheses' )
			->rawParams( $this->getLanguage()->pipeList( $links ) )
			->escaped();
		$linkStr = $this->msg( 'abusefilter-topnav' )->parse() . " $linkStr";

		$linkStr = Html::rawElement( 'div', [ 'class' => 'mw-abusefilter-navigation' ], $linkStr );

		$this->getOutput()->setSubtitle( $linkStr );
	}
}
