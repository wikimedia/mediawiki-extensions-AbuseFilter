<?php

namespace MediaWiki\Extension\AbuseFilter\Pager;

use HtmlArmor;
use Linker;
use MediaWiki\Extension\AbuseFilter\AbuseFilter;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\View\AbuseFilterViewHistory;
use MediaWiki\Linker\LinkRenderer;
use SpecialPage;
use TablePager;
use Title;
use Xml;

class AbuseFilterHistoryPager extends TablePager {
	/**
	 * @var int|null The filter ID
	 */
	public $mFilter;
	/**
	 * @var AbuseFilterViewHistory The associated page
	 */
	public $mPage;
	/**
	 * @var string The user whose changes we're looking up for
	 */
	public $mUser;
	/**
	 * @var bool
	 */
	private $canViewPrivateFilters;

	/**
	 * @param ?int $filter
	 * @param AbuseFilterViewHistory $page
	 * @param string $user User name
	 * @param LinkRenderer $linkRenderer
	 * @param bool $canViewPrivateFilters
	 */
	public function __construct(
		?int $filter,
		AbuseFilterViewHistory $page,
		$user,
		LinkRenderer $linkRenderer,
		bool $canViewPrivateFilters = false
	) {
		parent::__construct( $page->getContext(), $linkRenderer );
		$this->mFilter = $filter;
		$this->mPage = $page;
		$this->mUser = $user;
		$this->mDefaultDirection = true;
		$this->canViewPrivateFilters = $canViewPrivateFilters;
	}

	/**
	 * @return array
	 * @see Pager::getFieldNames()
	 */
	public function getFieldNames() {
		static $headers = null;

		if ( !empty( $headers ) ) {
			return $headers;
		}

		$headers = [
			'afh_timestamp' => 'abusefilter-history-timestamp',
			'afh_user_text' => 'abusefilter-history-user',
			'afh_public_comments' => 'abusefilter-history-public',
			'afh_flags' => 'abusefilter-history-flags',
			'afh_actions' => 'abusefilter-history-actions',
			'afh_id' => 'abusefilter-history-diff',
		];

		if ( !$this->mFilter ) {
			// awful hack
			$headers = [ 'afh_filter' => 'abusefilter-history-filterid' ] + $headers;
		}

		foreach ( $headers as &$msg ) {
			$msg = $this->msg( $msg )->text();
		}

		return $headers;
	}

	/**
	 * @param string $name
	 * @param string $value
	 * @return string
	 */
	public function formatValue( $name, $value ) {
		$lang = $this->getLanguage();
		$linkRenderer = $this->getLinkRenderer();
		$specsFormatter = AbuseFilterServices::getSpecsFormatter();
		$specsFormatter->setMessageLocalizer( $this->getContext() );

		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'afh_filter':
				$formatted = $linkRenderer->makeLink(
					SpecialPage::getTitleFor( 'AbuseFilter', $row->afh_filter ),
					$lang->formatNum( $row->afh_filter )
				);
				break;
			case 'afh_timestamp':
				$title = SpecialPage::getTitleFor( 'AbuseFilter',
					'history/' . $row->afh_filter . '/item/' . $row->afh_id );
				$formatted = $linkRenderer->makeLink(
					$title,
					$lang->timeanddate( $row->afh_timestamp, true )
				);
				break;
			case 'afh_user_text':
				$formatted =
					Linker::userLink( $row->afh_user, $row->afh_user_text ) . ' ' .
					Linker::userToolLinks( $row->afh_user, $row->afh_user_text );
				break;
			case 'afh_public_comments':
				$formatted = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false );
				break;
			case 'afh_flags':
				$formatted = $specsFormatter->formatFlags( $value, $lang );
				break;
			case 'afh_actions':
				$actions = unserialize( $value );

				$display_actions = '';

				foreach ( $actions as $action => $parameters ) {
					$displayAction = $specsFormatter->formatAction( $action, $parameters, $lang );
					$display_actions .= Xml::tags( 'li', null, $displayAction );
				}
				$display_actions = Xml::tags( 'ul', null, $display_actions );

				$formatted = $display_actions;
				break;
			case 'afh_id':
				// Set a link to a diff with the previous version if this isn't the first edit to the filter.
				// Like in AbuseFilterViewDiff, don't show it if the user cannot see private filters and any
				// of the versions is hidden.
				$formatted = '';
				$lookup = AbuseFilterServices::getFilterLookup();
				if ( $lookup->getFirstFilterVersionID( $row->afh_filter ) !== (int)$value ) {
					// @todo Should we also hide actions?
					$prevFilter = $lookup->getClosestVersion( $row->afh_id, $row->afh_filter, FilterLookup::DIR_PREV );
					if ( $this->canViewPrivateFilters ||
						(
							!in_array( 'hidden', explode( ',', $row->afh_flags ) ) &&
							!$prevFilter->isHidden()
						)
					) {
						$title = $this->mPage->getTitle(
							'history/' . $row->afh_filter . "/diff/prev/$value" );
						$formatted = $linkRenderer->makeLink(
							$title,
							new HtmlArmor( $this->msg( 'abusefilter-history-diff' )->parse() )
						);
					}
				}
				break;
			default:
				$formatted = "Unable to format $name";
				break;
		}

		return $formatted;
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$info = [
			'tables' => [ 'abuse_filter_history', 'abuse_filter' ],
			// All fields but afh_deleted on abuse_filter_history
			'fields' => [
				'afh_filter',
				'afh_timestamp',
				'afh_user_text',
				'afh_public_comments',
				'afh_flags',
				'afh_comments',
				'afh_actions',
				'afh_id',
				'afh_user',
				'afh_changed_fields',
				'afh_pattern',
				'af_hidden'
			],
			'conds' => [],
			'join_conds' => [
				'abuse_filter' =>
					[
						'LEFT JOIN',
						'afh_filter=af_id',
					],
			],
		];

		if ( $this->mUser ) {
			$info['conds']['afh_user_text'] = $this->mUser;
		}

		if ( $this->mFilter ) {
			$info['conds']['afh_filter'] = $this->mFilter;
		}

		if ( !$this->canViewPrivateFilters ) {
			// Hide data the user can't see.
			$info['conds']['af_hidden'] = 0;
		}

		return $info;
	}

	/**
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
	 */
	public function getDefaultSort() {
		return 'afh_timestamp';
	}

	/**
	 * @codeCoverageIgnore Merely declarative
	 * @inheritDoc
	 */
	public function isFieldSortable( $name ) {
		return $name === 'afh_timestamp';
	}

	/**
	 * @param string $field
	 * @param string $value
	 * @return array
	 * @see TablePager::getCellAttrs
	 *
	 */
	public function getCellAttrs( $field, $value ) {
		$row = $this->mCurrentRow;
		$mappings = array_flip( AbuseFilter::HISTORY_MAPPINGS ) +
			[ 'afh_actions' => 'actions', 'afh_id' => 'id' ];
		$changed = explode( ',', $row->afh_changed_fields );

		$fieldChanged = false;
		if ( $field === 'afh_flags' ) {
			// The field is changed if any of these filters are in the $changed array.
			$filters = [ 'af_enabled', 'af_hidden', 'af_deleted', 'af_global' ];
			if ( count( array_intersect( $filters, $changed ) ) ) {
				$fieldChanged = true;
			}
		} elseif ( in_array( $mappings[$field], $changed ) ) {
			$fieldChanged = true;
		}

		$class = $fieldChanged ? ' mw-abusefilter-history-changed' : '';
		$attrs = parent::getCellAttrs( $field, $value );
		$attrs['class'] .= $class;
		return $attrs;
	}

	/**
	 * Title used for self-links.
	 *
	 * @return Title
	 */
	public function getTitle() {
		$subpage = $this->mFilter ? ( 'history/' . $this->mFilter ) : 'history';
		return $this->mPage->getTitle( $subpage );
	}
}
