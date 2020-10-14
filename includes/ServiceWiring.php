<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager as PermManager;
use MediaWiki\Extension\AbuseFilter\ChangeTagger;
use MediaWiki\Extension\AbuseFilter\ChangeTagsManager;
use MediaWiki\Extension\AbuseFilter\FilterProfiler;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	KeywordsManager::SERVICE_NAME => function ( MediaWikiServices $services ): KeywordsManager {
		return new KeywordsManager(
			new AbuseFilterHookRunner( $services->getHookContainer() )
		);
	},
	FilterProfiler::SERVICE_NAME => function ( MediaWikiServices $services ): FilterProfiler {
		return new FilterProfiler(
			$services->getMainObjectStash(),
			new ServiceOptions(
				FilterProfiler::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			WikiMap::getCurrentWikiDbDomain()->getId(),
			$services->getStatsdDataFactory(),
			LoggerFactory::getInstance( 'AbuseFilter' )
		);
	},
	PermManager::SERVICE_NAME => function ( MediaWikiServices $services ): PermManager {
		return new PermManager( $services->getPermissionManager() );
	},
	ChangeTagger::SERVICE_NAME => function ( MediaWikiServices $services ) : ChangeTagger {
		return new ChangeTagger();
	},
	ChangeTagsManager::SERVICE_NAME => function ( MediaWikiServices $services ): ChangeTagsManager {
		return new ChangeTagsManager(
			$services->getDBLoadBalancerFactory(),
			$services->getMainWANObjectCache(),
			new ServiceOptions(
				ChangeTagsManager::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	},
];
