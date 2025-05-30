<?php

namespace MediaWiki\Extension\AbuseFilter\View;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\FilterProfiler;
use MediaWiki\Extension\AbuseFilter\Pager\AbuseFilterPager;
use MediaWiki\Extension\AbuseFilter\Pager\GlobalAbuseFilterPager;
use MediaWiki\Extension\AbuseFilter\SpecsFormatter;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Parser\ParserOptions;
use OOUI;
use StringUtils;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * The default view used in Special:AbuseFilter
 */
class AbuseFilterViewList extends AbuseFilterView {

	private LinkBatchFactory $linkBatchFactory;
	private IConnectionProvider $dbProvider;
	private FilterProfiler $filterProfiler;
	private SpecsFormatter $specsFormatter;
	private CentralDBManager $centralDBManager;
	private FilterLookup $filterLookup;

	public function __construct(
		LinkBatchFactory $linkBatchFactory,
		IConnectionProvider $dbProvider,
		AbuseFilterPermissionManager $afPermManager,
		FilterProfiler $filterProfiler,
		SpecsFormatter $specsFormatter,
		CentralDBManager $centralDBManager,
		FilterLookup $filterLookup,
		IContextSource $context,
		LinkRenderer $linkRenderer,
		string $basePageName,
		array $params
	) {
		parent::__construct( $afPermManager, $context, $linkRenderer, $basePageName, $params );
		$this->linkBatchFactory = $linkBatchFactory;
		$this->dbProvider = $dbProvider;
		$this->filterProfiler = $filterProfiler;
		$this->specsFormatter = $specsFormatter;
		$this->specsFormatter->setMessageLocalizer( $context );
		$this->centralDBManager = $centralDBManager;
		$this->filterLookup = $filterLookup;
	}

	/**
	 * Shows the page
	 */
	public function show() {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$config = $this->getConfig();
		$performer = $this->getAuthority();

		$out->addWikiMsg( 'abusefilter-intro' );
		$this->showStatus();

		// New filter button
		if ( $this->afPermManager->canEdit( $performer ) ) {
			$out->enableOOUI();
			$buttons = new OOUI\HorizontalLayout( [
				'items' => [
					new OOUI\ButtonWidget( [
						'label' => $this->msg( 'abusefilter-new' )->text(),
						'href' => $this->getTitle( 'new' )->getFullURL(),
						'flags' => [ 'primary', 'progressive' ],
					] ),
					new OOUI\ButtonWidget( [
						'label' => $this->msg( 'abusefilter-import-button' )->text(),
						'href' => $this->getTitle( 'import' )->getFullURL(),
						'flags' => [ 'primary', 'progressive' ],
					] )
				]
			] );
			$out->addHTML( $buttons );
		}

		$conds = [];
		$deleted = $request->getVal( 'deletedfilters' );
		$furtherOptions = $request->getArray( 'furtheroptions', [] );
		'@phan-var array $furtherOptions';
		// Backward compatibility with old links
		if ( $request->getBool( 'hidedisabled' ) ) {
			$furtherOptions[] = 'hidedisabled';
		}
		if ( $request->getBool( 'hideprivate' ) ) {
			$furtherOptions[] = 'hideprivate';
		}
		$defaultscope = 'all';
		if ( $config->get( 'AbuseFilterCentralDB' ) !== null
				&& !$config->get( 'AbuseFilterIsCentral' ) ) {
			// Show on remote wikis as default only local filters
			$defaultscope = 'local';
		}
		$scope = $request->getVal( 'rulescope', $defaultscope );

		$searchEnabled = $this->afPermManager->canViewPrivateFilters( $performer ) && !(
			$config->get( 'AbuseFilterCentralDB' ) !== null &&
			!$config->get( 'AbuseFilterIsCentral' ) &&
			$scope === 'global' );

		if ( $searchEnabled ) {
			$querypattern = $request->getVal( 'querypattern', '' );
			$searchmode = $request->getVal( 'searchoption', null );
			if ( $querypattern === '' ) {
				// Not specified or empty, that would error out
				$querypattern = $searchmode = null;
			}
		} else {
			$querypattern = null;
			$searchmode = null;
		}

		if ( $deleted === 'show' ) {
			// Nothing
		} elseif ( $deleted === 'only' ) {
			$conds['af_deleted'] = 1;
		} else {
			// hide, or anything else.
			$conds['af_deleted'] = 0;
			$deleted = 'hide';
		}
		if ( in_array( 'hidedisabled', $furtherOptions ) ) {
			$conds['af_deleted'] = 0;
			$conds['af_enabled'] = 1;
		}
		if ( in_array( 'hideprivate', $furtherOptions ) ) {
			$conds['af_hidden'] = Flags::FILTER_PUBLIC;
		}

		if ( $scope === 'local' ) {
			$conds['af_global'] = 0;
		} elseif ( $scope === 'global' ) {
			$conds['af_global'] = 1;
		}

		if ( $searchmode !== null ) {
			// Check the search pattern. Filtering the results is done in AbuseFilterPager
			$error = null;
			if ( !in_array( $searchmode, [ 'LIKE', 'RLIKE', 'IRLIKE' ] ) ) {
				$error = 'abusefilter-list-invalid-searchmode';
			} elseif ( $searchmode !== 'LIKE' && !StringUtils::isValidPCRERegex( "/$querypattern/" ) ) {
				// @phan-suppress-previous-line SecurityCheck-ReDoS Yes, I know...
				$error = 'abusefilter-list-regexerror';
			}

			if ( $error !== null ) {
				$out->addModuleStyles( 'mediawiki.codex.messagebox.styles' );
				$out->addHTML(
					Html::rawElement(
						'p',
						[],
						Html::errorBox( $this->msg( $error )->escaped() )
					)
				);

				// Reset the conditions in case of error
				$conds = [ 'af_deleted' => 0 ];
				$searchmode = $querypattern = null;
			}

			// Viewers with the right to view private filters have access to the search
			// function, which can query against protected filters and potentially expose PII.
			// Remove protected filters from the query if the user doesn't have the right to search
			// against them. This allows protected filters to be visible in the general list of
			// filters at all other times.
			// Filters with protected variables that have additional restrictions cannot be excluded using SQL
			// but will be excluded in the AbuseFilterPager.
			if ( !$this->afPermManager->canViewProtectedVariables( $performer, [] )->isGood() ) {
				$dbr = $this->dbProvider->getReplicaDatabase();
				$conds[] = $dbr->bitAnd( 'af_hidden', Flags::FILTER_USES_PROTECTED_VARS ) . ' = 0';
			}
		}

		$this->showList(
			[
				'deleted' => $deleted,
				'furtherOptions' => $furtherOptions,
				'querypattern' => $querypattern,
				'searchmode' => $searchmode,
				'scope' => $scope,
			],
			$conds
		);
	}

	/**
	 * @param array $optarray
	 * @param array $conds
	 */
	private function showList( array $optarray, array $conds = [ 'af_deleted' => 0 ] ) {
		$performer = $this->getAuthority();
		$config = $this->getConfig();
		$centralDB = $config->get( 'AbuseFilterCentralDB' );
		$dbIsCentral = $config->get( 'AbuseFilterIsCentral' );
		$this->getOutput()->addHTML(
			Html::rawElement( 'h2', [], $this->msg( 'abusefilter-list' )->parse() )
		);

		$deleted = $optarray['deleted'];
		$furtherOptions = $optarray['furtherOptions'];
		$scope = $optarray['scope'];
		$querypattern = $optarray['querypattern'];
		$searchmode = $optarray['searchmode'];

		if ( $centralDB !== null && !$dbIsCentral && $scope === 'global' ) {
			// TODO: remove the circular dependency
			$pager = new GlobalAbuseFilterPager(
				$this,
				$this->linkRenderer,
				$this->afPermManager,
				$this->specsFormatter,
				$this->centralDBManager,
				$this->filterLookup,
				$conds
			);
		} else {
			$pager = new AbuseFilterPager(
				$this,
				$this->linkRenderer,
				$this->linkBatchFactory,
				$this->afPermManager,
				$this->specsFormatter,
				$this->filterLookup,
				$conds,
				$querypattern,
				$searchmode
			);
		}

		// Options form
		$formDescriptor = [];

		if ( $centralDB !== null ) {
			$optionsMsg = [
				'abusefilter-list-options-scope-local' => 'local',
				'abusefilter-list-options-scope-global' => 'global',
			];
			if ( $dbIsCentral ) {
				// For central wiki: add third scope option
				$optionsMsg['abusefilter-list-options-scope-all'] = 'all';
			}
			$formDescriptor['rulescope'] = [
				'name' => 'rulescope',
				'type' => 'radio',
				'flatlist' => true,
				'label-message' => 'abusefilter-list-options-scope',
				'options-messages' => $optionsMsg,
				'default' => $scope,
			];
		}

		$formDescriptor['deletedfilters'] = [
			'name' => 'deletedfilters',
			'type' => 'radio',
			'flatlist' => true,
			'label-message' => 'abusefilter-list-options-deleted',
			'options-messages' => [
				'abusefilter-list-options-deleted-show' => 'show',
				'abusefilter-list-options-deleted-hide' => 'hide',
				'abusefilter-list-options-deleted-only' => 'only',
			],
			'default' => $deleted,
		];

		$formDescriptor['furtheroptions'] = [
			'name' => 'furtheroptions',
			'type' => 'multiselect',
			'label-message' => 'abusefilter-list-options-further-options',
			'flatlist' => true,
			'options' => [
				$this->msg( 'abusefilter-list-options-hideprivate' )->parse() => 'hideprivate',
				$this->msg( 'abusefilter-list-options-hidedisabled' )->parse() => 'hidedisabled',
			],
			'default' => $furtherOptions
		];

		if ( $this->afPermManager->canViewPrivateFilters( $performer ) ) {
			$globalEnabled = $centralDB !== null && !$dbIsCentral;
			$formDescriptor['querypattern'] = [
				'name' => 'querypattern',
				'type' => 'text',
				'hide-if' => $globalEnabled ? [ '===', 'rulescope', 'global' ] : [],
				'label-message' => 'abusefilter-list-options-searchfield',
				'placeholder' => $this->msg( 'abusefilter-list-options-searchpattern' )->text(),
				'default' => $querypattern
			];

			$formDescriptor['searchoption'] = [
				'name' => 'searchoption',
				'type' => 'radio',
				'flatlist' => true,
				'label-message' => 'abusefilter-list-options-searchoptions',
				'hide-if' => $globalEnabled ?
					[ 'OR', [ '===', 'querypattern', '' ], $formDescriptor['querypattern']['hide-if'] ] :
					[ '===', 'querypattern', '' ],
				'options-messages' => [
					'abusefilter-list-options-search-like' => 'LIKE',
					'abusefilter-list-options-search-rlike' => 'RLIKE',
					'abusefilter-list-options-search-irlike' => 'IRLIKE',
				],
				'default' => $searchmode
			];
		}

		$formDescriptor['limit'] = [
			'name' => 'limit',
			'type' => 'select',
			'label-message' => 'abusefilter-list-limit',
			'options' => $pager->getLimitSelectList(),
			'default' => $pager->getLimit(),
		];

		HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )
			->setTitle( $this->getTitle() )
			->setCollapsibleOptions( true )
			->setWrapperLegendMsg( 'abusefilter-list-options' )
			->setSubmitTextMsg( 'abusefilter-list-options-submit' )
			->setMethod( 'get' )
			->prepareForm()
			->displayForm( false );

		$this->getOutput()->addParserOutputContent(
			$pager->getFullOutput(),
			ParserOptions::newFromContext( $this->getContext() )
		);
	}

	/**
	 * Generates a summary of filter activity using the internal statistics.
	 */
	public function showStatus() {
		$totalCount = 0;
		$matchCount = 0;
		$overflowCount = 0;
		foreach ( $this->getConfig()->get( 'AbuseFilterValidGroups' ) as $group ) {
			$profile = $this->filterProfiler->getGroupProfile( $group );
			$totalCount += $profile[ 'total' ];
			$overflowCount += $profile[ 'overflow' ];
			$matchCount += $profile[ 'matches' ];
		}

		if ( $totalCount > 0 ) {
			$overflowPercent = round( 100 * $overflowCount / $totalCount, 2 );
			$matchPercent = round( 100 * $matchCount / $totalCount, 2 );

			$status = $this->msg( 'abusefilter-status' )
				->numParams(
					$totalCount,
					$overflowCount,
					$overflowPercent,
					$this->getConfig()->get( 'AbuseFilterConditionLimit' ),
					$matchCount,
					$matchPercent
				)->parse();

			$status = Html::rawElement( 'p', [ 'class' => 'mw-abusefilter-status' ], $status );
			$this->getOutput()->addHTML( $status );
		}
	}
}
