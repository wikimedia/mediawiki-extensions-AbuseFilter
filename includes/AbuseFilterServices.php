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

	/**
	 * Conveniency wrapper for strong typing
	 * @return FilterProfiler
	 */
	public static function getFilterProfiler() : FilterProfiler {
		return MediaWikiServices::getInstance()->getService( FilterProfiler::SERVICE_NAME );
	}

	/**
	 * @return AbuseFilterPermissionManager
	 */
	public static function getPermissionManager() : AbuseFilterPermissionManager {
		return MediaWikiServices::getInstance()->getService( AbuseFilterPermissionManager::SERVICE_NAME );
	}
}
