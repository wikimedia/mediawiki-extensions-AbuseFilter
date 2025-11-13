<?php

namespace MediaWiki\Extension\AbuseFilter\EditBox;

use LogicException;
use MediaWiki\Extension\AbuseFilter\AbuseFilterPermissionManager;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\Authority;
use MessageLocalizer;

/**
 * Factory for EditBoxBuilder objects
 */
class EditBoxBuilderFactory {

	public const SERVICE_NAME = 'AbuseFilterEditBoxBuilderFactory';

	public function __construct(
		private readonly AbuseFilterPermissionManager $afPermManager,
		private readonly KeywordsManager $keywordsManager,
		private readonly bool $isCodeEditorLoaded
	) {
	}

	/**
	 * Returns a builder, preferring the Ace version if available
	 * @param MessageLocalizer $messageLocalizer
	 * @param Authority $authority
	 * @param OutputPage $output
	 * @return EditBoxBuilder
	 */
	public function newEditBoxBuilder(
		MessageLocalizer $messageLocalizer,
		Authority $authority,
		OutputPage $output
	): EditBoxBuilder {
		return $this->isCodeEditorLoaded
			? $this->newAceBoxBuilder( $messageLocalizer, $authority, $output )
			: $this->newPlainBoxBuilder( $messageLocalizer, $authority, $output );
	}

	/**
	 * @param MessageLocalizer $messageLocalizer
	 * @param Authority $authority
	 * @param OutputPage $output
	 * @return PlainEditBoxBuilder
	 */
	public function newPlainBoxBuilder(
		MessageLocalizer $messageLocalizer,
		Authority $authority,
		OutputPage $output
	): PlainEditBoxBuilder {
		return new PlainEditBoxBuilder(
			$this->afPermManager,
			$this->keywordsManager,
			$messageLocalizer,
			$authority,
			$output
		);
	}

	/**
	 * @param MessageLocalizer $messageLocalizer
	 * @param Authority $authority
	 * @param OutputPage $output
	 * @return AceEditBoxBuilder
	 */
	public function newAceBoxBuilder(
		MessageLocalizer $messageLocalizer,
		Authority $authority,
		OutputPage $output
	): AceEditBoxBuilder {
		if ( !$this->isCodeEditorLoaded ) {
			throw new LogicException( 'Cannot create Ace box without CodeEditor' );
		}
		return new AceEditBoxBuilder(
			$this->afPermManager,
			$this->keywordsManager,
			$messageLocalizer,
			$authority,
			$output,
			$this->newPlainBoxBuilder(
				$messageLocalizer,
				$authority,
				$output
			)
		);
	}

}
