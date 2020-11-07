<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager as PermManager;
use MediaWiki\Extension\AbuseFilter\BlockAutopromoteStore;
use MediaWiki\Extension\AbuseFilter\CentralDBManager;
use MediaWiki\Extension\AbuseFilter\ChangeTagger;
use MediaWiki\Extension\AbuseFilter\ChangeTagsManager;
use MediaWiki\Extension\AbuseFilter\FilterCompare;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\FilterProfiler;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use MediaWiki\Extension\AbuseFilter\FilterValidator;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\Parser\ParserFactory;
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
			$services->getDBLoadBalancer(),
			$services->getMainWANObjectCache(),
			$services->get( CentralDBManager::SERVICE_NAME )
		);
	},
	CentralDBManager::SERVICE_NAME => function ( MediaWikiServices $services ): CentralDBManager {
		return new CentralDBManager(
			$services->getDBLoadBalancerFactory(),
			$services->getMainConfig()->get( 'AbuseFilterCentralDB' ),
			$services->getMainConfig()->get( 'AbuseFilterIsCentral' )
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
	ParserFactory::SERVICE_NAME => function ( MediaWikiServices $services ): ParserFactory {
		return new ParserFactory(
			$services->getContentLanguage(),
			// We could use $services here, but we need the fallback
			ObjectCache::getLocalServerInstance( 'hash' ),
			LoggerFactory::getInstance( 'AbuseFilter' ),
			$services->getService( KeywordsManager::SERVICE_NAME ),
			$services->getMainConfig()->get( 'AbuseFilterParserClass' )
		);
	},
	FilterLookup::SERVICE_NAME => function ( MediaWikiServices $services ): FilterLookup {
		return new FilterLookup(
			$services->getDBLoadBalancer(),
			$services->getMainWANObjectCache(),
			$services->get( CentralDBManager::SERVICE_NAME )
		);
	},
	FilterValidator::SERVICE_NAME => function ( MediaWikiServices $services ): FilterValidator {
		return new FilterValidator(
			$services->get( ChangeTagsManager::SERVICE_NAME ),
			$services->get( ParserFactory::SERVICE_NAME ),
			$services->get( PermManager::SERVICE_NAME ),
			// Pass the cleaned list of enabled restrictions
			array_keys( array_filter( $services->getMainConfig()->get( 'AbuseFilterActionRestrictions' ) ) )
		);
	},
	FilterCompare::SERVICE_NAME => function ( MediaWikiServices $services ): FilterCompare {
		return new FilterCompare(
			array_keys( array_filter( $services->getMainConfig()->get( 'AbuseFilterActions' ) ) )
		);
	},
];
