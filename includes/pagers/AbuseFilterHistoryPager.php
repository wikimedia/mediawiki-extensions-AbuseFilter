<?php

use MediaWiki\Linker\LinkRenderer;

class AbuseFilterHistoryPager extends TablePager {
	public $mFilter, $mPage, $mUser;

	protected $linkRenderer;
	/**
	 * @param string $filter
	 * @param AbuseFilterViewHistory $page
	 * @param string $user User name
	 * @param LinkRenderer $linkRenderer
	 */
	public function __construct( $filter, $page, $user, $linkRenderer ) {
		$this->mFilter = $filter;
		$this->mPage = $page;
		$this->mUser = $user;
		$this->mDefaultDirection = true;
		$this->linkRenderer = $linkRenderer;
		parent::__construct( $this->mPage->getContext() );
	}

	/**
	 * @see Pager::getFieldNames()
	 * @return array
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
			unset( $headers['afh_comments'] );
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

		$row = $this->mCurrentRow;

		switch ( $name ) {
			case 'afh_filter':
				$formatted = $this->linkRenderer->makeLink(
					SpecialPage::getTitleFor( 'AbuseFilter', intval( $row->afh_filter ) ),
					$lang->formatNum( $row->afh_filter )
				);
				break;
			case 'afh_timestamp':
				$title = SpecialPage::getTitleFor( 'AbuseFilter',
					'history/' . $row->afh_filter . '/item/' . $row->afh_id );
				$formatted = $this->linkRenderer->makeLink(
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
				$formatted = AbuseFilter::formatFlags( $value );
				break;
			case 'afh_actions':
				$actions = unserialize( $value );

				$display_actions = '';

				foreach ( $actions as $action => $parameters ) {
					$displayAction = AbuseFilter::formatAction( $action, $parameters );
					$display_actions .= Xml::tags( 'li', null, $displayAction );
				}
				$display_actions = Xml::tags( 'ul', null, $display_actions );

				$formatted = $display_actions;
				break;
			case 'afh_id':
				$formatted = '';
				if ( AbuseFilter::getFirstFilterChange( $row->afh_filter ) != $value ) {
					// Set a link to a diff with the previous version if this isn't the first edit to the filter
					$title = $this->mPage->getTitle(
								'history/' . $row->afh_filter . "/diff/prev/$value" );
					$formatted = $this->linkRenderer->makeLink(
						$title,
						new HtmlArmor( $this->msg( 'abusefilter-history-diff' )->parse() )
					);
				}
				break;
			default:
				$formatted = "Unable to format $name";
				break;
		}

		$mappings = array_flip( AbuseFilter::$history_mappings ) +
			[ 'afh_actions' => 'actions', 'afh_id' => 'id' ];
		$changed = explode( ',', $row->afh_changed_fields );

		$fieldChanged = false;
		if ( $name == 'afh_flags' ) {
			// This is a bit freaky, but it works.
			// Basically, returns true if any of those filters are in the $changed array.
			$filters = [ 'af_enabled', 'af_hidden', 'af_deleted', 'af_global' ];
			if ( count( array_diff( $filters, $changed ) ) < count( $filters ) ) {
				$fieldChanged = true;
			}
		} elseif ( in_array( $mappings[$name], $changed ) ) {
			$fieldChanged = true;
		}

		if ( $fieldChanged ) {
			$formatted = Xml::tags( 'div',
				[ 'class' => 'mw-abusefilter-history-changed' ],
				$formatted
			);
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

		if ( !$this->getUser()->isAllowedAny(
			'abusefilter-modify', 'abusefilter-view-private' )
		) {
			// Hide data the user can't see.
			$info['conds']['af_hidden'] = 0;
		}

		return $info;
	}

	/**
	 * @return string
	 */
	public function getIndexField() {
		return 'afh_timestamp';
	}

	/**
	 * @return string
	 */
	public function getDefaultSort() {
		return 'afh_timestamp';
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public function isFieldSortable( $name ) {
		$sortable_fields = [ 'afh_timestamp', 'afh_user_text' ];
		return in_array( $name, $sortable_fields );
	}

	/**
	 * Title used for self-links.
	 *
	 * @return Title
	 */
	public function getTitle() {
		return $this->mPage->getTitle( 'history/' . $this->mFilter );
	}
}
