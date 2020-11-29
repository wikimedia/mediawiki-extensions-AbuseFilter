<?php

use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewDiff;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewEdit;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewExamine;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewHistory;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewImport;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewList;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewRevert;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewTestBatch;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewTools;
use MediaWiki\MediaWikiServices;

class SpecialAbuseFilterIntegrationTest extends SpecialPageTestBase {

	/**
	 * @covers SpecialAbuseFilter::instantiateView
	 * @covers SpecialAbuseFilter::__construct
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
	 * @dataProvider provideInstantiateView
	 */
	public function testInstantiateView( string $viewClass, array $params = [] ) {
		$sp = $this->newSpecialPage();
		$view = $sp->instantiateView( $viewClass, $params );
		$this->assertInstanceOf( $viewClass, $view );
	}

	public function provideInstantiateView() : array {
		return [
			[ AbuseFilterViewDiff::class ],
			[ AbuseFilterViewEdit::class, [ 'filter' => 1 ] ],
			[ AbuseFilterViewExamine::class ],
			[ AbuseFilterViewHistory::class ],
			[ AbuseFilterViewImport::class ],
			[ AbuseFilterViewList::class ],
			[ AbuseFilterViewRevert::class ],
			[ AbuseFilterViewTestBatch::class ],
			[ AbuseFilterViewTools::class ],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage() : SpecialAbuseFilter {
		$services = MediaWikiServices::getInstance();
		$sp = new SpecialAbuseFilter(
			$services->getObjectFactory()
		);
		$sp->setLinkRenderer(
			$services->getLinkRendererFactory()->create()
		);
		return $sp;
	}

}
