<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit;

use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\EditBox\EditBoxBuilder;
use MediaWiki\Extension\AbuseFilter\EditBox\EditBoxBuilderFactory;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWikiUnitTestCase;
use MessageLocalizer;
use OutputPage;
use User;

/**
 * @coversDefaultClass \MediaWiki\Extension\AbuseFilter\Editbox\EditBoxBuilderFactory
 */
class EditBoxBuilderFactoryTest extends MediaWikiUnitTestCase {

	/**
	 * @covers ::__construct
	 * @covers ::newEditBoxBuilder
	 * @covers \MediaWiki\Extension\AbuseFilter\EditBox\EditBoxBuilder::__construct
	 * @dataProvider provideNewEditBoxBuilder
	 * @param bool $isCodeEditorLoaded
	 */
	public function testNewEditBoxBuilder( bool $isCodeEditorLoaded ) {
		$factory = new EditBoxBuilderFactory(
			$this->createMock( AbuseFilterPermissionManager::class ),
			$this->createMock( KeywordsManager::class ),
			$isCodeEditorLoaded
		);
		$builder = $factory->newEditBoxBuilder(
			$this->createMock( MessageLocalizer::class ),
			$this->createMock( User::class ),
			$this->createMock( OutputPage::class )
		);
		$this->assertInstanceOf( EditBoxBuilder::class, $builder );
	}

	public function provideNewEditBoxBuilder() : array {
		return [
			[ true ],
			[ false ]
		];
	}

}
