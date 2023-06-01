<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */
namespace MediaWiki\Extension\AbuseFilter\Special;

use ErrorPageError;
use HTMLForm;
use IDBAccessObject;
use MediaWiki\Extension\AbuseFilter\BlockedDomainStorage;
use MediaWiki\Utils\UrlUtils;
use PermissionsError;
use SpecialPage;
use Xml;

/**
 * A special page for listing and managing blocked external domains
 *
 * @ingroup SpecialPage
 */
class BlockedExternalDomains extends SpecialPage {
	private BlockedDomainStorage $blockedDomainStorage;
	private UrlUtils $urlUtils;

	public function __construct( BlockedDomainStorage $blockedDomainStorage, UrlUtils $urlUtils ) {
		parent::__construct( 'BlockedExternalDomains' );
		$this->blockedDomainStorage = $blockedDomainStorage;
		$this->urlUtils = $urlUtils;
	}

	/** @inheritDoc */
	public function execute( $par ) {
		if ( !$this->getConfig()->get( 'AbuseFilterEnableBlockedExternalDomain' ) ) {
			throw new ErrorPageError( 'abusefilter-disabled', 'disabledspecialpage-disabled' );
		}
		$this->setHeaders();
		$this->outputHeader();
		$this->addHelpLink( 'Manual:BlockedExternalDomains' );

		$request = $this->getRequest();
		switch ( $par ) {
			case 'remove':
				$this->showRemoveForm( $request->getVal( 'domain' ) );
				break;
			case 'add':
				$this->showAddForm( $request->getVal( 'domain' ) );
				break;
			default:
				$this->showList();
				break;
		}
	}

	private function showList() {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'abusefilter-blocked-domains-title' ) );
		$out->wrapWikiMsg( "$1", 'abusefilter-blocked-domains-intro' );

		// Direct editing of this page is blocked via EditPermissionHandler
		$userCanManage = $this->getAuthority()->isAllowed( 'abusefilter-modify-blocked-external-domains' );

		// Show form to add a blocked domain
		if ( $userCanManage ) {
			$fields = [
				'Domain' => [
					'type' => 'text',
					'label' => $this->msg( 'abusefilter-blocked-domains-domain' )->plain(),
					'required' => true,
				],
				'Notes' => [
					'type' => 'text',
					'maxlength' => 255,
					'label' => $this->msg( 'abusefilter-blocked-domains-notes' )->plain(),
					'size' => 250,
				],
			];

			HTMLForm::factory( 'ooui', $fields, $this->getContext() )
				->setAction( $this->getPageTitle( 'add' )->getLocalURL() )
				->setWrapperLegendMsg( 'abusefilter-blocked-domains-add-heading' )
				->setHeaderHtml( $this->msg( 'abusefilter-blocked-domains-add-explanation' )->parseAsBlock() )
				->setSubmitCallback( [ $this, 'processAddForm' ] )
				->setSubmitTextMsg( 'abusefilter-blocked-domains-add-submit' )
				->show();

			if ( $out->getRedirect() !== '' ) {
				return;
			}
		}

		$res = $this->blockedDomainStorage->load( IDBAccessObject::READ_LATEST );
		if ( !$res->isGood() ) {
			return;
		}

		$content = Xml::tags( 'th', null, $this->msg( 'abusefilter-blocked-domains-domain-header' )->parse() ) .
			Xml::tags( 'th', null, $this->msg( 'abusefilter-blocked-domains-notes-header' )->parse() ) .
			( ( $userCanManage ) ?
				Xml::tags( 'th', [ 'class' => 'unsortable' ],
						   $this->msg( 'abusefilter-blocked-domains-actions-header' )->parse() ) :
				'' );
		$thead = Xml::tags( 'tr', null, $content );

		$tbody = '';

		foreach ( $res->getValue() as $domain ) {
			$tbody .= $this->doDomainRow( $domain, $userCanManage );
		}

		$out->addModuleStyles( [ 'jquery.tablesorter.styles', 'mediawiki.pager.styles' ] );
		$out->addModules( 'jquery.tablesorter' );
		$out->addHTML( Xml::tags(
			'table',
			[ 'class' => 'mw-datatable sortable' ],
			Xml::tags( 'thead', null, $thead ) .
			Xml::tags( 'tbody', null, $tbody )
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
		$newRow = '';
		$newRow .= Xml::tags( 'td', null, Xml::element( 'code', null, $domain['domain'] ) );

		$newRow .= Xml::tags( 'td', null, $this->getOutput()->parseInlineAsInterface( $domain['notes'] ) );

		if ( $showManageActions ) {
			$actionLink = $this->getLinkRenderer()->makeKnownLink(
				$this->getPageTitle( 'remove' ),
				$this->msg( 'abusefilter-blocked-domains-remove' )->text(),
				[],
				[ 'domain' => $domain['domain'] ] );
			$newRow .= Xml::tags( 'td', null, $actionLink );
		}

		return Xml::tags( 'tr', null, $newRow ) . "\n";
	}

	/**
	 * Show form for removing a domain from the blocked list
	 *
	 * @param string $domain
	 * @return void
	 */
	private function showRemoveForm( $domain ) {
		if ( !$this->getAuthority()->isAllowed( 'editsitejson' ) ) {
			throw new PermissionsError( 'editsitejson' );
		}

		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'abusefilter-blocked-domains-remove-title' ) );
		$out->addBacklinkSubtitle( $this->getPageTitle() );

		$preText = $this->msg( 'abusefilter-blocked-domains-remove-explanation-initial', $domain )->parseAsBlock();

		$fields = [
			'Domain' => [
				'type' => 'text',
				'label' => $this->msg( 'abusefilter-blocked-domains-domain' )->plain(),
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

		HTMLForm::factory( 'ooui', $fields, $this->getContext() )
			->setAction( $this->getPageTitle( 'remove' )->getLocalURL() )
			->setSubmitCallback( function ( $data, $form ) {
				return $this->processRemoveForm( $data, $form );
			} )
			->setSubmitTextMsg( 'abusefilter-blocked-domains-remove-submit' )
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
		$domain = $this->validateDomain( $data['Domain'], $out );
		if ( !$domain ) {
			return false;
		}

		$rev = $this->blockedDomainStorage->removeDomain(
			$domain,
			$data['Notes'] ?? '',
			$this->getUser()
		);

		if ( !$rev ) {
			$out->wrapWikiTextAsInterface( 'error', 'Save failed' );
			return false;
		} else {
			$out->redirect( $this->getPageTitle()->getLocalURL() );
			return true;
		}
	}

	/**
	 * Validate if the entered domain is valid or not
	 *
	 * @param string $domain the domain such as foo.wikipedia.org
	 * @param \OutputPage $out
	 * @return bool|string false if the domain is invalid, the parsed domain otherwise
	 */
	private function validateDomain( $domain, $out ) {
		$domain = trim( $domain ?? '' );
		if ( strpos( $domain, '//' ) === false ) {
			$domain = 'https://' . $domain;
		}

		$parsedUrl = $this->urlUtils->parse( $domain );
		if ( !$parsedUrl ) {
			$out->wrapWikiTextAsInterface( 'error', 'Invalid URL' );
			return false;
		}
		// ParseUrl returns a valid URL for "foo"
		if ( strpos( $parsedUrl['host'], '.' ) === false ) {
			$out->wrapWikiTextAsInterface( 'error', 'Invalid URL' );
			return false;
		}
		return $parsedUrl['host'];
	}

	/**
	 * Show form for adding a domain to the blocked list
	 *
	 * @param string $domain
	 * @return void
	 */
	private function showAddForm( $domain ) {
		if ( !$this->getAuthority()->isAllowed( 'editsitejson' ) ) {
			throw new PermissionsError( 'editsitejson' );
		}

		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( "abusefilter-blocked-domains-add-heading" ) );
		$out->addBacklinkSubtitle( $this->getPageTitle() );

		$preText = $this->msg( "abusefilter-blocked-domains-add-explanation", $domain )->parseAsBlock();

		$fields = [
			'Domain' => [
				'type' => 'text',
				'label' => $this->msg( 'abusefilter-blocked-domains-domain' )->plain(),
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

		HTMLForm::factory( 'ooui', $fields, $this->getContext() )
			->setAction( $this->getPageTitle( 'add' )->getLocalURL() )
			->setSubmitCallback( function ( $data, $form ) {
				return $this->processAddForm( $data, $form );
			} )
			->setSubmitTextMsg( "abusefilter-blocked-domains-add-submit" )
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

		$domain = $this->validateDomain( $data['Domain'], $out );
		if ( !$domain ) {
			return false;
		}
		$rev = $this->blockedDomainStorage->addDomain(
			$domain,
			$data['Notes'] ?? '',
			$this->getUser()
		);

		if ( !$rev ) {
			$out->wrapWikiTextAsInterface( 'error', 'Save failed' );
			return false;
		} else {
			$out->redirect( $this->getPageTitle()->getLocalURL() );
			return true;
		}
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'spam';
	}

	public function isListed() {
		return $this->getConfig()->get( 'AbuseFilterEnableBlockedExternalDomain' );
	}
}
