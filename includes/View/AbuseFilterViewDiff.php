<?php

namespace MediaWiki\Extension\AbuseFilter\View;

use AbuseFilter;
use Diff;
use DifferenceEngine;
use IContextSource;
use Linker;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\SpecsFormatter;
use MediaWiki\Linker\LinkRenderer;
use OOUI;
use stdClass;
use TableDiffFormatterFullContext;
use Xml;

/**
 * @phan-file-suppress PhanTypeArraySuspiciousNullable Some confusion with class members
 */
class AbuseFilterViewDiff extends AbuseFilterView {
	/**
	 * @var (string|array)[]|null The old version of the filter
	 */
	public $mOldVersion = null;
	/**
	 * @var (string|array)[]|null The new version of the filter
	 */
	public $mNewVersion = null;
	/**
	 * @var int|null The history ID of the next version, if any
	 */
	public $mNextHistoryId = null;
	/**
	 * @var int|null The ID of the filter
	 */
	private $filter;
	/**
	 * @var SpecsFormatter
	 */
	private $specsFormatter;

	/**
	 * @param AbuseFilterPermissionManager $afPermManager
	 * @param SpecsFormatter $specsFormatter
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param string $basePageName
	 * @param array $params
	 */
	public function __construct(
		AbuseFilterPermissionManager $afPermManager,
		SpecsFormatter $specsFormatter,
		IContextSource $context,
		LinkRenderer $linkRenderer,
		string $basePageName,
		array $params
	) {
		parent::__construct( $afPermManager, $context, $linkRenderer, $basePageName, $params );
		$this->specsFormatter = $specsFormatter;
		$this->specsFormatter->setMessageLocalizer( $this->getContext() );
	}

	/**
	 * Shows the page
	 */
	public function show() {
		$show = $this->loadData();
		$out = $this->getOutput();
		$out->enableOOUI();
		$out->addModuleStyles( [ 'oojs-ui.styles.icons-movement' ] );

		$links = [];
		if ( $this->filter ) {
			$links['abusefilter-history-backedit'] =
				$this->getTitle( $this->filter )->getFullURL();
			$links['abusefilter-diff-backhistory'] =
				$this->getTitle( 'history/' . $this->filter )->getFullURL();
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
			if ( AbuseFilter::getFirstFilterChange( $this->filter ) !=
				$this->mOldVersion['meta']['history_id']
			) {
				// Create a "previous change" link if this isn't the first change of the given filter
				$href = $this->getTitle(
					'history/' . $this->filter . '/diff/prev/' . $this->mOldVersion['meta']['history_id']
				)->getFullURL();
				$buttons[] = new OOUI\ButtonWidget( [
					'label' => $this->msg( 'abusefilter-diff-prev' )->text(),
					'href' => $href,
					'icon' => 'previous'
				] );
			}

			if ( $this->mNextHistoryId !== null ) {
				// Create a "next change" link if this isn't the last change of the given filter
				$href = $this->getTitle(
					'history/' . $this->filter . '/diff/prev/' . $this->mNextHistoryId
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

		if ( !is_numeric( $this->mParams[1] ) ) {
			$this->getOutput()->addWikiMsg( 'abusefilter-diff-invalid' );
			return false;
		}
		$this->filter = (int)$this->mParams[1];

		$this->mOldVersion = $this->loadSpec( $oldSpec, $newSpec );
		$this->mNewVersion = $this->loadSpec( $newSpec, $oldSpec );

		if ( $this->mOldVersion === null || $this->mNewVersion === null ) {
			$this->getOutput()->addWikiMsg( 'abusefilter-diff-invalid' );
			return false;
		}

		if ( !$this->afPermManager->canViewPrivateFilters( $this->getUser() ) &&
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
	public function getNextHistoryId( $historyId ) {
		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow(
			'abuse_filter_history',
			'afh_id',
			[
				'afh_filter' => $this->filter,
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
	 * @return (string|array)[]|null
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
				[ 'afh_id' => $spec, 'afh_filter' => $this->filter ],
				__METHOD__
			);
		} elseif ( $spec === 'cur' ) {
			$row = $dbr->selectRow(
				'abuse_filter_history',
				$selectFields,
				[ 'afh_filter' => $this->filter ],
				__METHOD__,
				[ 'ORDER BY' => 'afh_timestamp desc' ]
			);
		} elseif ( ( $spec === 'prev' || $spec === 'next' ) &&
			!in_array( $otherSpec, $dependentSpecs )
		) {
			// cached
			$other = $this->loadSpec( $otherSpec, $spec );

			$comparison = $spec === 'prev' ? '<' : '>';
			$order = $spec === 'prev' ? 'DESC' : 'ASC';
			$row = $dbr->selectRow(
				'abuse_filter_history',
				$selectFields,
				[
					'afh_filter' => $this->filter,
					"afh_id $comparison" . $dbr->addQuotes( $other['meta']['history_id'] ),
				],
				__METHOD__,
				[ 'ORDER BY' => "afh_timestamp $order" ]
			);

			if ( $other && !$row ) {
				$t = $this->getTitle(
					'history/' . $this->filter . '/item/' . $other['meta']['history_id'] );
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
	 * @return (string|array)[]
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
		$filter = $this->filter;
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

		$headings = Xml::tags( 'th', null,
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
		$info = $this->getDiffRow(
			'abusefilter-edit-description',
			$oldVersion['info']['description'],
			$newVersion['info']['description']
		);
		if (
			count( $this->getConfig()->get( 'AbuseFilterValidGroups' ) ) > 1 ||
			$oldVersion['info']['group'] !== $newVersion['info']['group']
		) {
			$info .= $this->getDiffRow(
				'abusefilter-edit-group',
				$this->specsFormatter->nameGroup( $oldVersion['info']['group'] ),
				$this->specsFormatter->nameGroup( $newVersion['info']['group'] )
			);
		}
		$info .= $this->getDiffRow(
			'abusefilter-edit-flags',
			$this->specsFormatter->formatFlags( $oldVersion['info']['flags'], $this->getLanguage() ),
			$this->specsFormatter->formatFlags( $newVersion['info']['flags'], $this->getLanguage() )
		);

		$info .= $this->getDiffRow(
			'abusefilter-edit-notes',
			$oldVersion['info']['notes'],
			$newVersion['info']['notes']
		);

		if ( $info !== '' ) {
			$body .= $this->getHeaderRow( 'abusefilter-diff-info' ) . $info;
		}

		$pattern = $this->getDiffRow(
			'abusefilter-edit-rules',
			$oldVersion['pattern'],
			$newVersion['pattern']
		);

		if ( $pattern !== '' ) {
			$body .= $this->getHeaderRow( 'abusefilter-diff-pattern' ) . $pattern;
		}

		$actions = $this->getDiffRow(
			'abusefilter-edit-consequences',
			$this->stringifyActions( $oldVersion['actions'] ) ?: [ '' ],
			$this->stringifyActions( $newVersion['actions'] ) ?: [ '' ]
		);

		if ( $actions !== '' ) {
			$body .= $this->getHeaderRow( 'abusefilter-edit-consequences' ) . $actions;
		}

		$html = "<table class='wikitable'>
			<thead>$headings</thead>
			<tbody>$body</tbody>
		</table>";

		$html = Xml::tags( 'h2', null, $this->msg( 'abusefilter-diff-title' )->parse() ) . $html;

		return $html;
	}

	/**
	 * @param string[][] $actions
	 * @return string[]
	 */
	private function stringifyActions( array $actions ) : array {
		$lines = [];

		ksort( $actions );
		foreach ( $actions as $action => $parameters ) {
			$lines[] = $this->specsFormatter->formatAction( $action, $parameters, $this->getLanguage() );
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
