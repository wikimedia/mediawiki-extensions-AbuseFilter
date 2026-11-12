<?php

namespace MediaWiki\Extension\AbuseFilter\Special;

use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\ServiceNames;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterView;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewDiff;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewEdit;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewExamine;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewHistory;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewImport;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewList;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewRevert;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewTestBatch;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewTools;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use Wikimedia\ObjectFactory\ObjectFactory;

class SpecialAbuseFilter extends AbuseFilterSpecialPage {

	private const PAGE_NAME = 'AbuseFilter';

	private const SERVICES_PER_VIEW = [
		AbuseFilterViewDiff::class => [
			ServiceNames::PermManager,
			ServiceNames::SpecsFormatter,
			ServiceNames::FilterLookup,
		],
		AbuseFilterViewEdit::class => [
			'DBLoadBalancerFactory',
			ServiceNames::PermManager,
			ServiceNames::FilterProfiler,
			ServiceNames::FilterLookup,
			ServiceNames::FilterImporter,
			ServiceNames::FilterStore,
			ServiceNames::EditBoxBuilderFactory,
			ServiceNames::ConsequencesRegistry,
			ServiceNames::SpecsFormatter,
		],
		AbuseFilterViewExamine::class => [
			'DBLoadBalancerFactory',
			ServiceNames::PermManager,
			ServiceNames::FilterLookup,
			ServiceNames::EditBoxBuilderFactory,
			ServiceNames::VariablesBlobStore,
			ServiceNames::VariablesFormatter,
			ServiceNames::VariablesManager,
			ServiceNames::VariableGeneratorFactory,
			ServiceNames::AbuseLoggerFactory,
			'RecentChangeStore',
			'ReadOnlyMode',
		],
		AbuseFilterViewHistory::class => [
			'UserNameUtils',
			'LinkBatchFactory',
			ServiceNames::PermManager,
			ServiceNames::FilterLookup,
			ServiceNames::SpecsFormatter,
		],
		AbuseFilterViewImport::class => [
			ServiceNames::PermManager,
		],
		AbuseFilterViewList::class => [
			'LinkBatchFactory',
			'ConnectionProvider',
			ServiceNames::PermManager,
			ServiceNames::FilterProfiler,
			ServiceNames::SpecsFormatter,
			ServiceNames::CentralDBManager,
			ServiceNames::FilterLookup,
		],
		AbuseFilterViewRevert::class => [
			'DBLoadBalancerFactory',
			'UserFactory',
			ServiceNames::PermManager,
			ServiceNames::FilterLookup,
			ServiceNames::ConsequencesFactory,
			ServiceNames::VariablesBlobStore,
			ServiceNames::SpecsFormatter,
		],
		AbuseFilterViewTestBatch::class => [
			'DBLoadBalancerFactory',
			ServiceNames::PermManager,
			ServiceNames::EditBoxBuilderFactory,
			ServiceNames::RuleCheckerFactory,
			ServiceNames::VariableGeneratorFactory,
			ServiceNames::AbuseLoggerFactory,
			'RecentChangeFactory',
			'ReadOnlyMode',
		],
		AbuseFilterViewTools::class => [
			ServiceNames::PermManager,
			ServiceNames::EditBoxBuilderFactory,
		],
	];

	public function __construct(
		AbuseFilterPermissionManager $afPermissionManager,
		private readonly ObjectFactory $objectFactory
	) {
		parent::__construct( self::PAGE_NAME, $afPermissionManager );
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return 'abusefilter-view';
	}

	/**
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'wiki';
	}

	/**
	 * @param string|null $subpage
	 */
	public function execute( $subpage ) {
		$out = $this->getOutput();

		$out->addModuleStyles( 'ext.abuseFilter' );
		$this->setHeaders();
		$this->addHelpLink( 'Extension:AbuseFilter' );

		$this->checkPermissions();

		$session = $this->getContext()->getRequest()->getSession();
		$successData = $session->get( AbuseFilterViewEdit::EDIT_SUCCESS_SESSION_KEY );
		if ( $successData !== null ) {
			// Remove session data for the success message
			$session->remove( AbuseFilterViewEdit::EDIT_SUCCESS_SESSION_KEY );

			$changedFilter = $successData['changedFilter'];
			$changeId = $successData['changeId'];

			$out->setSubtitle( $this->msg( 'abusefilter-edit-done-subtitle' ) );
			$out->addModuleStyles( 'mediawiki.codex.messagebox.styles' );
			$out->addHTML( Html::successBox(
				$this->msg(
					'abusefilter-edit-done',
					$changedFilter,
					$changeId,
					$this->getLanguage()->formatNum( $changedFilter )
				)->parse()
			) );
		}

		[ $view, $pageType, $params ] = $this->getViewClassAndPageType( $subpage );

		// Links at the top
		$this->addNavigationLinks( $pageType );

		$view = $this->instantiateView( $view, $params );
		$view->show();
	}

	/**
	 * Instantiate the view class
	 *
	 * @param class-string<AbuseFilterView> $viewClass
	 * @param array $params
	 * @return AbuseFilterView
	 */
	public function instantiateView( string $viewClass, array $params ): AbuseFilterView {
		return $this->objectFactory->createObject( [
			'class' => $viewClass,
			'services' => self::SERVICES_PER_VIEW[$viewClass],
			'args' => [ $this->getContext(), $this->getLinkRenderer(), self::PAGE_NAME, $params ]
		] );
	}

	/**
	 * Determine the view class to instantiate
	 *
	 * @param string|null $subpage
	 * @return array{0:class-string,1:string,2:array} A tuple of three elements:
	 *      - a subclass of AbuseFilterView
	 *      - type of page for addNavigationLinks
	 *      - array of parameters for the class
	 */
	public function getViewClassAndPageType( ?string $subpage ): array {
		// Filter by removing blanks.
		$params = preg_split( '{/+}', $subpage ?: '' );

		if ( $subpage === 'tools' ) {
			return [ AbuseFilterViewTools::class, 'tools', [] ];
		}

		if ( $subpage === 'import' ) {
			return [ AbuseFilterViewImport::class, 'import', [] ];
		}

		if ( is_numeric( $subpage ) || $subpage === 'new' ) {
			return [
				AbuseFilterViewEdit::class,
				'edit',
				[ 'filter' => is_numeric( $subpage ) ? (int)$subpage : null ]
			];
		}

		if ( $params ) {
			if (
				count( $params ) === 2 && $params[0] === 'revert' &&
				self::isPositiveInteger( $params[1] )
			) {
				return [ AbuseFilterViewRevert::class, 'revert', [ 'filter' => (int)$params[1] ] ];
			}

			if ( $params[0] === 'test' ) {
				return [ AbuseFilterViewTestBatch::class, 'test', $params ];
			}

			if ( $params[0] === 'examine' ) {
				return [ AbuseFilterViewExamine::class, 'examine', $params ];
			}

			if ( $params[0] === 'history' || $params[0] === 'log' ) {
				if ( count( $params ) <= 2 ) {
					$params = isset( $params[1] ) ? [ 'filter' => (int)$params[1] ] : [];
					return [ AbuseFilterViewHistory::class, 'recentchanges', $params ];
				}
				if ( count( $params ) === 4 && $params[2] === 'item' ) {
					return [
						AbuseFilterViewEdit::class,
						'',
						[ 'filter' => (int)$params[1], 'history' => (int)$params[3] ]
					];
				}
				if ( count( $params ) === 5 && $params[2] === 'diff' ) {
					// Special:AbuseFilter/history/<filter>/diff/<oldid>/<newid>
					return [ AbuseFilterViewDiff::class, '', $params ];
				}
			}
		}

		return [ AbuseFilterViewList::class, 'home', [] ];
	}

	/**
	 * Checks whether the given value represents a positive integer.
	 *
	 * @param string|int $num
	 * @return bool
	 * @todo Use this method throughout the module.
	 */
	private static function isPositiveInteger( string|int $num ): bool {
		if ( is_string( $num ) && !ctype_digit( $num ) ) {
			return false;
		}
		return (int)$num > 0;
	}

	/**
	 * Static variant to get the associated Title.
	 *
	 * @param string|int $subpage
	 * @return Title
	 */
	public static function getTitleForSubpage( $subpage ): Title {
		return self::getTitleFor( self::PAGE_NAME, $subpage );
	}
}
