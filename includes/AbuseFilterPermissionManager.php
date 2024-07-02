<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\Filter\AbstractFilter;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Permissions\Authority;

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

	/**
	 * @param ServiceOptions $options
	 */
	public function __construct(
		ServiceOptions $options
	) {
		$this->protectedVariables = $options->get( 'AbuseFilterProtectedVariables' );
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
	 * Check if the filter should be protected:
	 * - Return false if it uses no protected variables
	 * - Return true if it uses protected variables and the performer has view permissions
	 * - Return an array of used protected variables if the performer doesn't have
	 *   view permissions
	 *
	 * @param Authority $performer
	 * @param string[] $usedVariables
	 * @return string[]|bool
	 */
	public function shouldProtectFilter( Authority $performer, $usedVariables ) {
		$usedProtectedVariables = array_intersect( $usedVariables, $this->protectedVariables );
		// Protected variables aren't used
		if ( count( $usedProtectedVariables ) === 0 ) {
			return false;
		} else {
			// Check for permissions if they are
			if ( $this->canViewProtectedVariables( $performer ) ) {
				return true;
			} else {
				return $usedProtectedVariables;
			}
		}
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

}
