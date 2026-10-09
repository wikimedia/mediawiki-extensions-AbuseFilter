<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\View;

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
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Specials\SpecialPageTestBase;
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

	public function testViewEditForInvalidImport() {
		[ $html, ] = $this->executeSpecialPage(
			'new',
			new FauxRequest( [ 'wpImportText' => 'abc' ], true ),
			null,
			$this->getTestSysop()->getAuthority()
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
}
