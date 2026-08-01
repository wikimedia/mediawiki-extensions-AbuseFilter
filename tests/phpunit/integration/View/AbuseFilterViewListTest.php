<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\View;

use MediaWiki\Extension\AbuseFilter\ServiceNames;
use MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseFilter;
use MediaWiki\Extension\AbuseFilter\Tests\Integration\ProtectedVarsTestTrait;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Specials\SpecialPageTestBase;
use Wikimedia\Parsoid\DOM\Document;
use Wikimedia\Parsoid\Ext\DOMUtils;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewList
 * @covers \MediaWiki\Extension\AbuseFilter\Pager\AbuseFilterPager
 *
 * Indirectly covers:
 * @covers \MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterView
 */
class AbuseFilterViewListTest extends SpecialPageTestBase {
	use ProtectedVarsTestTrait;

	private Authority $authorityCannotUseProtectedVar;
	private Authority $authorityCanUseProtectedVar;

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

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clearProtectedVarRelatedHooks();
		$this->authorityCannotUseProtectedVar = $this->mockFilterEditorAuthorityWithoutProtectedVarsAccess();
		$this->authorityCanUseProtectedVar = $this->mockFilterEditorAuthorityWithProtectedVarsAccess();
	}

	/**
	 * @inheritDoc
	 */
	public function addDBDataOnce() {
		$this->createFiltersWithProtectedVariables();
	}

	/**
	 * Common test code used by tests which load the list of AbuseFilters,
	 * used to verify that the headings on the table of AbuseFilters are
	 * as expected.
	 *
	 * @param Document $doc The HTML document of the special page
	 * @param Authority $authority The Authority who viewed the special page
	 * @param bool $searchModeEnabled Whether the special page request included searching
	 *   for filters with a specific substring in their pattern.
	 */
	private function verifyViewListHeadingsPresent(
		Document $doc, Authority $authority, bool $searchModeEnabled = false
	) {
		$tableHtml = $this->assertSelectorMatchesOneElementInNode( $doc, '.mw-datatable', true );

		$expectedTableHeadings = [
			'abusefilter-list-id',
			'abusefilter-list-public',
			'abusefilter-list-consequences',
			'abusefilter-list-status',
			'abusefilter-list-lastmodified',
			'abusefilter-list-visibility',
		];
		$expectedTableHeadingsToBeMissing = [];

		if ( $authority->isAllowed( 'abusefilter-log-detail' ) ) {
			$expectedTableHeadings[] = 'abusefilter-list-hitcount';
		} else {
			$expectedTableHeadingsToBeMissing[] = 'abusefilter-list-hitcount';
		}

		$canViewPrivateFilters = $this->getServiceContainer()->get( ServiceNames::PermManager )
			->canViewPrivateFilters( $authority );
		if ( $canViewPrivateFilters && $searchModeEnabled ) {
			$expectedTableHeadings[] = 'abusefilter-list-pattern';
		} else {
			$expectedTableHeadingsToBeMissing[] = 'abusefilter-list-pattern';
		}

		foreach ( $expectedTableHeadings as $heading ) {
			$this->assertStringContainsString( $heading, $tableHtml );
		}

		foreach ( $expectedTableHeadingsToBeMissing as $heading ) {
			$this->assertStringNotContainsString( $heading, $tableHtml );
		}
	}

	public function testViewListWhenLimitIsOne() {
		[ $html, ] = $this->executeSpecialPage(
			'',
			new FauxRequest( [ 'limit' => 1 ] ),
			null,
			$this->authorityCanUseProtectedVar
		);
		$htmlDoc = DOMUtils::parseHTML( $html );

		// Verify the structure of one row in the table, ensuring the correct flags are set.
		$this->verifyViewListHeadingsPresent( $htmlDoc, $this->authorityCanUseProtectedVar );

		$this->assertStringContainsString(
			'AbuseFilter/1',
			$this->assertSelectorMatchesOneElementInNode( $htmlDoc, '.TablePager_col_af_id', true ),
			'Missing the URL to the filter'
		);
		$this->assertStringContainsString(
			'Filter with protected variables',
			$this->assertSelectorMatchesOneElementInNode( $htmlDoc, '.TablePager_col_af_public_comments', true )
		);

		$cellClassesToExpectedText = [
			'TablePager_col_af_actions' => '(abusefilter-action-tags)',
			'TablePager_col_af_enabled' => '(abusefilter-enabled)',
			'TablePager_col_af_hidden' => '(abusefilter-protected)',
		];
		foreach ( $cellClassesToExpectedText as $class => $expectedText ) {
			$this->assertSame(
				"<td class=\"$class\">" . $expectedText . '</td>',
				$this->assertSelectorMatchesOneElementInNode( $htmlDoc, '.' . $class, true )
			);
		}

		$this->assertStringContainsString(
			'abusefilter-hitcount: 1',
			$this->assertSelectorMatchesOneElementInNode(
				$htmlDoc,
				'.TablePager_col_af_hit_count',
				true
			)
		);

		$timestampCellHtml = $this->assertSelectorMatchesOneElementInNode(
			$htmlDoc,
			'.TablePager_col_af_timestamp',
			true
		);
		$this->assertStringContainsString( 'abusefilter-edit-lastmod-text', $timestampCellHtml );
		$this->assertStringContainsString( 'UTSysop', $timestampCellHtml, 'Missing last editor of filter' );
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
		$this->verifyViewListHeadingsPresent( DOMUtils::parseHTML( $html ), $this->authorityCannotUseProtectedVar );
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
			'querypattern' => 'user_unnamed_ip = "1',
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
		$htmlDoc = DOMUtils::parseHTML( $html );
		$this->assertStringContainsString( 'table_pager_empty', $html );
		$this->verifyViewListHeadingsPresent( $htmlDoc, $this->authorityCannotUseProtectedVar, true );

		// Assert that the user who can see protected variables sees the filter from the db
		[ $html, ] = $this->executeSpecialPage(
			'',
			$requestWithProtectedVar,
			null,
			$this->authorityCanUseProtectedVar
		);
		$this->assertStringContainsString( 'Filter with protected variables', $html );
		$this->verifyViewListHeadingsPresent( $htmlDoc, $this->authorityCanUseProtectedVar, true );

		// Check that the search found one result and that the pattern is bolded to show the text match
		$patternCellHtml = $this->assertSelectorMatchesOneElement( $html, '.TablePager_col_af_pattern' );
		$this->assertSame(
			'<td class="TablePager_col_af_pattern"><b>user_unnamed_ip = "1</b>.2.3.4"</td>',
			$patternCellHtml
		);
	}

	public function testViewListWithSearchQueryHonoursSortColumn() {
		$request = new FauxRequest( [
			'sort' => 'af_hit_count',
			'limit' => 50,
			'asc' => 1,
			'desc' => '',
			'deletedfilters' => 'hide',
			'querypattern' => '1.2.3.4',
			'searchoption' => 'LIKE',
			'rulescope' => 'all',
			'furtheroptions' => []
		] );

		[ $html, ] = $this->executeSpecialPage( '', $request, null, $this->authorityCanUseProtectedVar );

		$positionOfFilterWithoutProtectedVars = strpos( $html, 'Filter without protected variables' );
		$positionOfFilterWithProtectedVars = strpos( $html, 'Filter with protected variables' );

		$this->assertLessThan(
			$positionOfFilterWithProtectedVars,
			$positionOfFilterWithoutProtectedVars,
			'Filter without protected variables (0 hits) should appear before ' .
			'Filter with protected variables (1 hit) when sorting by af_hit_count ascending'
		);
	}
}
