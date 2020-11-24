<?php

namespace MediaWiki\Extension\AbuseFilter;

use MessageLocalizer;
use OutputPage;
use User;

/**
 * Factory for EditBoxBuilder objects
 */
class EditBoxBuilderFactory {

	public const SERVICE_NAME = 'AbuseFilterEditBoxBuilderFactory';

	/** @var AbuseFilterPermissionManager */
	private $afPermManager;

	/** @var KeywordsManager */
	private $keywordsManager;

	/** @var bool */
	private $isCodeEditorLoaded;

	/**
	 * @param AbuseFilterPermissionManager $afPermManager
	 * @param KeywordsManager $keywordsManager
	 * @param bool $isCodeEditorLoaded
	 */
	public function __construct(
		AbuseFilterPermissionManager $afPermManager,
		KeywordsManager $keywordsManager,
		bool $isCodeEditorLoaded
	) {
		$this->afPermManager = $afPermManager;
		$this->keywordsManager = $keywordsManager;
		$this->isCodeEditorLoaded = $isCodeEditorLoaded;
	}

	/**
	 * @param MessageLocalizer $messageLocalizer
	 * @param User $user
	 * @param OutputPage $output
	 * @return EditBoxBuilder
	 */
	public function newEditBoxBuilder(
		MessageLocalizer $messageLocalizer,
		User $user,
		OutputPage $output
	) : EditBoxBuilder {
		return new EditBoxBuilder(
			$this->afPermManager,
			$this->keywordsManager,
			$this->isCodeEditorLoaded,
			$messageLocalizer,
			$user,
			$output
		);
	}

}
