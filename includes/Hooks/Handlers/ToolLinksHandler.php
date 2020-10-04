<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use IContextSource;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Linker\LinkRenderer;
use SpecialPage;
use Title;
use Wikimedia\IPUtils;

class ToolLinksHandler implements
	\MediaWiki\Hook\ContributionsToolLinksHook,
	\MediaWiki\Hook\HistoryPageToolLinksHook,
	\MediaWiki\Hook\UndeletePageToolLinksHook
{

	/** @var AbuseFilterPermissionManager */
	private $afPermManager;

	/**
	 * ToolLinksHandler constructor.
	 * @param AbuseFilterPermissionManager $afPermManager
	 */
	public function __construct( AbuseFilterPermissionManager $afPermManager ) {
		$this->afPermManager = $afPermManager;
	}

	/**
	 * @param int $id
	 * @param Title $nt
	 * @param array &$tools
	 * @param SpecialPage $sp for context
	 */
	public function onContributionsToolLinks( $id, $nt, &$tools, $sp ) {
		$username = $nt->getText();
		if ( $this->afPermManager->canViewAbuseLog( $sp->getUser() )
			&& !IPUtils::isValidRange( $username )
		) {
			$linkRenderer = $sp->getLinkRenderer();
			$tools['abuselog'] = $linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'AbuseLog' ),
				$sp->msg( 'abusefilter-log-linkoncontribs' )->text(),
				[ 'title' => $sp->msg( 'abusefilter-log-linkoncontribs-text',
					$username )->text() ],
				[ 'wpSearchUser' => $username ]
			);
		}
	}

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param string[] &$links
	 */
	public function onHistoryPageToolLinks( $context, $linkRenderer, &$links ) {
		if ( $this->afPermManager->canViewAbuseLog( $context->getUser() ) ) {
			$links[] = $linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'AbuseLog' ),
				$context->msg( 'abusefilter-log-linkonhistory' )->text(),
				[ 'title' => $context->msg( 'abusefilter-log-linkonhistory-text' )->text() ],
				[ 'wpSearchTitle' => $context->getTitle()->getPrefixedText() ]
			);
		}
	}

	/**
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param string[] &$links
	 */
	public function onUndeletePageToolLinks( $context, $linkRenderer, &$links ) {
		$show = $this->afPermManager->canViewAbuseLog( $context->getUser() );
		$action = $context->getRequest()->getVal( 'action', 'view' );

		// For 'history action', the link would be added by HistoryPageToolLinks hook.
		if ( $show && $action !== 'history' ) {
			$links[] = $linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'AbuseLog' ),
				$context->msg( 'abusefilter-log-linkonundelete' )->text(),
				[ 'title' => $context->msg( 'abusefilter-log-linkonundelete-text' )->text() ],
				[ 'wpSearchTitle' => $context->getTitle()->getPrefixedText() ]
			);
		}
	}
}
