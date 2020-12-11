<?php

namespace MediaWiki\Extension\AbuseFilter\View;

use HTMLForm;
use IContextSource;
use LogEventsList;
use LogPage;
use ManualLogEntry;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Linker\LinkRenderer;
use Xml;

class HideAbuseLog extends AbuseFilterView {

	/** @var int */
	private $hideID;

	/**
	 * @param AbuseFilterPermissionManager $afPermManager
	 * @param IContextSource $context
	 * @param LinkRenderer $linkRenderer
	 * @param string $basePageName
	 */
	public function __construct(
		AbuseFilterPermissionManager $afPermManager,
		IContextSource $context,
		LinkRenderer $linkRenderer,
		string $basePageName
	) {
		parent::__construct( $afPermManager, $context, $linkRenderer, $basePageName, [] );
		$this->hideID = $this->getRequest()->getIntOrNull( 'id' );
	}

	/**
	 * Shows the page
	 */
	public function show() : void {
		$output = $this->getOutput();
		$output->enableOOUI();

		if ( !$this->afPermManager->canHideAbuseLog( $this->getUser() ) ) {
			$output->addWikiMsg( 'abusefilter-log-hide-forbidden' );
			return;
		}

		$dbr = wfGetDB( DB_REPLICA );

		$deleted = $dbr->selectField(
			'abuse_filter_log',
			'afl_deleted',
			[ 'afl_id' => $this->hideID ],
			__METHOD__
		);

		if ( $deleted === false ) {
			$output->addWikiMsg( 'abusefilter-log-nonexistent' );
			return;
		}

		$hideReasonsOther = $this->msg( 'revdelete-reasonotherlist' )->text();
		$hideReasons = $this->msg( 'revdelete-reason-dropdown-suppress' )->inContentLanguage()->text();
		$hideReasons = Xml::listDropDownOptions( $hideReasons, [ 'other' => $hideReasonsOther ] );

		$formInfo = [
			'showorhide' => [
				'type' => 'radio',
				'label-message' => 'abusefilter-log-hide-set-visibility',
				'options-messages' => [
					'abusefilter-log-hide-show' => 'show',
					'abusefilter-log-hide-hide' => 'hide'
				],
				'default' => (int)$deleted === 0 ? 'show' : 'hide',
				'flatlist' => true
			],
			'logid' => [
				'type' => 'info',
				'default' => (string)$this->hideID,
				'label-message' => 'abusefilter-log-hide-id',
			],
			'dropdownreason' => [
				'type' => 'select',
				'options' => $hideReasons,
				'label-message' => 'abusefilter-log-hide-reason'
			],
			'reason' => [
				'type' => 'text',
				'label-message' => 'abusefilter-log-hide-reason-other',
			],
		];

		HTMLForm::factory( 'ooui', $formInfo, $this->getContext() )
			->setAction( $this->getTitle( 'hide' )->getFullURL( [ 'id' => $this->hideID ] ) )
			->setWrapperLegend( $this->msg( 'abusefilter-log-hide-legend' )->text() )
			->addHiddenField( 'hide', $this->hideID )
			->setSubmitCallback( [ $this, 'saveHideForm' ] )
			->showAlways();

		// Show suppress log for this entry
		$suppressLogPage = new LogPage( 'suppress' );
		$output->addHTML( "<h2>" . $suppressLogPage->getName()->escaped() . "</h2>\n" );
		LogEventsList::showLogExtract( $output, 'suppress', $this->getTitle( (string)$this->hideID ) );
	}

	/**
	 * @param array $fields
	 * @return bool
	 */
	public function saveHideForm( array $fields ) : bool {
		$logid = $this->getRequest()->getVal( 'hide' );

		$newValue = $fields['showorhide'] === 'hide' ? 1 : 0;
		$dbw = wfGetDB( DB_MASTER );

		$dbw->update(
			'abuse_filter_log',
			[ 'afl_deleted' => $newValue ],
			[ 'afl_id' => $logid ],
			__METHOD__
		);

		$reason = $fields['dropdownreason'];
		if ( $reason === 'other' ) {
			$reason = $fields['reason'];
		} elseif ( $fields['reason'] !== '' ) {
			$reason .=
				$this->msg( 'colon-separator' )->inContentLanguage()->text() . $fields['reason'];
		}

		$action = $fields['showorhide'] === 'hide' ? 'hide-afl' : 'unhide-afl';
		$logEntry = new ManualLogEntry( 'suppress', $action );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $this->getTitle( $logid ) );
		$logEntry->setComment( $reason );
		$logEntry->insert();

		return true;
	}
}
