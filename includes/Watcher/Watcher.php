<?php

namespace MediaWiki\Extension\AbuseFilter\Watcher;

/**
 * Classes inheriting this interface can be used to execute some actions after all filter have been checked.
 */
interface Watcher {
	/**
	 * @param string[] $filters The filters that matched the action
	 * @param string $group
	 */
	public function run( array $filters, string $group ) : void;
}
