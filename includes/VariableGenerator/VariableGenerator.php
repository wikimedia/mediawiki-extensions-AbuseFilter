<?php

namespace MediaWiki\Extension\AbuseFilter\VariableGenerator;

use AbuseFilterVariableHolder;
use Hooks;
use Page;
use RCDatabaseLogEntry;
use Title;
use User;
use WikiPage;

/**
 * Class used to generate variables, for instance related to a given user or title.
 */
class VariableGenerator {
	/**
	 * @var AbuseFilterVariableHolder
	 */
	protected $vars;

	/**
	 * @param AbuseFilterVariableHolder $vars
	 */
	public function __construct( AbuseFilterVariableHolder $vars ) {
		$this->vars = $vars;
	}

	/**
	 * @return AbuseFilterVariableHolder
	 */
	public function getVariableHolder() : AbuseFilterVariableHolder {
		return $this->vars;
	}

	/**
	 * Computes all variables unrelated to title and user. In general, these variables are known
	 * even without an ongoing action.
	 *
	 * @return $this For chaining
	 */
	public function addStaticVars() : self {
		// For now, we don't have variables to add; other extensions could.
		Hooks::run( 'AbuseFilter-generateStaticVars', [ $this->vars ] );
		return $this;
	}

	/**
	 * @param User $user
	 * @param RCDatabaseLogEntry|null $entry If the variables should be generated for an RC entry,
	 *   this is the entry. Null if it's for the current action being filtered.
	 * @return $this For chaining
	 */
	public function addUserVars( User $user, RCDatabaseLogEntry $entry = null ) : self {
		$this->vars->setLazyLoadVar(
			'user_editcount',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getEditCount' ]
		);

		$this->vars->setVar( 'user_name', $user->getName() );

		$this->vars->setLazyLoadVar(
			'user_emailconfirm',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getEmailAuthenticationTimestamp' ]
		);

		$this->vars->setLazyLoadVar(
			'user_age',
			'user-age',
			[ 'user' => $user, 'asof' => wfTimestampNow() ]
		);

		$this->vars->setLazyLoadVar(
			'user_groups',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getEffectiveGroups' ]
		);

		$this->vars->setLazyLoadVar(
			'user_rights',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getRights' ]
		);

		$this->vars->setLazyLoadVar(
			'user_blocked',
			'user-block',
			[ 'user' => $user ]
		);

		Hooks::run( 'AbuseFilter-generateUserVars', [ $this->vars, $user, $entry ] );

		return $this;
	}

	/**
	 * @param Title $title
	 * @param string $prefix
	 * @param RCDatabaseLogEntry|null $entry If the variables should be generated for an RC entry,
	 *   this is the entry. Null if it's for the current action being filtered.
	 * @return $this For chaining
	 */
	public function addTitleVars(
		Title $title,
		string $prefix,
		RCDatabaseLogEntry $entry = null
	) : self {
		$this->vars->setVar( $prefix . '_id', $title->getArticleID() );
		$this->vars->setVar( $prefix . '_namespace', $title->getNamespace() );
		$this->vars->setVar( $prefix . '_title', $title->getText() );
		$this->vars->setVar( $prefix . '_prefixedtitle', $title->getPrefixedText() );

		global $wgRestrictionTypes;
		foreach ( $wgRestrictionTypes as $action ) {
			$this->vars->setLazyLoadVar( "{$prefix}_restrictions_$action", 'get-page-restrictions',
				[ 'title' => $title->getText(),
					'namespace' => $title->getNamespace(),
					'action' => $action
				]
			);
		}

		$this->vars->setLazyLoadVar( "{$prefix}_recent_contributors", 'load-recent-authors',
			[
				'title' => $title->getText(),
				'namespace' => $title->getNamespace()
			] );

		$this->vars->setLazyLoadVar( "{$prefix}_age", 'page-age',
			[
				'title' => $title->getText(),
				'namespace' => $title->getNamespace(),
				'asof' => wfTimestampNow()
			] );

		$this->vars->setLazyLoadVar( "{$prefix}_first_contributor", 'load-first-author',
			[
				'title' => $title->getText(),
				'namespace' => $title->getNamespace()
			] );

		Hooks::run( 'AbuseFilter-generateTitleVars', [ $this->vars, $title, $prefix, $entry ] );

		return $this;
	}

	/**
	 * @param Title $title
	 * @param Page|null $page
	 * @return $this For chaining
	 */
	public function addEditVars( Title $title, Page $page = null ) : self {
		// NOTE: $page may end up remaining null, e.g. if $title points to a special page.
		if ( !$page && $title->canExist() ) {
			// TODO: The caller should do this!
			$page = WikiPage::factory( $title );
		}

		$this->vars->setLazyLoadVar( 'edit_diff', 'diff-array',
			[ 'oldtext-var' => 'old_wikitext', 'newtext-var' => 'new_wikitext' ] );
		$this->vars->setLazyLoadVar( 'edit_diff_pst', 'diff-array',
			[ 'oldtext-var' => 'old_wikitext', 'newtext-var' => 'new_pst' ] );
		$this->vars->setLazyLoadVar( 'new_size', 'length', [ 'length-var' => 'new_wikitext' ] );
		$this->vars->setLazyLoadVar( 'old_size', 'length', [ 'length-var' => 'old_wikitext' ] );
		$this->vars->setLazyLoadVar( 'edit_delta', 'subtract-int',
			[ 'val1-var' => 'new_size', 'val2-var' => 'old_size' ] );

		// Some more specific/useful details about the changes.
		$this->vars->setLazyLoadVar( 'added_lines', 'diff-split',
			[ 'diff-var' => 'edit_diff', 'line-prefix' => '+' ] );
		$this->vars->setLazyLoadVar( 'removed_lines', 'diff-split',
			[ 'diff-var' => 'edit_diff', 'line-prefix' => '-' ] );
		$this->vars->setLazyLoadVar( 'added_lines_pst', 'diff-split',
			[ 'diff-var' => 'edit_diff_pst', 'line-prefix' => '+' ] );

		// Links
		$this->vars->setLazyLoadVar( 'added_links', 'link-diff-added',
			[ 'oldlink-var' => 'old_links', 'newlink-var' => 'all_links' ] );
		$this->vars->setLazyLoadVar( 'removed_links', 'link-diff-removed',
			[ 'oldlink-var' => 'old_links', 'newlink-var' => 'all_links' ] );
		$this->vars->setLazyLoadVar( 'new_text', 'strip-html',
			[ 'html-var' => 'new_html' ] );

		$this->vars->setLazyLoadVar( 'all_links', 'links-from-wikitext',
			[
				'namespace' => $title->getNamespace(),
				'title' => $title->getText(),
				'text-var' => 'new_wikitext',
				'article' => $page
			] );
		$this->vars->setLazyLoadVar( 'old_links', 'links-from-wikitext-or-database',
			[
				'namespace' => $title->getNamespace(),
				'title' => $title->getText(),
				'text-var' => 'old_wikitext'
			] );
		$this->vars->setLazyLoadVar( 'new_pst', 'parse-wikitext',
			[
				'namespace' => $title->getNamespace(),
				'title' => $title->getText(),
				'wikitext-var' => 'new_wikitext',
				'article' => $page,
				'pst' => true,
			] );
		$this->vars->setLazyLoadVar( 'new_html', 'parse-wikitext',
			[
				'namespace' => $title->getNamespace(),
				'title' => $title->getText(),
				'wikitext-var' => 'new_wikitext',
				'article' => $page
			] );

		return $this;
	}
}
