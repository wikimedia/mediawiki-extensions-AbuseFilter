<?php

namespace MediaWiki\Extension\AbuseFilter;

use LogPage;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\Filter\AbstractFilter;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\Options\UserOptionsLookup;
use RCCacheEntry;

/**
 * This class simplifies the interactions between the AbuseFilter code and Authority, knowing
 * what rights are required to perform AF-related actions.
 */
class AbuseFilterPermissionManager {
	public const SERVICE_NAME = 'AbuseFilterPermissionManager';

	public const CONSTRUCTOR_OPTIONS = [
		'AbuseFilterProtectedVariables',
	];

	/**
	 * @var string[] Protected variables defined in config via AbuseFilterProtectedVariables
	 */
	private $protectedVariables;

	private UserOptionsLookup $userOptionsLookup;

	/**
	 * @param ServiceOptions $options
	 * @param UserOptionsLookup $userOptionsLookup
	 */
	public function __construct(
		ServiceOptions $options,
		UserOptionsLookup $userOptionsLookup
	) {
		$this->protectedVariables = $options->get( 'AbuseFilterProtectedVariables' );
		$this->userOptionsLookup = $userOptionsLookup;
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canEdit( Authority $performer ): bool {
		$block = $performer->getBlock();
		return (
			!( $block && $block->isSitewide() ) &&
			$performer->isAllowed( 'abusefilter-modify' )
		);
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canEditGlobal( Authority $performer ): bool {
		return $performer->isAllowed( 'abusefilter-modify-global' );
	}

	/**
	 * Whether the user can edit the given filter.
	 *
	 * @param Authority $performer
	 * @param AbstractFilter $filter
	 * @return bool
	 */
	public function canEditFilter( Authority $performer, AbstractFilter $filter ): bool {
		return (
			$this->canEdit( $performer ) &&
			!( $filter->isGlobal() && !$this->canEditGlobal( $performer ) )
		);
	}

	/**
	 * Whether the user can edit a filter with restricted actions enabled.
	 *
	 * @param Authority $performer
	 * @return bool
	 */
	public function canEditFilterWithRestrictedActions( Authority $performer ): bool {
		return $performer->isAllowed( 'abusefilter-modify-restricted' );
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canViewPrivateFilters( Authority $performer ): bool {
		$block = $performer->getBlock();
		return (
			!( $block && $block->isSitewide() ) &&
			$performer->isAllowedAny(
				'abusefilter-modify',
				'abusefilter-view-private'
			)
		);
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canViewProtectedVariables( Authority $performer ) {
		$block = $performer->getBlock();
		return (
			!( $block && $block->isSitewide() ) &&
			$performer->isAllowed( 'abusefilter-access-protected-vars' )
		);
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canViewProtectedVariableValues( Authority $performer ) {
		return (
			$this->canViewProtectedVariables( $performer ) &&
			$this->userOptionsLookup->getOption(
				$performer->getUser(),
				'abusefilter-protected-vars-view-agreement'
			)
		);
	}

	/**
	 * Return all used protected variables from an array of variables. Ignore user permissions.
	 *
	 * @param string[] $usedVariables
	 * @return string[]
	 */
	public function getUsedProtectedVariables( array $usedVariables ): array {
		return array_intersect( $usedVariables, $this->protectedVariables );
	}

	/**
	 * Check if the filter uses variables that the user is not allowed to use (i.e., variables that are protected, if
	 * the user can't view protected variables), and return them.
	 *
	 * @param Authority $performer
	 * @param string[] $usedVariables
	 * @return string[]
	 */
	public function getForbiddenVariables( Authority $performer, array $usedVariables ): array {
		$usedProtectedVariables = array_intersect( $usedVariables, $this->protectedVariables );
		// All good if protected variables aren't used, or the user can view them.
		if ( count( $usedProtectedVariables ) === 0 || $this->canViewProtectedVariables( $performer ) ) {
			return [];
		}
		return $usedProtectedVariables;
	}

	/**
	 * Return an array of protected variables (originally defined in configuration)
	 *
	 * @return string[]
	 */
	public function getProtectedVariables() {
		return $this->protectedVariables;
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canViewPrivateFiltersLogs( Authority $performer ): bool {
		return $this->canViewPrivateFilters( $performer ) ||
			$performer->isAllowed( 'abusefilter-log-private' );
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canViewAbuseLog( Authority $performer ): bool {
		return $performer->isAllowed( 'abusefilter-log' );
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canHideAbuseLog( Authority $performer ): bool {
		return $performer->isAllowed( 'abusefilter-hide-log' );
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canRevertFilterActions( Authority $performer ): bool {
		return $performer->isAllowed( 'abusefilter-revert' );
	}

	/**
	 * @param Authority $performer
	 * @param int $privacyLevel Bitmask of privacy flags
	 * @todo Take a Filter parameter
	 * @return bool
	 */
	public function canSeeLogDetailsForFilter( Authority $performer, int $privacyLevel ): bool {
		if ( !$this->canSeeLogDetails( $performer ) ) {
			return false;
		}

		if ( $privacyLevel === Flags::FILTER_PUBLIC ) {
			return true;
		}
		if ( FilterUtils::isHidden( $privacyLevel ) && !$this->canViewPrivateFiltersLogs( $performer ) ) {
			return false;
		}
		if ( FilterUtils::isProtected( $privacyLevel ) && !$this->canViewProtectedVariables( $performer ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canSeeLogDetails( Authority $performer ): bool {
		return $performer->isAllowed( 'abusefilter-log-detail' );
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canSeePrivateDetails( Authority $performer ): bool {
		return $performer->isAllowed( 'abusefilter-privatedetails' );
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canSeeHiddenLogEntries( Authority $performer ): bool {
		return $performer->isAllowed( 'abusefilter-hidden-log' );
	}

	/**
	 * @param Authority $performer
	 * @return bool
	 */
	public function canUseTestTools( Authority $performer ): bool {
		// TODO: make independent
		return $this->canViewPrivateFilters( $performer );
	}

	/**
	 * Determine whether the current user is allowed to view a revision
	 * at all, given its current visibility restrictions.
	 *
	 * Unlike `RevisionRecord::userCanBitfield`, which checks whether the user
	 * may view a specific deleted aspect of a revision (e.g. text or comment),
	 * this method evaluates whether the revision itself is viewable, considering
	 * all applicable visibility flags together.
	 *
	 * @param int $visibility Current visibility bit field (the `rev_deleted` value)
	 * @param Authority $authority User on whose behalf to check access
	 * @return bool
	 * @todo Consider moving this to core if similar logic is needed elsewhere
	 * @see RevisionRecord::userCanBitfield
	 */
	public static function hasRevisionAccess( int $visibility, Authority $authority ): bool {
		if ( !$visibility ) {
			return true;
		}
		if ( $visibility & RevisionRecord::DELETED_RESTRICTED ) {
			// Suppressed revisions require suppressor rights regardless of other flags
			return $authority->isAllowedAny( 'suppressrevision', 'viewsuppressed' );
		}
		if ( ( $visibility & RevisionRecord::DELETED_TEXT ) && !$authority->isAllowed( 'deletedtext' ) ) {
			return false;
		}
		if ( ( $visibility & ( RevisionRecord::DELETED_COMMENT | RevisionRecord::DELETED_USER ) ) &&
			!$authority->isAllowed( 'deletedhistory' )
		) {
			return false;
		}
		return true;
	}

	/**
	 * Determine whether the current user is allowed to view a recent change row
	 * at all, given its source and current visibility restrictions.
	 *
	 * Unlike `ChangesList::userCan`, which checks whether the user may view a
	 * specific deleted aspect of a recent change and delegates to
	 * `LogEventsList::userCanBitfield` for log entries or
	 * `RevisionRecord::userCanBitfield` otherwise, this method evaluates whether
	 * the recent change row itself is viewable, considering all applicable
	 * visibility flags together, and delegates to `::hasRevisionAccess` for
	 * non-log entries.
	 *
	 * @param RCCacheEntry|RecentChange $rc
	 * @param Authority $authority User on whose behalf to check
	 * @return bool
	 * @see ChangesList::userCan
	 * @see LogEventsList::userCanBitfield
	 * @see RevisionRecord::userCanBitfield
	 * @see AbuseFilterPermissionManager::hasRevisionAccess
	 */
	public static function hasRCEntryAccess( $rc, Authority $authority ): bool {
		$visibility = (int)$rc->getAttribute( 'rc_deleted' );
		if ( $visibility === 0 ) {
			return true;
		}
		if ( $rc->getAttribute( 'rc_source' ) === RecentChange::SRC_LOG ) {
			if ( $visibility & LogPage::DELETED_RESTRICTED ) {
				return $authority->isAllowedAny( 'suppressrevision', 'viewsuppressed' );
			} else {
				return $authority->isAllowed( 'deletedhistory' );
			}
		}
		return self::hasRevisionAccess( $visibility, $authority );
	}

}
