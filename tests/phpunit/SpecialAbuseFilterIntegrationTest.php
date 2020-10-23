<?php

use MediaWiki\MediaWikiServices;

class SpecialAbuseFilterIntegrationTest extends SpecialPageTestBase {

	/**
	 * @covers SpecialAbuseFilter::instantiateView
	 * @covers SpecialAbuseFilter::__construct
	 * @covers AbuseFilterView::__construct
	 * @covers AbuseFilterViewDiff::__construct
	 * @covers AbuseFilterViewEdit::__construct
	 * @covers AbuseFilterViewExamine::__construct
	 * @covers AbuseFilterViewHistory::__construct
	 * @covers AbuseFilterViewImport::__construct
	 * @covers AbuseFilterViewList::__construct
	 * @covers AbuseFilterViewRevert::__construct
	 * @covers AbuseFilterViewTestBatch::__construct
	 * @covers AbuseFilterViewTools::__construct
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
