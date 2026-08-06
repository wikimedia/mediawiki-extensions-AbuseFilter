<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Special;

use MediaWiki\Context\RequestContext;
use MediaWiki\Exception\PermissionsError;
use MediaWiki\Extension\AbuseFilter\ServiceNames;
use MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseFilter;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewDiff;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewEdit;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewExamine;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewHistory;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewImport;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewList;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewRevert;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewTestBatch;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewTools;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Specials\SpecialPageTestBase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseFilter
 *
 * Indirectly covers:
 * @covers \MediaWiki\Extension\AbuseFilter\Special\AbuseFilterSpecialPage::__construct
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterView::__construct
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewDiff::__construct
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewEdit::__construct
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewExamine::__construct
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewHistory::__construct
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewImport::__construct
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewList::__construct
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewRevert::__construct
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewTestBatch::__construct
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewTools::__construct
 */
class SpecialAbuseFilterTest extends SpecialPageTestBase {

	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage(): SpecialAbuseFilter {
		$services = $this->getServiceContainer();
		$sp = new SpecialAbuseFilter(
			$services->getService( ServiceNames::PermManager ),
			$services->getObjectFactory()
		);
		$sp->setLinkRenderer(
			$services->getLinkRendererFactory()->create()
		);
		return $sp;
	}

	public function testExecuteAsUnauthorizedUser() {
		$groupPermissions = $this->getConfVar( MainConfigNames::GroupPermissions );
		$groupPermissions['*']['abusefilter-view'] = false;
		$this->overrideConfigValue( MainConfigNames::GroupPermissions, $groupPermissions );

		$this->expectException( PermissionsError::class );
		$this->executeSpecialPage( performer: $this->getTestUser()->getAuthority() );
	}

	public function testExecuteForFilterEditSuccessMessage() {
		$context = RequestContext::getMain();
		$context->setAuthority( $this->getTestSysop()->getAuthority() );
		$context->getRequest()->setSessionData( AbuseFilterViewEdit::EDIT_SUCCESS_SESSION_KEY, [
			'changedFilter' => 1,
			'changeId' => 2,
		] );
		$context->setLanguage( 'qqx' );

		[ $html ] = $this->executeSpecialPage( fullHtml: true, context: $context );

		$this->assertNull(
			$context->getRequest()->getSessionData( AbuseFilterViewEdit::EDIT_SUCCESS_SESSION_KEY ),
			'The success message session data was not cleared after being displayed'
		);
		$this->assertStringContainsString(
			'(abusefilter-edit-done-subtitle)',
			$html,
			'The success message subtitle is not displayed'
		);
		$this->assertStringContainsString(
			'(abusefilter-edit-done: 1, 2, 1)',
			$html,
			'The success message box is not displayed'
		);
	}

	/**
	 * @dataProvider provideInstantiateView
	 */
	public function testInstantiateView( string $viewClass, array $params = [] ) {
		$sp = $this->newSpecialPage();
		$view = $sp->instantiateView( $viewClass, $params );
		$this->assertInstanceOf( $viewClass, $view );
	}

	public static function provideInstantiateView(): array {
		return [
			[ AbuseFilterViewDiff::class ],
			[ AbuseFilterViewEdit::class, [ 'filter' => 1 ] ],
			[ AbuseFilterViewExamine::class ],
			[ AbuseFilterViewHistory::class ],
			[ AbuseFilterViewImport::class ],
			[ AbuseFilterViewList::class ],
			[ AbuseFilterViewRevert::class, [ 'filter' => 1 ] ],
			[ AbuseFilterViewTestBatch::class ],
			[ AbuseFilterViewTools::class ],
		];
	}
}
