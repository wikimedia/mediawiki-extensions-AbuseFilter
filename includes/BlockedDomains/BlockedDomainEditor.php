<?php

namespace MediaWiki\Extension\AbuseFilter\BlockedDomains;

use MediaWiki\Context\IContextSource;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Exception\PermissionsError;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Message\Message;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleValue;
use Wikimedia\Assert\Assert;
use Wikimedia\Message\MessageSpecifier;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IDBAccessObject;

class BlockedDomainEditor {

	public function __construct(
		private readonly IContextSource $context,
		private readonly Title $rootTitle,
		private readonly WANObjectCache $wanCache,
		private readonly LinkRenderer $linkRenderer,
		private readonly IBlockedDomainStorage $blockedDomainStorage,
		private readonly BlockedDomainValidator $blockedDomainValidator
	) {
	}

	private function isAllowList(): bool {
		return $this->blockedDomainStorage->isAllowList();
	}

	/**
	 * Validate an input domain or URL depending on mode
	 *
	 * @param string|null $input
	 * @return string|false Validated domain or URL string to store, or false if invalid
	 */
	private function validateInput( ?string $input ) {
		if ( !$input ) {
			return false;
		}
		$input = trim( $input );

		if ( $this->isAllowList() ) {
			// If input contains a slash (path) or scheme or query, it is intended to be a URL
			if ( str_contains( $input, '/' ) || str_contains( $input, '?' ) ) {
				$urlToTest = str_contains( $input, '//' ) ? $input : 'https://' . $input;
				if ( $this->blockedDomainValidator->normalizeUrl( $urlToTest ) === false ) {
					return false;
				}
				// Always store with scheme so that BlockedDomainAllowListLookup detects it via '//'
				return $urlToTest;
			}
		}

		return $this->blockedDomainValidator->validateDomain( $input );
	}

	/**
	 * Wrapper around wfMessage that sets the current context.
	 *
	 * @param string|string[]|MessageSpecifier $key
	 * @param mixed ...$params
	 * @return Message
	 * @see wfMessage
	 */
	public function msg( $key, ...$params ) {
		return $this->context->msg( $key, ...$params );
	}

	public function execute( ?string $par ) {
		if ( !$this->context->getConfig()->get( 'AbuseFilterEnableBlockedExternalDomain' ) ) {
			throw new ErrorPageError( 'abusefilter-disabled', 'disabledspecialpage-disabled' );
		}
		$this->context->getOutput()->addHelpLink( 'Manual:BlockedExternalDomains' );

		$request = $this->context->getRequest();
		match ( $par ) {
			'remove' => $this->showRemoveForm( $request->getVal( 'domain' ) ),
			'add' => $this->showAddForm( $request->getVal( 'domain' ) ),
			default => $this->showList(),
		};
	}

	private function showList() {
		$out = $this->context->getOutput();
		$isAllowList = $this->isAllowList();

		if ( $isAllowList ) {
			$out->setPageTitleMsg( $this->msg( 'abusefilter-blocked-domains-allowlist-title' ) );
			$out->wrapWikiMsg( "$1", 'abusefilter-blocked-domains-allowlist-intro' );
		} else {
			$out->setPageTitleMsg( $this->msg( 'abusefilter-blocked-domains-title' ) );
			$out->wrapWikiMsg( "$1", 'abusefilter-blocked-domains-intro' );
			$out->wrapWikiMsg( "$1", 'abusefilter-blocked-domains-allowlist-intro' );
		}
		$out->addModuleStyles( 'mediawiki.codex.messagebox.styles' );

		// Direct editing of this page is blocked via EditPermissionHandler
		$userCanManage = $this->context->getAuthority()->isAllowed( 'abusefilter-modify-blocked-external-domains' );

		// Show form to add a blocked/allowed domain
		if ( $userCanManage ) {
			$domainLabelMsg = $isAllowList
				? 'abusefilter-blocked-domains-allowlist-domain'
				: 'abusefilter-blocked-domains-domain';
			$addHeadingMsg = $isAllowList
				? 'abusefilter-blocked-domains-allowlist-add-heading'
				: 'abusefilter-blocked-domains-add-heading';
			$addExplanationMsg = $isAllowList
				? 'abusefilter-blocked-domains-allowlist-add-explanation'
				: 'abusefilter-blocked-domains-add-explanation';
			$addSubmitMsg = $isAllowList
				? 'abusefilter-blocked-domains-allowlist-add-submit'
				: 'abusefilter-blocked-domains-add-submit';

			$fields = [
				'Domain' => [
					'type' => 'text',
					'label' => $this->msg( $domainLabelMsg )->plain(),
					'required' => true,
				],
				'Notes' => [
					'type' => 'text',
					'maxlength' => 255,
					'label' => $this->msg( 'abusefilter-blocked-domains-notes' )->plain(),
					'size' => 250,
				],
			];

			HTMLForm::factory( 'ooui', $fields, $this->context )
				->setAction( $this->getPageTitle( 'add' )->getLocalURL() )
				->setWrapperLegendMsg( $addHeadingMsg )
				->setHeaderHtml( $this->msg( $addExplanationMsg )->parseAsBlock() )
				->setSubmitCallback( [ $this, 'processAddForm' ] )
				->setSubmitTextMsg( $addSubmitMsg )
				->show();

			if ( $out->getRedirect() !== '' ) {
				return;
			}
		}

		$res = $this->blockedDomainStorage->loadConfig( IDBAccessObject::READ_LATEST );
		if ( !$res->isGood() ) {
			return;
		}

		$domainHeaderMsg = $isAllowList
			? 'abusefilter-blocked-domains-allowlist-domain-header'
			: 'abusefilter-blocked-domains-domain-header';
		$content = Html::element( 'th', [], $this->msg( $domainHeaderMsg )->text() ) .
			Html::element( 'th', [], $this->msg( 'abusefilter-blocked-domains-notes-header' )->text() );
		if ( $userCanManage ) {
			$content .= Html::element(
				'th',
				[],
				$this->msg( 'abusefilter-blocked-domains-addedby-header' )->text()
			);
			$content .= Html::element(
				'th',
				[ 'class' => 'unsortable' ],
				$this->msg( 'abusefilter-blocked-domains-actions-header' )->text()
			);
		}
		$thead = Html::rawElement( 'tr', [], $content );

		// Parsing each row is expensive, put it behind WAN cache
		// with md5 checksum, we make sure changes to the domain list
		// invalidate the cache
		$cacheKey = $this->wanCache->makeKey(
			'abusefilter-blockeddomains-rows',
			md5( json_encode( $res->getValue() ) ),
			(int)$userCanManage
		);
		$tbody = $this->wanCache->getWithSetCallback(
			$cacheKey,
			WANObjectCache::TTL_DAY,
			function () use ( $res, $userCanManage ) {
				$tbody = '';
				foreach ( $res->getValue() as $domain ) {
					$tbody .= $this->doDomainRow( $domain, $userCanManage );
				}
				return $tbody;
			}
		);

		$out->addModuleStyles( [ 'jquery.tablesorter.styles', 'mediawiki.pager.styles' ] );
		$out->addModules( 'jquery.tablesorter' );
		$out->addHTML( Html::rawElement(
			'table',
			[ 'class' => 'mw-datatable sortable' ],
			Html::rawElement( 'thead', [], $thead ) .
			Html::rawElement( 'tbody', [], $tbody )
		) );
	}

	/**
	 * Show the row in the table
	 *
	 * @param array $domain domain data
	 * @param bool $showManageActions whether to add manage actions
	 * @return string HTML for the row
	 */
	private function doDomainRow( $domain, $showManageActions ) {
		$newRow = Html::rawElement( 'td', [], Html::element( 'code', [], $domain['domain'] ) );

		$newRow .= Html::rawElement( 'td', [],
			$this->context->getOutput()->parseInlineAsInterface( $domain['notes'] )
		);

		if ( $showManageActions ) {
			if ( isset( $domain['addedBy'] ) ) {
				$addedBy = $this->linkRenderer->makeLink(
					new TitleValue( 3, $domain['addedBy'] ),
					$domain['addedBy']
				);
			} else {
				$addedBy = '';
			}
			$newRow .= Html::rawElement( 'td', [], $addedBy );

			$actionLink = $this->linkRenderer->makeKnownLink(
				$this->getPageTitle( 'remove' ),
				$this->msg( 'abusefilter-blocked-domains-remove' )->text(),
				[],
				[ 'domain' => $domain['domain'] ]
			);
			$newRow .= Html::rawElement( 'td', [], $actionLink );
		}

		return Html::rawElement( 'tr', [], $newRow ) . "\n";
	}

	/**
	 * Show form for removing a domain from the blocked list
	 *
	 * @param string $domain
	 * @return void
	 */
	private function showRemoveForm( $domain ) {
		if ( !$this->context->getAuthority()->isAllowed( 'editsitejson' ) ) {
			throw new PermissionsError( 'editsitejson' );
		}

		$out = $this->context->getOutput();
		$isAllowList = $this->isAllowList();
		$titleMsg = $isAllowList
			? 'abusefilter-blocked-domains-allowlist-remove-title'
			: 'abusefilter-blocked-domains-remove-title';
		$out->setPageTitleMsg( $this->msg( $titleMsg ) );
		$out->addBacklinkSubtitle( $this->getPageTitle() );

		$explanationMsg = $isAllowList
			? 'abusefilter-blocked-domains-allowlist-remove-explanation-initial'
			: 'abusefilter-blocked-domains-remove-explanation-initial';
		$preText = $this->msg( $explanationMsg, $domain )->parseAsBlock();

		$domainLabelMsg = $isAllowList
			? 'abusefilter-blocked-domains-allowlist-domain'
			: 'abusefilter-blocked-domains-domain';
		$removeSubmitMsg = $isAllowList
			? 'abusefilter-blocked-domains-allowlist-remove-submit'
			: 'abusefilter-blocked-domains-remove-submit';

		$fields = [
			'Domain' => [
				'type' => 'text',
				'label' => $this->msg( $domainLabelMsg )->plain(),
				'required' => true,
				'default' => $domain,
			],
			'Notes' => [
				'type' => 'text',
				'maxlength' => 255,
				'label' => $this->msg( 'abusefilter-blocked-domains-notes' )->plain(),
				'size' => 250,
			],
		];

		HTMLForm::factory( 'ooui', $fields, $this->context )
			->setAction( $this->getPageTitle( 'remove' )->getLocalURL() )
			->setSubmitCallback( $this->processRemoveForm( ... ) )
			->setSubmitTextMsg( $removeSubmitMsg )
			->setSubmitDestructive()
			->addPreHtml( $preText )
			->show();
	}

	/**
	 * Process the form for removing a domain from the blocked list
	 *
	 * @param array $data request data
	 * @param HTMLForm $form
	 * @return bool whether the action was successful or not
	 */
	public function processRemoveForm( array $data, HTMLForm $form ) {
		$out = $form->getContext()->getOutput();
		$domain = $this->validateInput( $data['Domain'] );
		if ( $domain === false ) {
			$out->addHTML( Html::errorBox(
				$this->msg( 'http-invalid-url' )->plaintextParams( $data['Domain'] )->parse()
			) );
			return false;
		}

		$status = $this->blockedDomainStorage->removeDomain(
			$domain,
			$data['Notes'] ?? '',
			$this->context->getAuthority()
		);

		if ( !$status->isGood() ) {
			foreach ( $status->getMessages() as $msg ) {
				$out->addHTML( Html::errorBox( $this->msg( $msg )->parse() ) );
			}
			return false;
		}

		$out->redirect( $this->getPageTitle()->getLocalURL() );
		return true;
	}

	/**
	 * Show form for adding a domain to the blocked list
	 *
	 * @param string $domain
	 * @return void
	 */
	private function showAddForm( $domain ) {
		if ( !$this->context->getAuthority()->isAllowed( 'editsitejson' ) ) {
			throw new PermissionsError( 'editsitejson' );
		}

		$out = $this->context->getOutput();
		$isAllowList = $this->isAllowList();
		$addHeadingMsg = $isAllowList
			? 'abusefilter-blocked-domains-allowlist-add-heading'
			: 'abusefilter-blocked-domains-add-heading';
		$out->setPageTitleMsg( $this->msg( $addHeadingMsg ) );
		$out->addBacklinkSubtitle( $this->getPageTitle() );

		$addExplanationMsg = $isAllowList
			? 'abusefilter-blocked-domains-allowlist-add-explanation'
			: 'abusefilter-blocked-domains-add-explanation';
		$preText = $this->msg( $addExplanationMsg, $domain )->parseAsBlock();

		$domainLabelMsg = $isAllowList
			? 'abusefilter-blocked-domains-allowlist-domain'
			: 'abusefilter-blocked-domains-domain';
		$addSubmitMsg = $isAllowList
			? 'abusefilter-blocked-domains-allowlist-add-submit'
			: 'abusefilter-blocked-domains-add-submit';

		$fields = [
			'Domain' => [
				'type' => 'text',
				'label' => $this->msg( $domainLabelMsg )->plain(),
				'required' => true,
				'default' => $domain,
			],
			'Notes' => [
				'type' => 'text',
				'maxlength' => 255,
				'label' => $this->msg( 'abusefilter-blocked-domains-notes' )->plain(),
				'size' => 250,
			],
		];

		HTMLForm::factory( 'ooui', $fields, $this->context )
			->setAction( $this->getPageTitle( 'add' )->getLocalURL() )
			->setSubmitCallback( $this->processAddForm( ... ) )
			->setSubmitTextMsg( $addSubmitMsg )
			->addPreHtml( $preText )
			->show();
	}

	/**
	 * Process the form for adding a domain to the blocked list
	 *
	 * @param array $data request data
	 * @param HTMLForm $form
	 * @return bool whether the action was successful or not
	 */
	private function processAddForm( array $data, HTMLForm $form ) {
		$out = $form->getContext()->getOutput();

		$domain = $this->validateInput( $data['Domain'] );
		if ( $domain === false ) {
			$out->addHTML( Html::errorBox(
				$this->msg( 'http-invalid-url' )->plaintextParams( $data['Domain'] )->parse()
			) );
			return false;
		}
		$status = $this->blockedDomainStorage->addDomain(
			$domain,
			$data['Notes'] ?? '',
			$this->context->getAuthority()
		);

		if ( !$status->isGood() ) {
			foreach ( $status->getMessages() as $msg ) {
				$out->addHTML( Html::errorBox( $this->msg( $msg )->parse() ) );
			}
			return false;
		}

		$out->redirect( $this->getPageTitle()->getLocalURL() );
		return true;
	}

	private function getPageTitle( ?string $subpage = null ): Title {
		$title = $this->rootTitle;
		if ( $subpage !== null ) {
			$title = $title->getSubpage( $subpage );
		}
		Assert::invariant( $title !== null, 'A valid title is expected' );
		return $title;
	}
}
