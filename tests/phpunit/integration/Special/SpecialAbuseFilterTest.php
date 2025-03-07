<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\Special;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Filter\Filter;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Filter\LastEditInfo;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Extension\AbuseFilter\Filter\Specs;
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
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use SpecialPageTestBase;

/**
 * @covers \MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseFilter
 * @covers \MediaWiki\Extension\AbuseFilter\Special\AbuseFilterSpecialPage
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterView
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewDiff
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewEdit
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewExamine
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewHistory
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewImport
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewList
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewRevert
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewTestBatch
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewTools
 * @group Database
 */
class SpecialAbuseFilterTest extends SpecialPageTestBase {
	use MockAuthorityTrait;

	private Authority $authorityCannotUseProtectedVar;

	private Authority $authorityCanUseProtectedVar;

	protected function setUp(): void {
		parent::setUp();

		// Create an authority who can see private filters but not protected variables
		$this->authorityCannotUseProtectedVar = $this->mockUserAuthorityWithPermissions(
			$this->getTestUser()->getUserIdentity(),
			[
				'abusefilter-log-private',
				'abusefilter-view-private',
				'abusefilter-modify',
				'abusefilter-log-detail',
			]
		);

		// Create an authority who can see private and protected variables
		$this->authorityCanUseProtectedVar = $this->mockUserAuthorityWithPermissions(
			$this->getTestUser()->getUserIdentity(),
			[
				'abusefilter-access-protected-vars',
				'abusefilter-log-private',
				'abusefilter-view-private',
				'abusefilter-modify',
				'abusefilter-log-detail',
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function addDBDataOnce() {
		$filterStore = AbuseFilterServices::getFilterStore();
		$performer = $this->getTestSysop()->getUserIdentity();
		$authority = $this->mockUserAuthorityWithPermissions(
			$performer,
			[
				'abusefilter-access-protected-vars',
				'abusefilter-log-private',
				'abusefilter-view-private',
				'abusefilter-modify',
			]
		);

		// Create a test filter with two revisions that is protected
		$firstFilterRevision = $this->getFilterFromSpecs( [
			'id' => '1',
			'rules' => 'user_unnamed_ip = "1.2.3.5"',
			'name' => 'Filter with protected variables',
			'hidden' => Flags::FILTER_USES_PROTECTED_VARS,
			'userIdentity' => $performer,
			'timestamp' => $this->getDb()->timestamp( '20190826000000' ),
			'enabled' => 1,
			'comments' => '',
			'hit_count' => 0,
			'throttled' => 0,
			'deleted' => 0,
			'actions' => [],
			'global' => 0,
			'group' => 'default',
		] );
		$this->assertStatusGood( $filterStore->saveFilter(
			$authority, null, $firstFilterRevision, MutableFilter::newDefault()
		) );
		$this->assertStatusGood( $filterStore->saveFilter(
			$authority, 1,
			$this->getFilterFromSpecs( [
				'id' => '1',
				'rules' => 'user_unnamed_ip = "1.2.3.4"',
				'name' => 'Filter with protected variables',
				'hidden' => Flags::FILTER_USES_PROTECTED_VARS,
				'userIdentity' => $performer,
				'timestamp' => $this->getDb()->timestamp( '20190827000000' ),
				'enabled' => 1,
				'comments' => '',
				'hit_count' => 1,
				'throttled' => 0,
				'deleted' => 0,
				'actions' => [],
				'global' => 0,
				'group' => 'default',
			] ),
			$firstFilterRevision
		) );

		// Create a second filter which is not public
		$this->assertStatusGood( $filterStore->saveFilter(
			$authority, null,
			$this->getFilterFromSpecs( [
				'id' => '2',
				'rules' => 'user_name = "1.2.3.4"',
				'name' => 'Filter without protected variables',
				'hidden' => Flags::FILTER_PUBLIC,
				'userIdentity' => $performer,
				'timestamp' => '20000101000000',
				'enabled' => 1,
				'comments' => '',
				'hit_count' => 0,
				'throttled' => 0,
				'deleted' => 0,
				'actions' => [],
				'global' => 0,
				'group' => 'default',
			] ),
			MutableFilter::newDefault()
		) );
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
			[ AbuseFilterViewRevert::class ],
			[ AbuseFilterViewTestBatch::class ],
			[ AbuseFilterViewTools::class ],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function newSpecialPage(): SpecialAbuseFilter {
		$services = MediaWikiServices::getInstance();
		$sp = new SpecialAbuseFilter(
			$services->getService( AbuseFilterPermissionManager::SERVICE_NAME ),
			$services->getObjectFactory()
		);
		$sp->setLinkRenderer(
			$services->getLinkRendererFactory()->create()
		);
		return $sp;
	}

	/**
	 * Adapted from FilterStoreTest->getFilterFromSpecs()
	 *
	 * @param array $filterSpecs
	 * @param array $actions
	 * @return Filter
	 */
	private function getFilterFromSpecs( array $filterSpecs, array $actions = [] ): Filter {
		$filterSpecs['timestamp'] = $this->getDb()->timestamp( $filterSpecs['timestamp'] );
		return new Filter(
			new Specs(
				$filterSpecs['rules'],
				$filterSpecs['comments'],
				$filterSpecs['name'],
				array_keys( $filterSpecs['actions'] ),
				$filterSpecs['group']
			),
			new Flags(
				$filterSpecs['enabled'],
				$filterSpecs['deleted'],
				$filterSpecs['hidden'],
				$filterSpecs['global']
			),
			$actions,
			new LastEditInfo(
				$filterSpecs['userIdentity']->getId(),
				$filterSpecs['userIdentity']->getName(),
				$filterSpecs['timestamp']
			),
			$filterSpecs['id'],
			$filterSpecs['hit_count'],
			$filterSpecs['throttled']
		);
	}

	public function testViewEditTokenMismatch() {
		[ $html, ] = $this->executeSpecialPage(
			'new',
			new FauxRequest(
				[
					'wpFilterDescription' => 'Test filter',
					'wpFilterRules' => 'user_name = "1.2.3.4"',
					'wpFilterNotes' => '',
				],
				// This was posted
				true,
			),
			null,
			$this->authorityCannotUseProtectedVar
		);

		$this->assertStringContainsString(
			'abusefilter-edit-token-not-match',
			$html,
			'The token mismatch warning message was not present.'
		);
	}

	public function testViewEditUnrecoverableError() {
		[ $html, $response ] = $this->executeSpecialPage(
			'new',
			new FauxRequest(
				[
					'wpFilterDescription' => '',
					'wpFilterRules' => 'user_name = "1.2.3.4"',
					'wpFilterNotes' => '',
				],
				// This was posted
				true,
			)
		);

		$this->assertStringContainsString(
			'abusefilter-edit-notallowed',
			$html,
			'The permission error message was not present.'
		);
	}

	public function testViewEditForInvalidImport() {
		[ $html, ] = $this->executeSpecialPage(
			'new',
			new FauxRequest( [ 'wpImportText' => 'abc' ], true ),
			null,
			$this->authorityCannotUseProtectedVar
		);

		$this->assertStringContainsString(
			'(abusefilter-import-invalid-data',
			$html,
			'An unknown filter ID should cause an error message.'
		);
		$this->assertStringContainsString(
			'(abusefilter-return',
			$html,
			'Button to return the filter management was missing.'
		);
	}

	/** @dataProvider provideViewEditForBadFilter */
	public function testViewEditForBadFilter( $subPage ) {
		[ $html, ] = $this->executeSpecialPage(
			$subPage, new FauxRequest(), null, $this->authorityCannotUseProtectedVar
		);

		$this->assertStringContainsString(
			'(abusefilter-edit-badfilter',
			$html,
			'An unknown filter ID should cause an error message.'
		);
		$this->assertStringContainsString(
			'(abusefilter-return',
			$html,
			'Button to return the filter management was missing.'
		);
	}

	public static function provideViewEditForBadFilter() {
		return [
			'Unknown filter ID' => [ '12345' ],
			'Unknown history ID for existing filter' => [ 'history/1/item/123456' ],
		];
	}

	public function testViewEditProtectedVarsCheckboxPresentForProtectedFilter() {
		// Xml::buildForm uses the global wfMessage which means we need to set
		// the language for the user globally too.
		$this->setUserLang( 'qqx' );

		[ $html, ] = $this->executeSpecialPage(
			'1',
			new FauxRequest(),
			null,
			$this->authorityCanUseProtectedVar
		);

		$this->assertStringNotContainsString(
			'abusefilter-edit-protected-help-message',
			$html,
			'The enabled checkbox to protect the filter was not present.'
		);
		$this->assertStringContainsString(
			'abusefilter-edit-protected-variable-already-protected',
			$html,
			'The disabled checkbox explaining that the filter is protected was not present.'
		);

		// Also check that the filter hit count is present and as expected for the protected filter.
		$this->assertStringContainsString( '(abusefilter-edit-hitcount', $html );
		$this->assertStringContainsString( '(abusefilter-hitcount: 1', $html );
	}

	public function testViewEditForProtectedFilterWhenUserLacksAuthority() {
		[ $html, ] = $this->executeSpecialPage(
			'1',
			new FauxRequest(),
			null,
			$this->authorityCannotUseProtectedVar
		);

		$this->assertStringContainsString(
			'(abusefilter-edit-denied-protected-vars',
			$html,
			'The protected filter permission error was not present.'
		);
	}

	public function testViewEditProtectedVarsCheckboxAbsentForUnprotectedFilter() {
		[ $html, ] = $this->executeSpecialPage(
			'2',
			new FauxRequest(),
			null,
			$this->authorityCanUseProtectedVar
		);
		$this->assertStringNotContainsString(
			'abusefilter-edit-protected',
			$html,
			'Elements related to protected filters were present.'
		);
	}

	public function testViewEditProtectedVarsSave() {
		$authority = $this->authorityCanUseProtectedVar;
		$user = $this->getServiceContainer()->getUserFactory()->newFromUserIdentity( $authority->getUser() );

		// Set the abuse filter editor to the context user, so that the edit token matches
		RequestContext::getMain()->getRequest()->getSession()->setUser( $user );

		[ $html, ] = $this->executeSpecialPage(
			'new',
			new FauxRequest(
				[
					'wpFilterDescription' => 'Uses protected variable',
					'wpFilterRules' => 'user_unnamed_ip = "1.2.3.4"',
					'wpFilterNotes' => '',
					'wpEditToken' => $user->getEditToken( [ 'abusefilter', 'new' ] ),
				],
				// This was posted
				true,
			),
			null,
			$authority
		);

		$this->assertStringContainsString(
			'abusefilter-edit-protected-variable-not-protected',
			$html,
			'The error message about protecting the filter was not present.'
		);

		$this->assertStringContainsString(
			'abusefilter-edit-protected-help-message',
			$html,
			'The enabled checkbox to protect the filter was not present.'
		);
	}

	public function testViewEditProtectedVarsSaveSuccess() {
		$authority = $this->authorityCanUseProtectedVar;
		$user = $this->getServiceContainer()->getUserFactory()->newFromUserIdentity( $authority->getUser() );

		// Set the abuse filter editor to the context user, so that the edit token matches
		RequestContext::getMain()->getRequest()->getSession()->setUser( $user );

		[ $html, $response ] = $this->executeSpecialPage(
			'new',
			new FauxRequest(
				[
					'wpFilterDescription' => 'Uses protected variable',
					'wpFilterRules' => 'user_unnamed_ip = "1.2.3.4"',
					'wpFilterProtected' => '1',
					'wpFilterNotes' => '',
					'wpEditToken' => $user->getEditToken( [ 'abusefilter', 'new' ] ),
				],
				// This was posted
				true,
			),
			null,
			$authority
		);

		// On saving successfully, the page redirects
		$this->assertSame( '', $html );
		$this->assertStringContainsString( 'result=success', $response->getHeader( 'location' ) );
	}

	public function testViewTestBatchProtectedVarsFilterVisibility() {
		// Assert that the user who cannot see protected variables cannot load the filter
		[ $html, ] = $this->executeSpecialPage(
			'test/1',
			new FauxRequest(),
			null,
			$this->authorityCannotUseProtectedVar
		);
		$this->assertStringNotContainsString( '1.2.3.4', $html );

		// Assert that the user who can see protected variables can load the filter
		[ $html, ] = $this->executeSpecialPage(
			'test/1',
			new FauxRequest(),
			null,
			$this->authorityCanUseProtectedVar
		);
		$this->assertStringContainsString( '1.2.3.4', $html );
	}

	public function testViewListProtectedVarsFilterVisibility() {
		// Ensure that even if the user cannot view the details of a protected filter
		// they can still see the filter in the filter list
		[ $html, ] = $this->executeSpecialPage(
			'',
			new FauxRequest(),
			null,
			$this->authorityCannotUseProtectedVar
		);
		$this->assertStringContainsString( 'abusefilter-protected', $html );
	}

	public function testViewListWithSearchQueryProtectedVarsFilterVisibility() {
		// Stub out a page with query results for a filter that uses protected variables
		// &sort=af_id&limit=50&asc=&desc=1&deletedfilters=hide&querypattern=user_unnamed_ip&searchoption=LIKE
		$requestWithProtectedVar = new FauxRequest( [
			'sort' => 'af_id',
			'limit' => 50,
			'asc' => '',
			'desc' => 1,
			'deletedfilters' => 'hide',
			'querypattern' => 'user_unnamed_ip',
			'searchoption' => 'LIKE',
			'rulescope' => 'all',
			'furtheroptions' => []
		] );

		// Assert that the user who cannot see protected variables sees no filters when searching
		[ $html, ] = $this->executeSpecialPage(
			'',
			$requestWithProtectedVar,
			null,
			$this->authorityCannotUseProtectedVar
		);
		$this->assertStringContainsString( 'table_pager_empty', $html );

		// Assert that the user who can see protected variables sees the filter from the db
		[ $html, ] = $this->executeSpecialPage(
			'',
			$requestWithProtectedVar,
			null,
			$this->authorityCanUseProtectedVar
		);
		$this->assertStringContainsString( '1.2.3.4', $html );
	}

	public function testViewHistoryForProtectedFilterWhenUserLacksAuthority() {
		[ $html, ] = $this->executeSpecialPage(
			'history/1',
			new FauxRequest(),
			null,
			$this->authorityCannotUseProtectedVar
		);

		$this->assertStringContainsString(
			'(abusefilter-history-error-protected)',
			$html,
			'The protected filter permission error was not present.'
		);
		$this->assertStringNotContainsString(
			'abusefilter-history-select-user',
			$html,
			'The filter history should not be shown if the user cannot see the filter.'
		);
	}

	/** @dataProvider provideViewDiffWhenDiffInvalid */
	public function testViewDiffWhenDiffInvalid( $subPage ) {
		[ $html, ] = $this->executeSpecialPage(
			$subPage,
			new FauxRequest(),
			null,
			$this->authorityCannotUseProtectedVar
		);

		$this->assertStringContainsString( '(abusefilter-diff-invalid)', $html );
	}

	public static function provideViewDiffWhenDiffInvalid() {
		return [
			'Filter ID is not numeric' => [ 'history/abc/diff/prev/1' ],
			'Version IDs do not exist' => [ 'history/1/diff/prev/123456' ],
		];
	}

	public function testViewDiffForProtectedFilterWhenUserLacksAuthority() {
		[ $html, ] = $this->executeSpecialPage(
			'history/1/diff/next/1',
			new FauxRequest(),
			null,
			$this->authorityCannotUseProtectedVar
		);

		$this->assertStringContainsString(
			'(abusefilter-history-error-protected)',
			$html,
			'The protected filter permission error was not present.'
		);
	}
}
