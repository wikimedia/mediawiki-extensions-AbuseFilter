<?php

use MediaWiki\Cache\LinkBatchFactory;
use Wikimedia\Rdbms\IResultWrapper;

class AbuseLogPager extends ReverseChronologicalPager {
	/**
	 * @var SpecialAbuseLog
	 */
	public $mForm;

	/**
	 * @var array
	 */
	public $mConds;

	/** @var LinkBatchFactory */
	private $linkBatchFactory;

	/**
	 * @param SpecialAbuseLog $form
	 * @param array $conds
	 * @param LinkBatchFactory $linkBatchFactory
	 */
	public function __construct( SpecialAbuseLog $form, $conds, LinkBatchFactory $linkBatchFactory ) {
		$this->mForm = $form;
		$this->mConds = $conds;
		parent::__construct();
		$this->linkBatchFactory = $linkBatchFactory;
	}

	/**
	 * @param object $row
	 * @return string
	 */
	public function formatRow( $row ) {
		return $this->mForm->formatRow( $row );
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
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

		if ( !$this->mForm->canSeeHidden( $this->getUser() ) ) {
			$info['conds']['afl_deleted'] = 0;
		}

		return $info;
	}

	/**
	 * @param IResultWrapper $result
	 */
	protected function preprocessResults( $result ) {
		if ( $this->getNumRows() === 0 ) {
			return;
		}

		$lb = $this->linkBatchFactory->newLinkBatch();
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

	/**
	 * @return string
	 */
	public function getIndexField() {
		return 'afl_timestamp';
	}
}
