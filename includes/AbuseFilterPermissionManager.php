<?php

namespace MediaWiki\Extension\AbuseFilter;

use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserIdentity;
use stdClass;
use User;

/**
 * This class acts as a mediator between the AbuseFilter code and the PermissionManager, knowing
 * what rights are required to perform AF-related actions.
 */
class AbuseFilterPermissionManager {
	public const SERVICE_NAME = 'AbuseFilterPermissionManager';

	/** @var PermissionManager */
	private $permissionManager;

	/**
	 * @param PermissionManager $pm
	 */
	public function __construct( PermissionManager $pm ) {
		$this->permissionManager = $pm;
	}

	/**
	 * @param User $user
	 * @return bool
	 */
	public function canEdit( User $user ) : bool {
		$block = $user->getBlock();
		return (
			!( $block && $block->isSitewide() ) &&
			$this->permissionManager->userHasRight( $user, 'abusefilter-modify' )
		);
	}

	/**
	 * @param UserIdentity $user
	 * @return bool
	 */
	public function canEditGlobal( UserIdentity $user ) : bool {
		return $this->permissionManager->userHasRight( $user, 'abusefilter-modify-global' );
	}

	/**
	 * Whether the user can edit the given filter.
	 *
	 * @param User $user
	 * @param stdClass $row Filter row
	 * @return bool
	 */
	public function canEditFilter( User $user, stdClass $row ) : bool {
		return (
			$this->canEdit( $user ) &&
			!( isset( $row->af_global ) && $row->af_global && !$this->canEditGlobal( $user ) )
		);
	}

	/**
	 * Whether the user can edit a filter with restricted actions enabled.
	 *
	 * @param UserIdentity $user
	 * @return bool
	 */
	public function canEditFilterWithRestrictedActions( UserIdentity $user ) : bool {
		return $this->permissionManager->userHasRight( $user, 'abusefilter-modify-restricted' );
	}

	/**
	 * @param UserIdentity $user
	 * @return bool
	 */
	public function canViewPrivateFilters( UserIdentity $user ) : bool {
		return $this->permissionManager->userHasAnyRight(
			$user,
			'abusefilter-modify',
			'abusefilter-view-private'
		);
	}

	/**
	 * @param UserIdentity $user
	 * @return bool
	 */
	public function canViewPrivateFiltersLogs( UserIdentity $user ) : bool {
		return $this->canViewPrivateFilters( $user ) ||
			$this->permissionManager->userHasRight( $user, 'abusefilter-log-private' );
	}

	/**
	 * @param UserIdentity $user
	 * @return bool
	 */
	public function canViewAbuseLog( UserIdentity $user ) : bool {
		return $this->permissionManager->userHasRight( $user, 'abusefilter-log' );
	}

	/**
	 * @param UserIdentity $user
	 * @return bool
	 */
	public function canHideAbuseLog( UserIdentity $user ) : bool {
		return $this->permissionManager->userHasRight( $user, 'abusefilter-hide-log' );
	}

	/**
	 * @param UserIdentity $user
	 * @return bool
	 */
	public function canRevertFilterActions( UserIdentity $user ) : bool {
		return $this->permissionManager->userHasRight( $user, 'abusefilter-revert' );
	}

	/**
	 * @param UserIdentity $user
	 * @param int|null $id The ID of the filter
	 * @param bool|int $global Whether the filter is global
	 * @param bool|int|null $filterHidden Whether the filter is hidden
	 * @return bool
	 */
	public function canSeeLogDetails( UserIdentity $user, $id = null, $global = false, $filterHidden = null ) : bool {
		if ( $id !== null ) {
			if ( $filterHidden === null ) {
				$filterHidden = \AbuseFilter::filterHidden( $id, $global );
			}
			if ( $filterHidden ) {
				return $this->permissionManager->userHasRight( $user, 'abusefilter-log-detail' )
					&& $this->canViewPrivateFiltersLogs( $user );
			}
		}

		return $this->permissionManager->userHasRight( $user, 'abusefilter-log-detail' );
	}

	/**
	 * @param UserIdentity $user
	 * @return bool
	 */
	public function canSeePrivateDetails( UserIdentity $user ) : bool {
		return $this->permissionManager->userHasRight( $user, 'abusefilter-privatedetails' );
	}

	/**
	 * @param UserIdentity $user
	 * @return bool
	 */
	public function canSeeHiddenLogEntries( UserIdentity $user ) : bool {
		return $this->permissionManager->userHasRight( $user, 'abusefilter-hidden-log' );
	}
}
