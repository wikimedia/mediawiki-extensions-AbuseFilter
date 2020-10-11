<?php

namespace MediaWiki\Extension\AbuseFilter\Consequence;

use AbuseFilterVariableHolder;
use AFPData;
use ManualLogEntry;
use MediaWiki\Extension\AbuseFilter\FilterUser;
use MediaWiki\User\UserGroupManager;
use User;

/**
 * Consequence that removes all user groups from a user.
 */
class Degroup extends Consequence implements HookAborterConsequence {
	/**
	 * @var AbuseFilterVariableHolder
	 * @todo This dependency is subpar
	 */
	private $vars;

	/** @var UserGroupManager */
	private $userGroupManager;

	/** @var FilterUser */
	private $filterUser;

	/**
	 * @param Parameters $params
	 * @param AbuseFilterVariableHolder $vars
	 * @param UserGroupManager $userGroupManager
	 * @param FilterUser $filterUser
	 */
	public function __construct(
		Parameters $params,
		AbuseFilterVariableHolder $vars,
		UserGroupManager $userGroupManager,
		FilterUser $filterUser
	) {
		parent::__construct( $params );
		$this->vars = $vars;
		$this->userGroupManager = $userGroupManager;
		$this->filterUser = $filterUser;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() : bool {
		$user = User::newFromIdentity( $this->parameters->getUser() );

		if ( $user->isAnon() ) {
			return false;
		}

		// Pull the groups from the VariableHolder, so that they will always be computed.
		// This allow us to pull the groups from the VariableHolder to undo the degroup
		// via Special:AbuseFilter/revert.
		$groups = $this->vars->getVar( 'user_groups', AbuseFilterVariableHolder::GET_LAX );
		if ( $groups->type !== AFPData::DARRAY ) {
			// Somehow, the variable wasn't set
			$groups = $this->userGroupManager->getUserEffectiveGroups( $user );
			$this->vars->setVar( 'user_groups', $groups );
		} else {
			$groups = $groups->toNative();
		}
		$this->vars->setVar( 'user_groups', $groups );

		$implicitGroups = $this->userGroupManager->listAllImplicitGroups();
		$removeGroups = array_diff( $groups, $implicitGroups );
		if ( !count( $removeGroups ) ) {
			return false;
		}

		foreach ( $removeGroups as $group ) {
			$this->userGroupManager->removeUserFromGroup( $user, $group );
		}

		// TODO Core should provide a logging method
		$logEntry = new ManualLogEntry( 'rights', 'rights' );
		$logEntry->setPerformer( $this->filterUser->getUser() );
		$logEntry->setTarget( $user->getUserPage() );
		$logEntry->setComment(
			wfMessage(
				'abusefilter-degroupreason',
				$this->parameters->getFilter()->getName(),
				$this->parameters->getFilter()->getID()
			)->inContentLanguage()->text()
		);
		$logEntry->setParameters( [
			'4::oldgroups' => $removeGroups,
			'5::newgroups' => []
		] );
		$logEntry->publish( $logEntry->insert() );
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getMessage(): array {
		return [
			'abusefilter-degrouped',
			$this->parameters->getFilter()->getName(),
			$this->parameters->getFilter()->getID()
		];
	}
}
