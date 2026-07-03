<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Integration\EditBox;

use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\EditBox\PlainEditBoxBuilder;
use MediaWiki\Output\OutputPage;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiIntegrationTestCase;

/**
 * @group AbuseFilter
 * @covers \MediaWiki\Extension\AbuseFilter\EditBox\EditBoxBuilder
 * @covers \MediaWiki\Extension\AbuseFilter\EditBox\PlainEditBoxBuilder
 */
class EditBoxBuilderTest extends MediaWikiIntegrationTestCase {
	use MockAuthorityTrait;

	private function getOutputPage(): OutputPage {
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setLanguage( 'qqx' );
		return new OutputPage( $context );
	}

	private function getBuilder( OutputPage $output, bool $canEdit ): PlainEditBoxBuilder {
		$permManager = $this->createMock( AbuseFilterPermissionManager::class );
		$permManager->method( 'canEdit' )->willReturn( $canEdit );
		return new PlainEditBoxBuilder(
			$permManager,
			AbuseFilterServices::getKeywordsManager(),
			$output->getContext(),
			$this->mockRegisteredUltimateAuthority(),
			$output
		);
	}

	public function testBuildEditBoxForPrivilegedUser(): void {
		$output = $this->getOutputPage();
		$html = $this->getBuilder( $output, true )->buildEditBox( 'true' );

		$this->assertStringContainsString( 'wpFilterRules', $html );
		$this->assertStringNotContainsString( 'readonly', $html );

		// Placeholder input, disabled until the picker loads
		$this->assertStringContainsString( 'mw-abusefilter-condition-builder', $html );
		$this->assertMatchesRegularExpression(
			'/<input class="cdx-text-input__input"[^>]* disabled/',
			$html
		);
		$this->assertStringContainsString( '(abusefilter-edit-builder-select)', $html );

		// Fallback dropdown; operator examples keep their invisible direction characters
		$this->assertStringContainsString( 'mw-abusefilter-condition-builder-fallback', $html );
		$this->assertStringContainsString( 'wpFilterBuilder', $html );
		$this->assertStringContainsString( "\u{202A}", $html );
		$this->assertStringContainsString( "\u{202C}", $html );

		$this->assertStringContainsString( 'mw-abusefilter-syntaxresult', $html );

		$this->assertContains( 'ext.abuseFilter.conditionBuilder', $output->getModules() );
		$this->assertContains( 'ext.abuseFilter.conditionBuilder.styles', $output->getModuleStyles() );
		$this->assertContains( 'ext.abuseFilter.edit', $output->getModules() );
	}

	public function testBuildEditBoxForUnprivilegedUser(): void {
		$output = $this->getOutputPage();
		$html = $this->getBuilder( $output, false )->buildEditBox( 'true' );

		$this->assertStringContainsString( 'readonly', $html );
		$this->assertStringNotContainsString( 'mw-abusefilter-condition-builder', $html );
		$this->assertStringNotContainsString( 'mw-abusefilter-syntaxresult', $html );
		$this->assertNotContains( 'ext.abuseFilter.conditionBuilder', $output->getModules() );
	}
}
