<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Extension\AbuseFilter\Filter\AbstractFilter;
use MediaWiki\Extension\AbuseFilter\Filter\Flags;
use MediaWiki\Extension\AbuseFilter\Variables\AbuseFilterProtectedVariablesLookup;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\Options\UserOptionsLookup;

/**
 * This class simplifies the interactions between the AbuseFilter code and Authority, knowing
 * what rights are required to perform AF-related actions.
 */
class AbuseFilterPermissionManager {
	public const SERVICE_NAME = 'AbuseFilterPermissionManager';

	/**
	 * @var string[] All protected variables
	 */
	private array $protectedVariables;

	private UserOptionsLookup $userOptionsLookup;

	public function __construct(
		UserOptionsLookup $userOptionsLookup,
		AbuseFilterProtectedVariablesLookup $protectedVariablesLookup
	) {
		$this->protectedVariables = $protectedVariablesLookup->getAllProtectedVariables();
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
	 * @return AbuseFilterPermissionStatus
	 */
	public function canViewProtectedVariables( Authority $performer ): AbuseFilterPermissionStatus {
		$block = $performer->getBlock();
		if ( $block && $block->isSitewide() ) {
			return AbuseFilterPermissionStatus::newBlockedError( $block );
		}

		if ( !$performer->isAllowed( 'abusefilter-access-protected-vars' ) ) {
			return AbuseFilterPermissionStatus::newPermissionError( 'abusefilter-access-protected-vars' );
		}

		return AbuseFilterPermissionStatus::newGood();
	}

	/**
	 * @param Authority $performer
	 * @return AbuseFilterPermissionStatus
	 */
	public function canViewProtectedVariableValues( Authority $performer ): AbuseFilterPermissionStatus {
		$returnStatus = $this->canViewProtectedVariables( $performer );

		if ( !$returnStatus->isGood() ) {
			return $returnStatus;
		}

		if ( !$this->userOptionsLookup->getOption(
			$performer->getUser(),
			'abusefilter-protected-vars-view-agreement'
		) ) {
			return AbuseFilterPermissionStatus::newFatal( 'abusefilter-examine-protected-vars-permission' );
		}

		return AbuseFilterPermissionStatus::newGood();
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
		$usedProtectedVariables = $this->getUsedProtectedVariables( $usedVariables );
		// All good if protected variables aren't used, or the user can view them.
		if ( count( $usedProtectedVariables ) === 0 || $this->canViewProtectedVariables( $performer )->isGood() ) {
			return [];
		}
		return $usedProtectedVariables;
	}

	/**
	 * Return an array of protected variables. Convenience method that calls
	 * {@link AbuseFilterProtectedVariablesLookup::getAllProtectedVariables}.
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
		if ( FilterUtils::isProtected( $privacyLevel ) && !$this->canViewProtectedVariables( $performer )->isGood() ) {
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
