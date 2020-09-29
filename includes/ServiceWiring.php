<?php

use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\MediaWikiServices;

return [
	KeywordsManager::SERVICE_NAME => function ( MediaWikiServices $services ): KeywordsManager {
		return new KeywordsManager(
			new AbuseFilterHookRunner( $services->getHookContainer() )
		);
	},
];
