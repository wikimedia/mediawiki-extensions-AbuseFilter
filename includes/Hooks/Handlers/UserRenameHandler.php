<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks\Handlers;

use RenameuserSQL;
use RenameUserSQLHook;

class UserRenameHandler implements RenameUserSQLHook {

	/**
	 * @inheritDoc
	 */
	public function onRenameUserSQL( RenameuserSQL $renameUserSql ) : void {
		$renameUserSql->tablesJob['abuse_filter'] = [
			RenameuserSQL::NAME_COL => 'af_user_text',
			RenameuserSQL::UID_COL => 'af_user',
			RenameuserSQL::TIME_COL => 'af_timestamp',
			'uniqueKey' => 'af_id'
		];
		$renameUserSql->tablesJob['abuse_filter_history'] = [
			RenameuserSQL::NAME_COL => 'afh_user_text',
			RenameuserSQL::UID_COL => 'afh_user',
			RenameuserSQL::TIME_COL => 'afh_timestamp',
			'uniqueKey' => 'afh_id'
		];
	}

	/**
	 * Tables that Extension:UserMerge needs to update
	 * @todo Use new hook system once UserMerge is updated
	 *
	 * @param array &$updateFields
	 */
	public static function onUserMergeAccountFields( array &$updateFields ) {
		$updateFields[] = [ 'abuse_filter', 'af_user', 'af_user_text' ];
		$updateFields[] = [ 'abuse_filter_log', 'afl_user', 'afl_user_text' ];
		$updateFields[] = [ 'abuse_filter_history', 'afh_user', 'afh_user_text' ];
	}

}
