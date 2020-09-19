<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
use MediaWiki\MediaWikiServices;

class AbuseFilterServices {

	/**
	 * @return KeywordsManager
	 */
	public static function getKeywordsManager() : KeywordsManager {
		return MediaWikiServices::getInstance()->getService( KeywordsManager::SERVICE_NAME );
	}

	/**
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

	/**
	 * @return ChangeTagger
	 */
	public static function getChangeTagger() : ChangeTagger {
		return MediaWikiServices::getInstance()->getService( ChangeTagger::SERVICE_NAME );
	}

	/**
	 * @return ChangeTagsManager
	 */
	public static function getChangeTagsManager() : ChangeTagsManager {
		return MediaWikiServices::getInstance()->getService( ChangeTagsManager::SERVICE_NAME );
	}

	/**
	 * @return BlockAutopromoteStore
	 */
	public static function getBlockAutopromoteStore() : BlockAutopromoteStore {
		return MediaWikiServices::getInstance()->getService( BlockAutopromoteStore::SERVICE_NAME );
	}

	/**
	 * @return FilterUser
	 */
	public static function getFilterUser() : FilterUser {
		return MediaWikiServices::getInstance()->getService( FilterUser::SERVICE_NAME );
	}

	/**
	 * @return CentralDBManager
	 */
	public static function getCentralDBManager() : CentralDBManager {
		return MediaWikiServices::getInstance()->getService( CentralDBManager::SERVICE_NAME );
	}

	/**
	 * @return ParserFactory
	 */
	public static function getParserFactory() : ParserFactory {
		return MediaWikiServices::getInstance()->getService( ParserFactory::SERVICE_NAME );
	}

	/**
	 * @return FilterLookup
	 */
	public static function getFilterLookup() : FilterLookup {
		return MediaWikiServices::getInstance()->getService( FilterLookup::SERVICE_NAME );
	}

	/**
	 * @return EmergencyWatcher
	 */
	public static function getEmergencyWatcher() : EmergencyWatcher {
		return MediaWikiServices::getInstance()->getService( EmergencyWatcher::SERVICE_NAME );
	}

	/**
	 * @return FilterValidator
	 */
	public static function getFilterValidator() : FilterValidator {
		return MediaWikiServices::getInstance()->getService( FilterValidator::SERVICE_NAME );
	}

	/**
	 * @return FilterCompare
	 */
	public static function getFilterCompare() : FilterCompare {
		return MediaWikiServices::getInstance()->getService( FilterCompare::SERVICE_NAME );
	}

	/**
	 * @return FilterImporter
	 */
	public static function getFilterImporter() : FilterImporter {
		return MediaWikiServices::getInstance()->getService( FilterImporter::SERVICE_NAME );
	}

	/**
	 * @return FilterStore
	 */
	public static function getFilterStore() : FilterStore {
		return MediaWikiServices::getInstance()->getService( FilterStore::SERVICE_NAME );
	}
}
