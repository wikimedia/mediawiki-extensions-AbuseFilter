<?php

namespace MediaWiki\Extension\AbuseFilter\View;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\FilterProfiler;
use MediaWiki\Extension\AbuseFilter\Pager\AbuseFilterPager;
use MediaWiki\Extension\AbuseFilter\Pager\GlobalAbuseFilterPager;
use MediaWiki\Extension\AbuseFilter\Pager\PatternMatcher;
use MediaWiki\Extension\AbuseFilter\Pager\SearchMode;
use MediaWiki\Extension\AbuseFilter\SpecsFormatter;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Parser\ParserOptions;
use OOUI;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * The default view used in Special:AbuseFilter
 */
class AbuseFilterViewList extends AbuseFilterView {

	public function __construct(
		private readonly LinkBatchFactory $linkBatchFactory,
		private readonly IConnectionProvider $dbProvider,
		AbuseFilterPermissionManager $afPermManager,
		private readonly FilterProfiler $filterProfiler,
		private readonly SpecsFormatter $specsFormatter,
		private readonly CentralDBManager $centralDBManager,
		private readonly FilterLookup $filterLookup,
		IContextSource $context,
		LinkRenderer $linkRenderer,
		string $basePageName,
		array $params
	) {
		parent::__construct( $afPermManager, $context, $linkRenderer, $basePageName, $params );
		$this->specsFormatter->setMessageLocalizer( $context );
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
			$out->addHTML( (string)$buttons );
		}

		$deleted = $request->getVal( 'deletedfilters' );
		/** @var string[] $furtherOptions */
		$furtherOptions = $request->getArray( 'furtheroptions' ) ?? [];
		// Backward compatibility with old links
		if ( $request->getBool( 'hidedisabled' ) ) {
			$furtherOptions[] = 'hidedisabled';
		}
		if ( $request->getBool( 'hideprivate' ) ) {
			$furtherOptions[] = 'hideprivate';
		}
		if ( $request->getBool( 'hidesuppressed' ) ) {
			$furtherOptions[] = 'hidesuppressed';
		}
		$defaultscope = 'all';
		if ( $config->get( 'AbuseFilterCentralDB' ) !== null
				&& !$config->get( 'AbuseFilterIsCentral' ) ) {
			// Show on remote wikis as default only local filters
			$defaultscope = 'local';
		}
		$scope = $request->getText( 'rulescope', $defaultscope );

		$searchEnabled = $this->afPermManager->canViewPrivateFilters( $performer ) && !(
			$config->get( 'AbuseFilterCentralDB' ) !== null &&
			!$config->get( 'AbuseFilterIsCentral' ) &&
			$scope === 'global' );
		$searchOptions = null;

		if ( $searchEnabled ) {
			$querypattern = $request->getText( 'querypattern' );
			$searchmode = $request->getVal( 'searchoption', null );
			// BC: Support older links without the "searchfields" query parameter (non-null fallback)
			$searchfields = $request->getVal( 'searchfields' ) ?? (string)PatternMatcher::SEARCH_RULES;

			if ( $querypattern !== '' && $searchmode !== null ) {
				// Validate the search options. Filtering the results is done in AbuseFilterPager
				$error = null;
				$searchmode = SearchMode::tryFrom( $searchmode );
				if ( $searchmode === null ) {
					$error = 'abusefilter-list-invalid-searchmode';
				} elseif ( !PatternMatcher::isValidSearchTarget( $searchfields ) ) {
					$error = 'abusefilter-list-invalid-searchfields';
				} elseif (
					$searchmode->requiresRegexMatching() &&
					!PatternMatcher::isValidRegexPattern( $querypattern )
				) {
					$error = 'abusefilter-list-regexerror';
				} else {
					$searchOptions = [
						'pattern' => $querypattern,
						'mode' => $searchmode,
						'target' => (int)$searchfields,
					];
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
				}
			}
		}

		$conds = [];
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

		$hidePrivate = in_array( 'hideprivate', $furtherOptions );
		$hideSuppressed = in_array( 'hidesuppressed', $furtherOptions );
		$hiddenFlags = 0;

		// Don't show private filters if they've been requested to be hidden
		if ( $hidePrivate ) {
			$hiddenFlags |= Flags::FILTER_HIDDEN;
		}

		// Don't show suppressed filters if they've been requested to be hidden
		// or if we're searching with a query pattern and the user cannot view supressed filters
		// (this would leak the rules of the filter if permitted).
		if (
			$hideSuppressed ||
			( $searchOptions !== null && !$this->afPermManager->canViewSuppressed( $performer ) )
		) {
			$hiddenFlags |= Flags::FILTER_SUPPRESSED;
		}

		// Viewers with the right to view private filters have access to the search
		// function, which can query against protected filters and potentially expose PII.
		// Remove protected filters from the query if the user doesn't have the right to search
		// against them. This allows protected filters to be visible in the general list of
		// filters at all other times.
		// Filters with protected variables that have additional restrictions cannot be excluded using SQL
		// but will be excluded in the AbuseFilterPager.
		if (
			$searchOptions !== null &&
			!$this->afPermManager->canViewProtectedVariables( $performer, [] )->isGood()
		) {
			$hiddenFlags |= Flags::FILTER_USES_PROTECTED_VARS;
		}

		if ( $hiddenFlags !== 0 ) {
			$dbr = $this->dbProvider->getReplicaDatabase();
			$conds[] = $dbr->bitAnd( 'af_hidden', $hiddenFlags ) . ' = 0';
		}

		if ( $scope === 'local' ) {
			$conds['af_global'] = 0;
		} elseif ( $scope === 'global' ) {
			$conds['af_global'] = 1;
		}

		$this->showList(
			[
				'deleted' => $deleted,
				'furtherOptions' => $furtherOptions,
				'searchOptions' => $searchOptions,
				'scope' => $scope,
			],
			$conds
		);
	}

	/**
	 * @phpcs:ignore Generic.Files.LineLength
	 * @param array{deleted:'show'|'only'|'hide',furtherOptions:string[],searchOptions:array{pattern:string,mode:SearchMode,target:int}|null,scope:'all'|'local'|'global'} $optarray
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
		$searchOptions = $optarray['searchOptions'];
		$scope = $optarray['scope'];

		if ( $searchOptions !== null ) {
			$patternMatcher = new PatternMatcher(
				$searchOptions['pattern'],
				$searchOptions['mode'],
				$searchOptions['target'],
			);
		} else {
			$patternMatcher = null;
		}

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
				$patternMatcher,
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
				$this->msg( 'abusefilter-list-options-hidesuppressed' )->parse() => 'hidesuppressed',
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
				'label-message' => 'abusefilter-list-options-searchquery',
				'placeholder' => $this->msg( 'abusefilter-list-options-searchpattern' )->text(),
				'default' => $searchOptions !== null ? $searchOptions['pattern'] : '',
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
					'abusefilter-list-options-search-like' => SearchMode::LIKE->value,
					'abusefilter-list-options-search-rlike' => SearchMode::RLIKE->value,
					'abusefilter-list-options-search-irlike' => SearchMode::IRLIKE->value,
				],
				'default' => $searchOptions !== null ? $searchOptions['mode']->value : SearchMode::LIKE->value,
			];

			$formDescriptor['searchfields'] = [
				'name' => 'searchfields',
				'type' => 'radio',
				'flatlist' => true,
				'label-message' => 'abusefilter-list-options-searchfields',
				'hide-if' => $formDescriptor['searchoption']['hide-if'],
				'options-messages' => [
					'abusefilter-list-options-searchfields-rules' => PatternMatcher::SEARCH_RULES,
					'abusefilter-list-options-searchfields-notes' => PatternMatcher::SEARCH_NOTES,
					'abusefilter-list-options-searchfields-both' => PatternMatcher::SEARCH_ALL,
				],
				'default' => $searchOptions !== null ? $searchOptions['target'] : PatternMatcher::SEARCH_RULES,
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
