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

	/** @var bool */
	private $joinWithArchive;

	/**
	 * @param SpecialAbuseLog $form
	 * @param array $conds
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param bool $joinWithArchive
	 */
	public function __construct(
		SpecialAbuseLog $form,
		array $conds,
		LinkBatchFactory $linkBatchFactory,
		bool $joinWithArchive = false
	) {
		parent::__construct( $form->getContext(), $form->getLinkRenderer() );
		$this->mForm = $form;
		$this->mConds = $conds;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->joinWithArchive = $joinWithArchive;
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
			'tables' => [ 'abuse_filter_log', 'abuse_filter', 'revision' ],
			'fields' => [
				$this->mDb->tableName( 'abuse_filter_log' ) . '.*',
				$this->mDb->tableName( 'abuse_filter' ) . '.*',
				'rev_id',
			],
			'conds' => $conds,
			'join_conds' => [
				'abuse_filter' => [
					'LEFT JOIN',
					'af_id=afl_filter',
				],
				'revision' => [
					'LEFT JOIN',
					[
						'afl_wiki IS NULL',
						'afl_rev_id IS NOT NULL',
						'rev_id=afl_rev_id',
					]
				],
			],
		];

		if ( $this->joinWithArchive ) {
			$info['tables'][] = 'archive';
			$info['fields'][] = 'ar_timestamp';
			$info['join_conds']['archive'] = [
				'LEFT JOIN',
				[
					'afl_wiki IS NULL',
					'afl_rev_id IS NOT NULL',
					'rev_id IS NULL',
					'ar_rev_id=afl_rev_id',
				]
			];
		}

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
