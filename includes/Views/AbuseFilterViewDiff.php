<?php

class AbuseFilterViewDiff extends AbuseFilterView {
	public $mOldVersion = null;
	public $mNewVersion = null;
	public $mNextHistoryId = null;
	public $mFilter = null;

	function show() {
		$show = $this->loadData();
		$out = $this->getOutput();

		$links = [];
		if ( $this->mFilter ) {
			$links['abusefilter-history-backedit'] = $this->getTitle( $this->mFilter );
			$links['abusefilter-diff-backhistory'] = $this->getTitle( 'history/' . $this->mFilter );
		}

		foreach ( $links as $msg => $title ) {
			$links[$msg] = $this->linkRenderer->makeLink( $title, $this->msg( $msg )->text() );
		}

		$backlinks = $this->getLanguage()->pipeList( $links );
		$out->addHTML( Xml::tags( 'p', null, $backlinks ) );

		if ( $show ) {
			$out->addHTML( $this->formatDiff() );

			// Next and previous change links
			$links = [];
			if ( AbuseFilter::getFirstFilterChange( $this->mFilter ) !=
				$this->mOldVersion['meta']['history_id']
			) {
				// Create a "previous change" link if this isn't the first change of the given filter
				$links[] = $this->linkRenderer->makeLink(
					$this->getTitle(
						'history/' . $this->mFilter . '/diff/prev/' . $this->mOldVersion['meta']['history_id']
					),
					$this->getLanguage()->getArrow( 'backwards' ) .
						' ' . $this->msg( 'abusefilter-diff-prev' )->text()
				);
			}

			if ( !is_null( $this->mNextHistoryId ) ) {
				// Create a "next change" link if this isn't the last change of the given filter
				$links[] = $this->linkRenderer->makeLink(
					$this->getTitle(
						'history/' . $this->mFilter . '/diff/prev/' . $this->mNextHistoryId
					),
					$this->msg( 'abusefilter-diff-next' )->text() .
						' ' . $this->getLanguage()->getArrow( 'forwards' )
				);
			}

			if ( count( $links ) > 0 ) {
				$backlinks = $this->getLanguage()->pipeList( $links );
				$out->addHTML( Xml::tags( 'p', null, $backlinks ) );
			}
		}
	}

	function loadData() {
		$oldSpec = $this->mParams[3];
		$newSpec = $this->mParams[4];
		$this->mFilter = $this->mParams[1];

		$this->mOldVersion = $this->loadSpec( $oldSpec, $newSpec );
		$this->mNewVersion = $this->loadSpec( $newSpec, $oldSpec );

		if ( is_null( $this->mOldVersion ) || is_null( $this->mNewVersion ) ) {
			$this->getOutput()->addWikiMsg( 'abusefilter-diff-invalid' );
			return false;
		}

		if ( !self::canViewPrivate() &&
			(
				in_array( 'hidden', explode( ',', $this->mOldVersion['info']['flags'] ) ) ||
				in_array( 'hidden', explode( ',', $this->mNewVersion['info']['flags'] ) )
			)
		) {
			$this->getOutput()->addWikiMsg( 'abusefilter-history-error-hidden' );
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
	function getNextHistoryId( $historyId ) {
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

	function loadSpec( $spec, $otherSpec ) {
		static $dependentSpecs = [ 'prev', 'next' ];
		static $cache = [];

		if ( isset( $cache[$spec] ) ) {
			return $cache[$spec];
		}

		$dbr = wfGetDB( DB_REPLICA );
		$row = null;
		if ( is_numeric( $spec ) ) {
			$row = $dbr->selectRow(
				'abuse_filter_history',
				'*',
				[ 'afh_id' => $spec, 'afh_filter' => $this->mFilter ],
				__METHOD__
			);
		} elseif ( $spec == 'cur' ) {
			$row = $dbr->selectRow(
				'abuse_filter_history',
				'*',
				[ 'afh_filter' => $this->mFilter ],
				__METHOD__,
				[ 'ORDER BY' => 'afh_timestamp desc' ]
			);
		} elseif ( $spec == 'prev' && !in_array( $otherSpec, $dependentSpecs ) ) {
			// cached
			$other = $this->loadSpec( $otherSpec, $spec );

			$row = $dbr->selectRow(
				'abuse_filter_history',
				'*',
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
				'*',
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

	function loadFromHistoryRow( $row ) {
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
	function formatVersionLink( $timestamp, $history_id ) {
		$filter = $this->mFilter;
		$text = $this->getLanguage()->timeanddate( $timestamp, true );
		$title = $this->getTitle( "history/$filter/item/$history_id" );

		$link = $this->linkRenderer->makeLink( $title, $text );

		return $link;
	}

	/**
	 * @return string
	 */
	function formatDiff() {
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

		// Basic info
		$info = '';
		$info .= $this->getHeaderRow( 'abusefilter-diff-info' );
		$info .= $this->getDiffRow(
			'abusefilter-edit-description',
			$oldVersion['info']['description'],
			$newVersion['info']['description']
		);
		global $wgAbuseFilterValidGroups;
		if (
			count( $wgAbuseFilterValidGroups ) > 1 ||
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

		// Pattern
		$info .= $this->getHeaderRow( 'abusefilter-diff-pattern' );
		$info .= $this->getDiffRow(
			'abusefilter-edit-rules',
			$oldVersion['pattern'],
			$newVersion['pattern'],
			'text'
		);

		// Actions
		$oldActions = $this->stringifyActions( $oldVersion['actions'] );
		$newActions = $this->stringifyActions( $newVersion['actions'] );

		$info .= $this->getHeaderRow( 'abusefilter-edit-consequences' );
		$info .= $this->getDiffRow(
			'abusefilter-edit-consequences',
			$oldActions,
			$newActions
		);

		$html = "<table class='wikitable'>
			<thead>$headings</thead>
			<tbody>$info</tbody>
		</table>";

		$html = Xml::tags( 'h2', null, $this->msg( 'abusefilter-diff-title' )->parse() ) . $html;

		return $html;
	}

	/**
	 * @param array $actions
	 * @return array
	 */
	function stringifyActions( $actions ) {
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
	function getHeaderRow( $msg ) {
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
	function getDiffRow( $msg, $old, $new ) {
		if ( !is_array( $old ) ) {
			$old = explode( "\n", preg_replace( "/\\\r\\\n?/", "\n", $old ) );
		}
		if ( !is_array( $new ) ) {
			$new = explode( "\n", preg_replace( "/\\\r\\\n?/", "\n", $new ) );
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
