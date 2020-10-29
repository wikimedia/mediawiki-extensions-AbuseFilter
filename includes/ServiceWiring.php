<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager as PermManager;
use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Extension\AbuseFilter\ChangeTagger;
use MediaWiki\Extension\AbuseFilter\ChangeTagsManager;
use MediaWiki\Extension\AbuseFilter\FilterProfiler;
use MediaWiki\Extension\AbuseFilter\FilterUser;
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
		return new ChangeTagger(
			$services->getService( ChangeTagsManager::SERVICE_NAME )
		);
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
	BlockAutopromoteStore::SERVICE_NAME => function ( MediaWikiServices $services ): BlockAutopromoteStore {
		return new BlockAutopromoteStore(
			ObjectCache::getInstance( 'db-replicated' ),
			LoggerFactory::getInstance( 'AbuseFilter' ),
			$services->get( FilterUser::SERVICE_NAME )
		);
	},
	FilterUser::SERVICE_NAME => function ( MediaWikiServices $services ): FilterUser {
		return new FilterUser(
			// TODO We need a proper MessageLocalizer, see T247127
			RequestContext::getMain(),
			$services->getUserGroupManager(),
			LoggerFactory::getInstance( 'AbuseFilter' )
		);
	},
];
