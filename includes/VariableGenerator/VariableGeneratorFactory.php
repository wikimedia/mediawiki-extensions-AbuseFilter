<?php

namespace MediaWiki\Extension\AbuseFilter\VariableGenerator;

use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\ServiceNames;
use MediaWiki\Extension\AbuseFilter\TextExtractor;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Wikimedia\Mime\MimeAnalyzer;

class VariableGeneratorFactory {
	public const SERVICE_NAME = ServiceNames::VariableGeneratorFactory;

	public function __construct(
		private readonly AbuseFilterHookRunner $hookRunner,
		private readonly TextExtractor $textExtractor,
		private readonly MimeAnalyzer $mimeAnalyzer,
		private readonly RepoGroup $repoGroup,
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly UserFactory $userFactory,
	) {
	}

	/**
	 * @param VariableHolder|null $holder
	 * @return VariableGenerator
	 */
	public function newGenerator( ?VariableHolder $holder = null ): VariableGenerator {
		return new VariableGenerator( $this->hookRunner, $this->userFactory, $holder );
	}

	/**
	 * @param User $user
	 * @param Title $title
	 * @param VariableHolder|null $holder
	 * @return RunVariableGenerator
	 */
	public function newRunGenerator( User $user, Title $title, ?VariableHolder $holder = null ): RunVariableGenerator {
		return new RunVariableGenerator(
			$this->hookRunner,
			$this->userFactory,
			$this->textExtractor,
			$this->mimeAnalyzer,
			$this->wikiPageFactory,
			$user,
			$title,
			$holder
		);
	}

	/**
	 * @param RecentChange $rc
	 * @param User $contextUser
	 * @param VariableHolder|null $holder
	 * @return RCVariableGenerator
	 */
	public function newRCGenerator(
		RecentChange $rc,
		User $contextUser,
		?VariableHolder $holder = null
	): RCVariableGenerator {
		return new RCVariableGenerator(
			$this->hookRunner,
			$this->userFactory,
			$this->mimeAnalyzer,
			$this->repoGroup,
			$this->wikiPageFactory,
			$rc,
			$contextUser,
			$holder
		);
	}
}
