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
	 * Computes all variables unrelated to title and user. In general, these variables are known
	 * even without an ongoing action.
	 *
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateStaticVars() {
		$vars = new AbuseFilterVariableHolder();

		// For now, we don't have variables to add; other extensions could.
		Hooks::run( 'AbuseFilter-generateStaticVars', [ $vars ] );
		return $vars;
	}

	/**
	 * @param User $user
	 * @param RCDatabaseLogEntry|null $entry If the variables should be generated for an RC entry,
	 *   this is the entry. Null if it's for the current action being filtered.
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateUserVars( User $user, RCDatabaseLogEntry $entry = null ) {
		$vars = new AbuseFilterVariableHolder;

		$vars->setLazyLoadVar(
			'user_editcount',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getEditCount' ]
		);

		$vars->setVar( 'user_name', $user->getName() );

		$vars->setLazyLoadVar(
			'user_emailconfirm',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getEmailAuthenticationTimestamp' ]
		);

		$vars->setLazyLoadVar(
			'user_age',
			'user-age',
			[ 'user' => $user, 'asof' => wfTimestampNow() ]
		);

		$vars->setLazyLoadVar(
			'user_groups',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getEffectiveGroups' ]
		);

		$vars->setLazyLoadVar(
			'user_rights',
			'simple-user-accessor',
			[ 'user' => $user, 'method' => 'getRights' ]
		);

		$vars->setLazyLoadVar(
			'user_blocked',
			'user-block',
			[ 'user' => $user ]
		);

		Hooks::run( 'AbuseFilter-generateUserVars', [ $vars, $user, $entry ] );

		return $vars;
	}

	/**
	 * @param Title|null $title
	 * @param string $prefix
	 * @param RCDatabaseLogEntry|null $entry If the variables should be generated for an RC entry,
	 *   this is the entry. Null if it's for the current action being filtered.
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateTitleVars( $title, $prefix, RCDatabaseLogEntry $entry = null ) {
		$vars = new AbuseFilterVariableHolder;

		if ( !$title ) {
			return $vars;
		}

		$vars->setVar( $prefix . '_id', $title->getArticleID() );
		$vars->setVar( $prefix . '_namespace', $title->getNamespace() );
		$vars->setVar( $prefix . '_title', $title->getText() );
		$vars->setVar( $prefix . '_prefixedtitle', $title->getPrefixedText() );

		global $wgRestrictionTypes;
		foreach ( $wgRestrictionTypes as $action ) {
			$vars->setLazyLoadVar( "{$prefix}_restrictions_$action", 'get-page-restrictions',
				[ 'title' => $title->getText(),
					'namespace' => $title->getNamespace(),
					'action' => $action
				]
			);
		}

		$vars->setLazyLoadVar( "{$prefix}_recent_contributors", 'load-recent-authors',
			[
				'title' => $title->getText(),
				'namespace' => $title->getNamespace()
			] );

		$vars->setLazyLoadVar( "{$prefix}_age", 'page-age',
			[
				'title' => $title->getText(),
				'namespace' => $title->getNamespace(),
				'asof' => wfTimestampNow()
			] );

		$vars->setLazyLoadVar( "{$prefix}_first_contributor", 'load-first-author',
			[
				'title' => $title->getText(),
				'namespace' => $title->getNamespace()
			] );

		Hooks::run( 'AbuseFilter-generateTitleVars', [ $vars, $title, $prefix, $entry ] );

		return $vars;
	}

	/**
	 * @param Title $title
	 * @param Page|null $page
	 * @return AbuseFilterVariableHolder
	 */
	public static function generateEditVars( Title $title, Page $page = null ) {
		$vars = new AbuseFilterVariableHolder;

		// NOTE: $page may end up remaining null, e.g. if $title points to a special page.
		if ( !$page && $title->canExist() ) {
			$page = WikiPage::factory( $title );
		}

		$vars->setLazyLoadVar( 'edit_diff', 'diff-array',
			[ 'oldtext-var' => 'old_wikitext', 'newtext-var' => 'new_wikitext' ] );
		$vars->setLazyLoadVar( 'edit_diff_pst', 'diff-array',
			[ 'oldtext-var' => 'old_wikitext', 'newtext-var' => 'new_pst' ] );
		$vars->setLazyLoadVar( 'new_size', 'length', [ 'length-var' => 'new_wikitext' ] );
		$vars->setLazyLoadVar( 'old_size', 'length', [ 'length-var' => 'old_wikitext' ] );
		$vars->setLazyLoadVar( 'edit_delta', 'subtract-int',
			[ 'val1-var' => 'new_size', 'val2-var' => 'old_size' ] );

		// Some more specific/useful details about the changes.
		$vars->setLazyLoadVar( 'added_lines', 'diff-split',
			[ 'diff-var' => 'edit_diff', 'line-prefix' => '+' ] );
		$vars->setLazyLoadVar( 'removed_lines', 'diff-split',
			[ 'diff-var' => 'edit_diff', 'line-prefix' => '-' ] );
		$vars->setLazyLoadVar( 'added_lines_pst', 'diff-split',
			[ 'diff-var' => 'edit_diff_pst', 'line-prefix' => '+' ] );

		// Links
		$vars->setLazyLoadVar( 'added_links', 'link-diff-added',
			[ 'oldlink-var' => 'old_links', 'newlink-var' => 'all_links' ] );
		$vars->setLazyLoadVar( 'removed_links', 'link-diff-removed',
			[ 'oldlink-var' => 'old_links', 'newlink-var' => 'all_links' ] );
		$vars->setLazyLoadVar( 'new_text', 'strip-html',
			[ 'html-var' => 'new_html' ] );

		$vars->setLazyLoadVar( 'all_links', 'links-from-wikitext',
			[
				'namespace' => $title->getNamespace(),
				'title' => $title->getText(),
				'text-var' => 'new_wikitext',
				'article' => $page
			] );
		$vars->setLazyLoadVar( 'old_links', 'links-from-wikitext-or-database',
			[
				'namespace' => $title->getNamespace(),
				'title' => $title->getText(),
				'text-var' => 'old_wikitext'
			] );
		$vars->setLazyLoadVar( 'new_pst', 'parse-wikitext',
			[
				'namespace' => $title->getNamespace(),
				'title' => $title->getText(),
				'wikitext-var' => 'new_wikitext',
				'article' => $page,
				'pst' => true,
			] );
		$vars->setLazyLoadVar( 'new_html', 'parse-wikitext',
			[
				'namespace' => $title->getNamespace(),
				'title' => $title->getText(),
				'wikitext-var' => 'new_wikitext',
				'article' => $page
			] );

		return $vars;
	}
}
