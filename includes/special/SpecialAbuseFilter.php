<?php

class SpecialAbuseFilter extends AbuseFilterSpecialPage {

	public const PAGE_NAME = 'AbuseFilter';

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		parent::__construct( self::PAGE_NAME, 'abusefilter-view' );
	}

	/**
	 * @inheritDoc
	 */
	public function doesWrites() {
		return true;
	}

	/**
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
		$request = $this->getRequest();

		$out->addModuleStyles( 'ext.abuseFilter' );

		$this->setHeaders();
		$this->addHelpLink( 'Extension:AbuseFilter' );

		$this->checkPermissions();

		if ( $request->getVal( 'result' ) === 'success' ) {
			$out->setSubtitle( $this->msg( 'abusefilter-edit-done-subtitle' ) );
			$changedFilter = intval( $request->getVal( 'changedfilter' ) );
			$changeId = intval( $request->getVal( 'changeid' ) );
			$out->wrapWikiMsg( '<p class="success">$1</p>',
				[
					'abusefilter-edit-done',
					$changedFilter,
					$changeId,
					$this->getLanguage()->formatNum( $changedFilter )
				]
			);
		}

		[ $view, $pageType, $params ] = $this->getViewClassAndPageType( $subpage );

		// Links at the top
		$this->addNavigationLinks( $pageType );

		/** @var AbuseFilterView $v */
		$v = new $view( $this, $params );
		$v->show();
	}

	/**
	 * Determine the view class to instantiate
	 *
	 * @param string|null $subpage
	 * @return array A tuple of three elements:
	 *      - a subclass of AbuseFilterView
	 *      - type of page for addNavigationLinks
	 *      - array of parameters for the class
	 * @phan-return array{0:class-string,1:string,2:array}
	 */
	public function getViewClassAndPageType( $subpage ) : array {
		// Filter by removing blanks.
		$params = array_values( array_filter(
			explode( '/', $subpage ?: '' ),
			function ( $value ) {
				return $value !== '';
			}
		) );

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
			if ( count( $params ) === 2 && $params[0] === 'revert' && is_numeric( $params[1] ) ) {
				$params[1] = (int)$params[1];
				return [ AbuseFilterViewRevert::class, 'revert', $params ];
			}

			if ( $params[0] === 'test' ) {
				return [ AbuseFilterViewTestBatch::class, 'test', $params ];
			}

			if ( $params[0] === 'examine' ) {
				return [ AbuseFilterViewExamine::class, 'examine', $params ];
			}

			if ( $params[0] === 'history' || $params[0] === 'log' ) {
				if ( count( $params ) <= 2 ) {
					if ( isset( $params[1] ) ) {
						$params[1] = (int)$params[1];
					}
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
}
