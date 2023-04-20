<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration;

use MediaWiki\Tests\ExtensionJsonTestBase;

/**
 * @group Test
 * @group AbuseFilter
 * @coversNothing
 */
class AbuseFilterExtensionJsonTest extends ExtensionJsonTestBase {

	/** @inheritDoc */
	protected string $extensionJsonPath = __DIR__ . '/../../../extension.json';

}
