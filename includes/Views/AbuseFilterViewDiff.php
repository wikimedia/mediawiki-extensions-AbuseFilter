<?php

class AbuseFilterViewDiff extends AbuseFilterView {
	public $mOldVersion = null;
	public $mNewVersion = null;
	public $mNextHistoryId = null;
	public $mFilter = null;

	/**
	 * Shows the page
	 */
	public function show() {
		$show = $this->loadData();
		$out = $this->getOutput();
		$out->enableOOUI();
		$out->addModuleStyles( [ 'oojs-ui.styles.icons-movement' ] );

		$links = [];
		if ( $this->mFilter ) {
			$links['abusefilter-history-backedit'] =
				$this->getTitle( $this->mFilter )->getFullURL();
			$links['abusefilter-diff-backhistory'] =
				$this->getTitle( 'history/' . $this->mFilter )->getFullURL();
		}

		foreach ( $links as $msg => $href ) {
			$links[$msg] =
				new OOUI\ButtonWidget( [
					'label' => $this->msg( $msg )->text(),
					'href' => $href
				] );
		}

		$backlinks =
			new OOUI\HorizontalLayout( [
				'items' => $links
			] );
		$out->addHTML( $backlinks );

		if ( $show ) {
			$out->addHTML( $this->formatDiff() );
			// Next and previous change links
			$buttons = [];
			if ( AbuseFilter::getFirstFilterChange( $this->mFilter ) !=
				$this->mOldVersion['meta']['history_id']
			) {
				// Create a "previous change" link if this isn't the first change of the given filter
				$href = $this->getTitle(
					'history/' . $this->mFilter . '/diff/prev/' . $this->mOldVersion['meta']['history_id']
				)->getFullURL();
				$buttons[] = new OOUI\ButtonWidget( [
					'label' => $this->msg( 'abusefilter-diff-prev' )->text(),
					'href' => $href,
					'icon' => 'previous'
				] );
			}

			if ( !is_null( $this->mNextHistoryId ) ) {
				// Create a "next change" link if this isn't the last change of the given filter
				$href = $this->getTitle(
					'history/' . $this->mFilter . '/diff/prev/' . $this->mNextHistoryId
				)->getFullURL();
				$buttons[] = new OOUI\ButtonWidget( [
					'label' => $this->msg( 'abusefilter-diff-next' )->text(),
					'href' => $href,
					'icon' => 'next'
				] );
			}

			if ( count( $buttons ) > 0 ) {
				$buttons = new OOUI\HorizontalLayout( [
					'items' => $buttons,
					'classes' => [ 'mw-abusefilter-history-buttons' ]
				] );
				$out->addHTML( $buttons );
			}
		}
	}

	/**
	 * @return bool
	 */
	public function loadData() {
		$oldSpec = $this->mParams[3];
		$newSpec = $this->mParams[4];
		$this->mFilter = $this->mParams[1];

		if ( AbuseFilter::filterHidden( $this->mFilter )
			&& !$this->getUser()->isAllowedAny( 'abusefilter-modify', 'abusefilter-view-private' )
		) {
			$this->getOutput()->addWikiMsg( 'abusefilter-history-error-hidden' );
			return false;
		}

		$this->mOldVersion = $this->loadSpec( $oldSpec, $newSpec );
		$this->mNewVersion = $this->loadSpec( $newSpec, $oldSpec );

		if ( is_null( $this->mOldVersion ) || is_null( $this->mNewVersion ) ) {
			$this->getOutput()->addWikiMsg( 'abusefilter-diff-invalid' );
			return false;
		}

		$this->mNextHistoryId = $this->getNextHistoryId(
			$this->mNewVersion['meta']['history_id']
		);

		return true;
	}

	/**
	 * Get the history ID of the next change
	 *
	 * @param int $historyId History id to find next change of
	 * @return int|null Id of the next change or null if there isn't one
	 */
	public function getNextHistoryId( $historyId ) {
		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow(
			'abuse_filter_history',
			'afh_id',
			[
				'afh_filter' => $this->mFilter,
				'afh_id > ' . $dbr->addQuotes( $historyId ),
			],
			__METHOD__,
			[ 'ORDER BY' => 'afh_timestamp ASC' ]
		);
		if ( $row ) {
			return $row->afh_id;
		}
		return null;
	}

	/**
	 * @param string $spec
	 * @param string $otherSpec
	 * @return array|null
	 */
	public function loadSpec( $spec, $otherSpec ) {
		static $dependentSpecs = [ 'prev', 'next' ];
		static $cache = [];

		if ( isset( $cache[$spec] ) ) {
			return $cache[$spec];
		}

		$dbr = wfGetDB( DB_REPLICA );
		// All but afh_filter, afh_deleted and afh_changed_fields
		$selectFields = [
			'afh_id',
			'afh_user',
			'afh_user_text',
			'afh_timestamp',
			'afh_pattern',
			'afh_comments',
			'afh_flags',
			'afh_public_comments',
			'afh_actions',
			'afh_group',
		];
		$row = null;
		if ( is_numeric( $spec ) ) {
			$row = $dbr->selectRow(
				'abuse_filter_history',
				$selectFields,
				[ 'afh_id' => $spec, 'afh_filter' => $this->mFilter ],
				__METHOD__
			);
		} elseif ( $spec == 'cur' ) {
			$row = $dbr->selectRow(
				'abuse_filter_history',
				$selectFields,
				[ 'afh_filter' => $this->mFilter ],
				__METHOD__,
				[ 'ORDER BY' => 'afh_timestamp desc' ]
			);
		} elseif ( $spec == 'prev' && !in_array( $otherSpec, $dependentSpecs ) ) {
			// cached
			$other = $this->loadSpec( $otherSpec, $spec );

			$row = $dbr->selectRow(
				'abuse_filter_history',
				$selectFields,
				[
					'afh_filter' => $this->mFilter,
					'afh_id<' . $dbr->addQuotes( $other['meta']['history_id'] ),
				],
				__METHOD__,
				[ 'ORDER BY' => 'afh_timestamp desc' ]
			);
			if ( $other && !$row ) {
				$t = $this->getTitle(
					'history/' . $this->mFilter . '/item/' . $other['meta']['history_id'] );
				$this->getOutput()->redirect( $t->getFullURL() );
				return null;
			}
		} elseif ( $spec == 'next' && !in_array( $otherSpec, $dependentSpecs ) ) {
			// cached
			$other = $this->loadSpec( $otherSpec, $spec );

			$row = $dbr->selectRow(
				'abuse_filter_history',
				$selectFields,
				[
					'afh_filter' => $this->mFilter,
					'afh_id>' . $dbr->addQuotes( $other['meta']['history_id'] ),
				],
				__METHOD__,
				[ 'ORDER BY' => 'afh_timestamp ASC' ]
			);

			if ( $other && !$row ) {
				$t = $this->getTitle(
					'history/' . $this->mFilter . '/item/' . $other['meta']['history_id'] );
				$this->getOutput()->redirect( $t->getFullURL() );
				return null;
			}
		}

		if ( !$row ) {
			return null;
		}

		$data = $this->loadFromHistoryRow( $row );
		$cache[$spec] = $data;
		return $data;
	}

	/**
	 * @param stdClass $row
	 * @return array
	 */
	public function loadFromHistoryRow( $row ) {
		return [
			'meta' => [
				'history_id' => $row->afh_id,
				'modified_by' => $row->afh_user,
				'modified_by_text' => $row->afh_user_text,
				'modified' => $row->afh_timestamp,
			],
			'info' => [
				'description' => $row->afh_public_comments,
				'flags' => $row->afh_flags,
				'notes' => $row->afh_comments,
				'group' => $row->afh_group,
			],
			'pattern' => $row->afh_pattern,
			'actions' => unserialize( $row->afh_actions ),
		];
	}

	/**
	 * @param string $timestamp
	 * @param int $history_id
	 * @return string
	 */
	public function formatVersionLink( $timestamp, $history_id ) {
		$filter = $this->mFilter;
		$text = $this->getLanguage()->timeanddate( $timestamp, true );
		$title = $this->getTitle( "history/$filter/item/$history_id" );

		$link = $this->linkRenderer->makeLink( $title, $text );

		return $link;
	}

	/**
	 * @return string
	 */
	public function formatDiff() {
		$oldVersion = $this->mOldVersion;
		$newVersion = $this->mNewVersion;

		// headings
		$oldLink = $this->formatVersionLink(
			$oldVersion['meta']['modified'],
			$oldVersion['meta']['history_id']
		);
		$newLink = $this->formatVersionLink(
			$newVersion['meta']['modified'],
			$newVersion['meta']['history_id']
		);

		$oldUserLink = Linker::userLink(
			$oldVersion['meta']['modified_by'],
			$oldVersion['meta']['modified_by_text']
		);
		$newUserLink = Linker::userLink(
			$newVersion['meta']['modified_by'],
			$newVersion['meta']['modified_by_text']
		);

		$headings = '';
		$headings .= Xml::tags( 'th', null,
			$this->msg( 'abusefilter-diff-item' )->parse() );
		$headings .= Xml::tags( 'th', null,
			$this->msg( 'abusefilter-diff-version' )
				->rawParams( $oldLink, $oldUserLink )
				->params( $newVersion['meta']['modified_by_text'] )
				->parse()
		);
		$headings .= Xml::tags( 'th', null,
			$this->msg( 'abusefilter-diff-version' )
				->rawParams( $newLink, $newUserLink )
				->params( $newVersion['meta']['modified_by_text'] )
				->parse()
		);

		$headings = Xml::tags( 'tr', null, $headings );

		$body = '';
		// Basic info
		$infoHeader = $this->getHeaderRow( 'abusefilter-diff-info' );
		$info = '';
		$info .= $this->getDiffRow(
			'abusefilter-edit-description',
			$oldVersion['info']['description'],
			$newVersion['info']['description']
		);
		if (
			count( $this->getConfig()->get( 'AbuseFilterValidGroups' ) ) > 1 ||
			$oldVersion['info']['group'] != $newVersion['info']['group']
		) {
			$info .= $this->getDiffRow(
				'abusefilter-edit-group',
				AbuseFilter::nameGroup( $oldVersion['info']['group'] ),
				AbuseFilter::nameGroup( $newVersion['info']['group'] )
			);
		}
		$info .= $this->getDiffRow(
			'abusefilter-edit-flags',
			AbuseFilter::formatFlags( $oldVersion['info']['flags'] ),
			AbuseFilter::formatFlags( $newVersion['info']['flags'] )
		);

		$info .= $this->getDiffRow(
			'abusefilter-edit-notes',
			$oldVersion['info']['notes'],
			$newVersion['info']['notes']
		);

		if ( $info !== '' ) {
			$body .= $infoHeader . $info;
		}

		// Pattern
		$patternHeader = $this->getHeaderRow( 'abusefilter-diff-pattern' );
		$pattern = '';
		$pattern .= $this->getDiffRow(
			'abusefilter-edit-rules',
			$oldVersion['pattern'],
			$newVersion['pattern']
		);

		if ( $pattern !== '' ) {
			$body .= $patternHeader . $pattern;
		}

		// Actions
		$actionsHeader = $this->getHeaderRow( 'abusefilter-edit-consequences' );
		$actions = '';

		$oldActions = $this->stringifyActions( $oldVersion['actions'] );
		$newActions = $this->stringifyActions( $newVersion['actions'] );

		$actions .= $this->getDiffRow(
			'abusefilter-edit-consequences',
			$oldActions,
			$newActions
		);

		if ( $actions !== '' ) {
			$body .= $actionsHeader . $actions;
		}

		$html = "<table class='wikitable'>
			<thead>$headings</thead>
			<tbody>$body</tbody>
		</table>";

		$html = Xml::tags( 'h2', null, $this->msg( 'abusefilter-diff-title' )->parse() ) . $html;

		return $html;
	}

	/**
	 * @param array $actions
	 * @return array
	 */
	public function stringifyActions( $actions ) {
		$lines = [];

		ksort( $actions );
		foreach ( $actions as $action => $parameters ) {
			$lines[] = AbuseFilter::formatAction( $action, $parameters );
		}

		if ( !count( $lines ) ) {
			$lines[] = '';
		}

		return $lines;
	}

	/**
	 * @param string $msg
	 * @return string
	 */
	public function getHeaderRow( $msg ) {
		$html = $this->msg( $msg )->parse();
		$html = Xml::tags( 'th', [ 'colspan' => 3 ], $html );
		$html = Xml::tags( 'tr', [ 'class' => 'mw-abusefilter-diff-header' ], $html );

		return $html;
	}

	/**
	 * @param string $msg
	 * @param array|string $old
	 * @param array|string $new
	 * @return string
	 */
	public function getDiffRow( $msg, $old, $new ) {
		if ( !is_array( $old ) ) {
			$old = explode( "\n", preg_replace( "/\\\r\\\n?/", "\n", $old ) );
		}
		if ( !is_array( $new ) ) {
			$new = explode( "\n", preg_replace( "/\\\r\\\n?/", "\n", $new ) );
		}

		if ( $old === $new ) {
			return '';
		}

		$diffEngine = new DifferenceEngine( $this->getContext() );

		$diffEngine->showDiffStyle();

		$diff = new Diff( $old, $new );
		$formatter = new TableDiffFormatterFullContext();
		$formattedDiff = $diffEngine->addHeader( $formatter->format( $diff ), '', '' );

		return Xml::tags( 'tr', null,
			Xml::tags( 'th', null, $this->msg( $msg )->parse() ) .
			Xml::tags( 'td', [ 'colspan' => 2 ], $formattedDiff )
		) . "\n";
	}
}
