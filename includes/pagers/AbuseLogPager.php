<?php

use Wikimedia\Rdbms\ResultWrapper;

class AbuseLogPager extends ReverseChronologicalPager {
	/**
	 * @var SpecialAbuseLog
	 */
	public $mForm;

	/**
	 * @var array
	 */
	public $mConds;

	/**
	 * @param SpecialAbuseLog $form
	 * @param array $conds
	 */
	function __construct( $form, $conds = [] ) {
		$this->mForm = $form;
		$this->mConds = $conds;
		parent::__construct();
	}

	function formatRow( $row ) {
		return $this->mForm->formatRow( $row );
	}

	function getQueryInfo() {
		$conds = $this->mConds;

		$info = [
			'tables' => [ 'abuse_filter_log', 'abuse_filter' ],
			'fields' => '*',
			'conds' => $conds,
			'join_conds' =>
				[ 'abuse_filter' =>
					[
						'LEFT JOIN',
						'af_id=afl_filter',
					],
				],
		];

		if ( !$this->mForm->canSeeHidden() ) {
			$db = $this->mDb;
			$info['conds'][] = SpecialAbuseLog::getNotDeletedCond( $db );
		}

		return $info;
	}

	/**
	 * @param ResultWrapper $result
	 */
	protected function preprocessResults( $result ) {
		if ( $this->getNumRows() === 0 ) {
			return;
		}

		$lb = new LinkBatch();
		$lb->setCaller( __METHOD__ );
		foreach ( $result as $row ) {
			// Only for local wiki results
			if ( !$row->afl_wiki ) {
				$lb->add( $row->afl_namespace, $row->afl_title );
				$lb->add( NS_USER,  $row->afl_user );
				$lb->add( NS_USER_TALK, $row->afl_user_text );
			}
		}
		$lb->execute();
		$result->seek( 0 );
	}

	function getIndexField() {
		return 'afl_timestamp';
	}
}
