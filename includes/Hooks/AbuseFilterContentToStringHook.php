<?php

namespace MediaWiki\Extension\AbuseFilter\Hooks;

use Content;

interface AbuseFilterContentToStringHook {
	/**
	 * Hook runner for the `AbuseFilter-contentToString` hook
	 *
	 * Called when converting a Content object to a string to which
	 * filters can be applied. If the hook function returns true, Content::getTextForSearchIndex()
	 * will be used for non-text content.
	 *
	 * @param Content $content
	 * @param ?string &$text
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onAbuseFilterContentToString(
		Content $content,
		?string &$text
	);
}
