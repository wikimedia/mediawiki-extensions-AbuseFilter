<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

class UserMergeHandler {

	/**
	 * Tables that Extension:UserMerge needs to update
	 * @todo Use new hook system once UserMerge is updated
	 *
	 * @param array &$updateFields
	 */
	public static function onUserMergeAccountFields( array &$updateFields ) {
		global $wgAbuseFilterActorTableSchemaMigrationStage;
		$updateFields[] = [
			'abuse_filter',
			'af_user',
			'af_user_text',
			'batchKey' => 'af_id',
			'actorId' => 'af_actor',
			'actorStage' => $wgAbuseFilterActorTableSchemaMigrationStage,
		];
		$updateFields[] = [
			'abuse_filter_log',
			'afl_user',
			'afl_user_text',
			'batchKey' => 'afl_id',
		];
		$updateFields[] = [
			'abuse_filter_history',
			'afh_user',
			'afh_user_text',
			'batchKey' => 'afh_id',
			'actorId' => 'afh_actor',
			'actorStage' => $wgAbuseFilterActorTableSchemaMigrationStage,
		];
	}

}
