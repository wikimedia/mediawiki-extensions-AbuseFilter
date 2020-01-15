<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\MediaWikiServices;

class AbuseFilterServices {
	/**
	 * Conveniency wrapper for strong typing
	 * @return KeywordsManager
	 */
	public static function getKeywordsManager() : KeywordsManager {
		return MediaWikiServices::getInstance()->getService( KeywordsManager::SERVICE_NAME );
	}
}
