<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\View;

use Generator;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionStatus;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Filter\Filter;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Filter\MutableFilter;
use MediaWiki\Extension\AbuseFilter\ServiceNames;
use MediaWiki\Extension\AbuseFilter\Special\SpecialAbuseFilter;
use MediaWiki\Extension\AbuseFilter\Tests\Integration\ProtectedVarsTestTrait;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewEdit;
use MediaWiki\Language\RawMessage;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Specials\SpecialPageTestBase;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use Wikimedia\Parsoid\Ext\DOMUtils;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewEdit
 *
 * Indirectly covers:
 * @covers \MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager
 * @covers \MediaWiki\Extension\AbuseFilter\View\AbuseFilterView
 */
class AbuseFilterViewEditTest extends SpecialPageTestBase {
	use MockAuthorityTrait;
	use ProtectedVarsTestTrait;

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

	private function enableAllFilterActions(): void {
		$this->overrideConfigValue(
			'AbuseFilterActions',
			[
				'throttle' => true,
				'warn' => true,
				'disallow' => true,
				'blockautopromote' => true,
				'block' => true,
				'rangeblock' => true,
				'degroup' => true,
				'tag' => true,
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->enableAllFilterActions();
		$this->overrideConfigValues( [
			'AbuseFilterActionRestrictions' => [
				'throttle' => false,
				'warn' => false,
				'disallow' => false,
				'blockautopromote' => true,
				'block' => true,
				'rangeblock' => true,
				'degroup' => true,
				'tag' => false,
			],
		] );
		$this->clearProtectedVarRelatedHooks();
	}

	/**
	 * @inheritDoc
	 */
	public function addDBDataOnce() {
		$this->enableAllFilterActions();
		$this->createFiltersWithProtectedVariables();

		$filterStore = AbuseFilterServices::getFilterStore();
		$performer = $this->getTestSysop()->getAuthority();
		$authority = new UltimateAuthority( $performer->getUser() );

		// Create a private filter #3
		$this->assertStatusGood( $filterStore->saveFilter(
			$authority,
			null,
			$this->getFilterFromSpecs( [
				'id' => '3',
				'name' => 'Private filter',
				'rules' => '1 = 0',
				'privacy' => Flags::FILTER_HIDDEN,
			] ),
			MutableFilter::newDefault()
		) );

		// Create a throttled filter #4
		$this->assertStatusGood( $filterStore->saveFilter(
			$authority,
			null,
			$this->getSpecsForThrottledFilter( [] ),
			MutableFilter::newDefault()
		) );

		// Create a suppressed filter #5
		$this->assertStatusGood( $filterStore->saveFilter(
			$authority,
			null,
			$this->getFilterFromSpecs( [
				'id' => '5',
				'name' => 'Suppressed filter',
				'rules' => '1 = 0',
				'privacy' => Flags::FILTER_SUPPRESSED,
			] ),
			MutableFilter::newDefault()
		) );

		// Create a public filter #6 where the first revision has no restricted
		// actions enabled and the second revision has one restricted action enabled
		$restrictedFilterFirstRev = $this->getFilterFromSpecs( [
			'id' => '6',
			'name' => 'Filter to be converted',
			'rules' => '1 = 0',
			'privacy' => Flags::FILTER_PUBLIC,
		] );
		$this->assertStatusGood( $filterStore->saveFilter(
			$authority,
			null,
			$restrictedFilterFirstRev,
			MutableFilter::newDefault()
		) );
		$this->assertStatusGood( $filterStore->saveFilter(
			$authority,
			6,
			$this->getFilterFromSpecs( [
				'id' => '6',
				'name' => 'Filter with restricted actions',
				'rules' => '1 = 0',
				'privacy' => Flags::FILTER_PUBLIC,
				'actions' => [
					'disallow' => [ 'abusefilter-disallowed' ],
					'blockautopromote' => [],
				],
			] ),
			$restrictedFilterFirstRev
		) );
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
			$this->getTestSysop()->getAuthority()
		);

		$this->assertStringContainsString(
			'abusefilter-edit-token-not-match',
			$html,
			'The token mismatch warning message was not present.'
		);
	}

	/**
	 * @dataProvider provideViewEditMakePublic
	 */
	public function testViewEditMakePublic( int $isNewFilterHidden ) {
		$request = new FauxRequest( [
			// Avoid the abusefilter-edit-missingfields error (see FilterValidator::checkRequiredFields)
			'wpFilterDescription' => 'Hidden filter',
			'wpFilterRules' => '1 = 0',
		], true );
		if ( $isNewFilterHidden ) {
			// Checkbox checked: keep the filter private
			$request->setVal( 'wpFilterHidden', 1 );
		}

		// Make sure wpEditToken is set, because wpMakePublic is evaluated after token mismatches
		$context = RequestContext::getMain();
		$context->setAuthority( $this->getTestSysop()->getAuthority() );
		$token = $context->getCsrfTokenSet()->getToken( [ 'abusefilter', '3' ] )->toString();
		$request->setVal( 'wpEditToken', $token );

		[ $html ] = $this->executeSpecialPage( '3', $request );

		$msgFragment = $isNewFilterHidden ? 'shown unexpectedly' : 'not shown';
		$this->assertSame(
			!$isNewFilterHidden,
			str_contains( $html, '(abusefilter-edit-makepublic)' ),
			"The warning for making a private filter public was $msgFragment"
		);
	}

	public static function provideViewEditMakePublic() {
		return [
			'Keeping a private filter private' => [ 1 ],
			'Making a private filter public' => [ 0 ]
		];
	}

	public function testViewEditKeepsBlockDurationsWithExtraParameter() {
		// T394592: the editing form used the number of stored block parameters to decide how to
		// read them, so a filter carrying an extra parameter fell through to the site defaults.
		// Opening such a filter showed the wrong durations, and saving would have persisted them.
		$performer = $this->getTestSysop()->getAuthority();
		$status = AbuseFilterServices::getFilterStore()->saveFilter(
			$performer,
			null,
			$this->getFilterFromSpecs( [
				// Ignored on creation, since saveFilter() is passed null, but the trait needs the key.
				'id' => '4',
				'name' => 'Filter with a block',
				'rules' => '1 = 0',
				'actions' => [ 'block' => [ 'blocktalk', '3 hours', '9 days', 'autoblock' ] ],
			] ),
			MutableFilter::newDefault()
		);
		$this->assertStatusGood( $status );
		[ $filterId ] = $status->getValue();

		[ $html ] = $this->executeSpecialPage( (string)$filterId, performer: $performer );

		// The durations themselves cannot be asserted here: the test runs in qqx, so the
		// dropdown options come from the unexpanded ipboptions message. The talk page checkbox
		// is filled in by the same parsing, so assert on that instead.
		$this->assertSelectorMatchesOneElement( $html, 'input[name="wpFilterBlockTalk"][checked]' );
	}

	public function testViewEditUnrecoverableError() {
		[ $html, ] = $this->executeSpecialPage(
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

	/**
	 * @dataProvider provideViewEditForBadFilter
	 */
	public function testViewEditForBadFilter( string $subPage ) {
		[ $html, ] = $this->executeSpecialPage(
			$subPage, performer: $this->getTestSysop()->getAuthority()
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
		[ $html, ] = $this->executeSpecialPage(
			'1',
			performer: $this->getTestSysop()->getAuthority()
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
			performer:  $this->mockFilterEditorAuthorityWithoutProtectedVarsAccess()
		);

		$this->assertStringContainsString(
			'(abusefilter-edit-denied-protected-vars-because-of-permission: ' .
				'(action-abusefilter-access-protected-vars))',
			$html,
			'The protected filter permission error was not present.'
		);
	}

	public function testViewEditForProtectedFilterWhenHookPreventsAccess() {
		$this->setTemporaryHook(
			'AbuseFilterCanViewProtectedVariables',
			static function ( $performer, $variables, AbuseFilterPermissionStatus $returnStatus ) {
				$returnStatus->fatal( new RawMessage( 'Testing-custom-message-for-abuse-filter' ) );
			}
		);

		[ $html, ] = $this->executeSpecialPage(
			'1',
			performer: $this->getTestSysop()->getAuthority()
		);

		$this->assertStringContainsString(
			'(abusefilter-edit-denied-protected-vars:',
			$html,
			'The protected filter access error was not present.'
		);
		$this->assertStringContainsString( 'Testing-custom-message-for-abuse-filter', $html );
	}

	public function testViewEditProtectedVarsCheckboxAbsentForUnprotectedFilter() {
		[ $html, ] = $this->executeSpecialPage(
			'2',
			performer: $this->getTestSysop()->getAuthority()
		);
		$this->assertStringNotContainsString(
			'abusefilter-edit-protected',
			$html,
			'Elements related to protected filters were present.'
		);
	}

	public function testViewEditProtectedVarsSave() {
		$authority = $this->getTestSysop()->getAuthority();
		$user = $this->getServiceContainer()->getUserFactory()->newFromUserIdentity( $authority->getUser() );

		// Set the abuse filter editor to the context user, so that the edit token matches
		RequestContext::getMain()->getRequest()->getSession()->setUser( $user );

		[ $html, ] = $this->executeSpecialPage(
			'new',
			new FauxRequest(
				[
					'wpFilterDescription' => 'Uses protected variable',
					'wpFilterRules' => 'user_unnamed_ip = "4.2.3.4"',
					'wpFilterNotes' => '',
					'wpEditToken' => $user->getEditToken( [ 'abusefilter', 'new' ] ),
				],
				// This was posted
				true,
				RequestContext::getMain()->getRequest()->getSession()
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
		$authority = $this->getTestSysop()->getAuthority();
		$user = $this->getServiceContainer()->getUserFactory()->newFromUserIdentity( $authority->getUser() );

		// Set the abuse filter editor to the context user, so that the edit token matches
		$session = RequestContext::getMain()->getRequest()->getSession();
		$session->setUser( $user );

		[ $html, $response ] = $this->executeSpecialPage(
			'new',
			new FauxRequest(
				[
					'wpFilterDescription' => 'Uses protected variable',
					'wpFilterRules' => 'user_unnamed_ip = "4.2.3.4"',
					'wpFilterProtected' => '1',
					'wpFilterNotes' => '',
					'wpEditToken' => $user->getEditToken( [ 'abusefilter', 'new' ] ),
				],
				// This was posted
				true,
				$session
			),
			null,
			$authority
		);

		// On saving successfully, the page redirects
		$this->assertSame( '', $html );

		$builder = $this->getDb()->newSelectQueryBuilder();
		$filterId = $builder
			->field( 'af_id' )
			->from( 'abuse_filter' )
			->orderBy( 'af_id', $builder::SORT_DESC )
			->caller( __METHOD__ )
			->fetchField();
		$this->assertNotNull( $filterId );

		$successData = $session->get( AbuseFilterViewEdit::EDIT_SUCCESS_SESSION_KEY );
		$this->assertSame( $successData['changedFilter'], (int)$filterId );
		$this->assertIsInt( $successData['changeId'] );
	}

	/**
	 * @dataProvider provideBuildFilterEditorThrottleWarning
	 */
	public function testBuildFilterEditorThrottleWarning( array $rawActions, string $expected ): void {
		$performer = $this->getTestSysop()->getAuthority();
		$filterLookup = AbuseFilterServices::getFilterLookup();
		$filterId = 4;

		// Update the throttled filter's actions
		$this->assertStatusGood( AbuseFilterServices::getFilterStore()->saveFilter(
			new UltimateAuthority( $performer->getUser() ),
			$filterId,
			$this->getSpecsForThrottledFilter( $rawActions ),
			$filterLookup->getFilter( $filterId, false )
		) );

		// ::saveFilter resets af_throttled to 0
		$this->getDb()->newUpdateQueryBuilder()
			->table( 'abuse_filter' )
			->set( [ 'af_throttled' => 1 ] )
			->where( [ 'af_id' => $filterId ] )
			->caller( __METHOD__ )
			->execute();

		$filterObj = $filterLookup->getFilter( $filterId, false, IDBAccessObject::READ_LATEST );
		$this->assertSame( $rawActions, $filterObj->getActions() );
		$this->assertTrue( $filterObj->isThrottled() );

		[ $html ] = $this->executeSpecialPage( (string)$filterId, performer: $performer );

		$this->assertStringContainsString( $expected, $html, 'Could not find the expected throttle warning' );
	}

	public static function provideBuildFilterEditorThrottleWarning(): array {
		return [
			'no dangerous actions configured' => [
				'rawActions' => [
					'disallow' => [ 'abusefilter-disallowed' ],
				],
				'expected' => '(abusefilter-edit-throttled-warning-no-actions)',
			],
			'dangerous actions configured' => [
				'rawActions' => [
					'degroup' => [],
				],
				'expected' => '(abusefilter-edit-throttled-warning-actions: (abusefilter-action-degroup), 1)',
			],
		];
	}

	private function getSpecsForThrottledFilter( array $actions ): Filter {
		return $this->getFilterFromSpecs( [
			'id' => '4',
			'name' => 'Throttled filter',
			'rules' => '1 = 0',
			'privacy' => Flags::FILTER_PUBLIC,
			'enabled' => 1,
			'hitCount' => 2,
			'throttled' => 1,
			'deleted' => 0,
			'actions' => $actions,
		] );
	}

	/**
	 * @dataProvider provideBuildFilterEditorReadOnly
	 * @param string|array{int,int} $subPage Subpage name as a string or `[ $filterId, $nthRevFromCurrent ]`
	 * @param array $rights
	 * @param bool $readOnly
	 * @param array $readOnlyOverrideMap
	 */
	public function testBuildFilterEditorReadOnly(
		string|array $subPage, array $rights, bool $readOnly, array $readOnlyOverrideMap
	): void {
		$this->overrideConfigValues( [
			'AbuseFilterUseCodeEditor' => false,
			'AbuseFilterUseCodeMirror' => false,
			'AbuseFilterCentralDB' => $this->getConfVar( MainConfigNames::DBname ),
			'AbuseFilterIsCentral' => true,
		] );

		if ( is_array( $subPage ) ) {
			[ $filterId, $nthRevFromCurrent ] = $subPage;
			$builder = $this->getDb()->newSelectQueryBuilder();
			$historyId = $builder
				->field( 'afh_id' )
				->table( 'abuse_filter_history' )
				->where( [ 'afh_filter' => $filterId ] )
				->orderBy( 'afh_id', $builder::SORT_DESC )
				->limit( $nthRevFromCurrent )
				->offset( $nthRevFromCurrent - 1 )
				->caller( __METHOD__ )
				->fetchField();
			$this->assertNotFalse( $historyId );
			$subPage = "history/$filterId/item/$historyId";
		}

		[ $html ] = $this->executeSpecialPage(
			$subPage,
			performer: $this->mockRegisteredAuthorityWithPermissions( $rights ),
			fullHtml: true,
		);
		$dom = DOMUtils::parseHTML( $html );

		$heading = DOMCompat::querySelector( $dom, 'h1' );
		$this->assertNotNull( $heading );
		if ( $readOnly ) {
			$this->assertStringContainsString(
				'(abusefilter-view-specific:', $heading->textContent
			);
		} else {
			$this->assertStringContainsString(
				'(abusefilter-edit-specific:', $heading->textContent
			);
		}

		$textInputs = DOMCompat::querySelectorAll( $dom, implode( ', ', [
			'input[name="wpFilterDescription"]',
			'textarea[name="wpFilterRules"]',
			'textarea[name="wpFilterNotes"]',
		] ) );
		$this->assertCount( 3, $textInputs );

		foreach ( $textInputs as $input ) {
			$name = $input->getAttribute( 'name' );
			$expected = $readOnlyOverrideMap[$name] ?? $readOnly;
			$this->assertSame(
				$expected, $input->hasAttribute( 'readonly' ),
				"$name should " . ( $expected ? 'be' : 'not be' ) . ' read-only'
			);
		}

		$flagCheckboxes = DOMCompat::querySelectorAll( $dom, implode( ', ', [
			'input[name="wpFilterSuppressed"]',
			'input[name="wpFilterHidden"]',
			'input[name="wpFilterEnabled"]',
			// 'input[name="wpFilterDeleted"]', // This is always disabled for enabled filters
			'input[name="wpFilterGlobal"]',
		] ) );
		$this->assertCount( 4, $flagCheckboxes );

		$actionCheckboxes = DOMCompat::querySelectorAll(
			$dom, '.mw-abusefilter-action-checkbox > input[type="checkbox"][name^="wpFilterAction"]'
		);
		$checkboxCount = count( $actionCheckboxes );
		$actionCount = count( $this->getConfVar( 'AbuseFilterActions' ) );
		$this->assertGreaterThanOrEqual(
			$actionCount, $checkboxCount,
			"$actionCount actions defined, but only found $checkboxCount action checkboxes"
		);

		foreach ( array_merge( $flagCheckboxes, $actionCheckboxes ) as $cb ) {
			$name = $cb->getAttribute( 'name' );
			$expected = $readOnlyOverrideMap[$name] ?? $readOnly;
			$this->assertSame(
				$expected, $cb->hasAttribute( 'disabled' ),
				"$name should " . ( $expected ? 'be' : 'not be' ) . ' disabled'
			);
		}
	}

	public static function provideBuildFilterEditorReadOnly(): Generator {
		$editRights = [ 'abusefilter-modify', 'abusefilter-modify-global' ];

		foreach ( [
			'public' => [ '2', [] ],
			'private' => [ '3', [ 'abusefilter-view-private' ] ],
			'protected' => [ '1', [ 'abusefilter-access-protected-vars' ] ],
			'suppressed' => [ '5', [ 'viewsuppressed', 'suppressrevision' ] ],
		] as $privacy => [ $subPage, $viewRights ] ) {
			$baseRights = array_merge(
				[ 'abusefilter-view', 'abusefilter-modify-restricted' ],
				$viewRights
			);

			$readOnlyOverrideMap = [];
			if ( !in_array( 'suppressrevision', $baseRights, true ) ) {
				// wpFilterSuppressed is disabled on a non-suppressor view
				$readOnlyOverrideMap['wpFilterSuppressed'] = true;
			}

			yield "$privacy filter, no edit rights" => [
				'subPage' => $subPage,
				'rights' => $baseRights,
				'readOnly' => true,
				'readOnlyOverrideMap' => $readOnlyOverrideMap,
			];
			yield "$privacy filter, has edit rights" => [
				'subPage' => $subPage,
				'rights' => array_merge( $baseRights, $editRights ),
				'readOnly' => false,
				'readOnlyOverrideMap' => $readOnlyOverrideMap,
			];
		}

		yield 'has edit rights but cannot edit filters with restricted actions' => [
			'subPage' => '3',
			'rights' => $editRights,
			'readOnly' => false,
			'readOnlyOverrideMap' => [
				'wpFilterSuppressed' => true,
				'wpFilterActionBlockautopromote' => true,
				'wpFilterActionBlock' => true,
				'wpFilterActionRangeblock' => true,
				'wpFilterActionDegroup' => true,
			],
		];

		yield 'has edit rights but cannot edit filters with restricted actions, viewing an older revision' => [
			// Second-most-recent revision of filter #6
			'subPage' => [ 6, 2 ],
			'rights' => $editRights,
			'readOnly' => true,
			'readOnlyOverrideMap' => [
				// The rules edit box remains editable as long as AbuseFilterPermissionManager::canEdit
				// returns true in EditBoxBuilder::buildEditBox, regardless of whether restricted actions
				// are enabled.
				'wpFilterRules' => false,
			],
		];
	}

	public function testGetFilterFromImportTextInvalid(): void {
		[ $html, ] = $this->executeSpecialPage(
			'new',
			new FauxRequest( [ 'wpImportText' => 'abc' ], true ),
			null,
			$this->getTestSysop()->getAuthority()
		);

		$this->assertStringContainsString(
			'(abusefilter-import-invalid-data)',
			$html,
			'Invalid import data should cause an error message.'
		);
		$this->assertStringContainsString(
			'(abusefilter-return)',
			$html,
			'Button to return to the filter management was missing.'
		);
	}

	public function testGetFilterFromImportTextRemovesRestrictedActions(): void {
		[ $html ] = $this->executeSpecialPage(
			'new',
			new FauxRequest( [
				'wpImportText' => AbuseFilterServices::getFilterImporter()->encodeData(
					$this->getFilterFromSpecs( [ 'id' => 999 ] ),
					[
						'disallow' => [ 'abusefilter-disallow' ],
						'blockautopromote' => [],
						'block' => [ 'blocktalk', '2 hours', '2 hours' ],
						'rangeblock' => [],
						'degroup' => [],
					]
				)
			], true ),
			null,
			$this->mockRegisteredAuthorityWithPermissions( [
				'abusefilter-view', 'abusefilter-modify'
			] )
		);

		$this->assertStringContainsString( '(abusefilter-import-actions-removed:', $html );

		$actionCheckboxes = DOMCompat::querySelectorAll(
			DOMUtils::parseHTML( $html ),
			'.mw-abusefilter-action-checkbox > input[type="checkbox"][name^="wpFilterAction"]'
		);
		$checkboxCount = count( $actionCheckboxes );
		$actionCount = count( $this->getConfVar( 'AbuseFilterActions' ) );
		$this->assertGreaterThanOrEqual(
			$actionCount, $checkboxCount,
			"$actionCount actions defined, but only found $checkboxCount action checkboxes"
		);

		foreach ( $actionCheckboxes as $cb ) {
			$name = $cb->getAttribute( 'name' );
			// Disallow is the only non-restricted action included in the import data
			$expected = $name === 'wpFilterActionDisallow';
			$this->assertSame(
				$expected, $cb->hasAttribute( 'checked' ),
				"$name should " . ( $expected ? 'be' : 'not be' ) . ' checked'
			);
		}
	}
}
