<?php

namespace MediaWiki\Extension\AbuseFilter;

use AbuseFilter;
use AbuseFilterVariableHolder;
use CheckUserHooks;
use DeferredUpdates;
use ExtensionRegistry;
use InvalidArgumentException;
use ManualLogEntry;
use MediaWiki\Config\ServiceOptions;
use Title;
use User;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

class AbuseLogger {
	public const CONSTRUCTOR_OPTIONS = [
		'AbuseFilterLogIP',
		'AbuseFilterNotifications',
		'AbuseFilterNotificationsPrivate',
	];

	/** @var Title */
	private $title;
	/** @var User */
	private $user;
	/** @var AbuseFilterVariableHolder */
	private $vars;
	/** @var string */
	private $action;

	/** @var CentralDBManager */
	private $centralDBManager;
	/** @var FilterLookup */
	private $filterLookup;
	/** @var ILoadBalancer */
	private $loadBalancer;
	/** @var ServiceOptions */
	private $options;
	/** @var string */
	private $wikiID;
	/** @var string */
	private $requestIP;

	/**
	 * @param CentralDBManager $centralDBManager
	 * @param FilterLookup $filterLookup
	 * @param ILoadBalancer $loadBalancer
	 * @param ServiceOptions $options
	 * @param string $wikiID
	 * @param string $requestIP
	 * @param Title $title
	 * @param User $user
	 * @param AbuseFilterVariableHolder $vars
	 */
	public function __construct(
		CentralDBManager $centralDBManager,
		FilterLookup $filterLookup,
		ILoadBalancer $loadBalancer,
		ServiceOptions $options,
		string $wikiID,
		string $requestIP,
		Title $title,
		User $user,
		AbuseFilterVariableHolder $vars
	) {
		if ( !$vars->varIsSet( 'action' ) ) {
			throw new InvalidArgumentException( "The 'action' variable is not set." );
		}
		$this->centralDBManager = $centralDBManager;
		$this->filterLookup = $filterLookup;
		$this->loadBalancer = $loadBalancer;
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->wikiID = $wikiID;
		$this->requestIP = $requestIP;
		$this->title = $title;
		$this->user = $user;
		$this->vars = $vars;
		$this->action = $vars->getVar( 'action' )->toString();
	}

	/**
	 * Create and publish log entries for taken actions
	 *
	 * @param array[] $actionsTaken
	 * @return array Shape is [ 'local' => int[], 'global' => int[] ], IDs of logged filters
	 * @phan-return array{local:int[],global:int[]}
	 */
	public function addLogEntries( array $actionsTaken ) : array {
		$dbw = $this->loadBalancer->getConnectionRef( DB_MASTER );
		$logTemplate = $this->buildLogTemplate();
		$centralLogTemplate = [
			'afl_wiki' => $this->wikiID,
		];

		$logRows = [];
		$centralLogRows = [];
		$loggedLocalFilters = [];
		$loggedGlobalFilters = [];

		foreach ( $actionsTaken as $filter => $actions ) {
			list( $filterID, $global ) = AbuseFilter::splitGlobalName( $filter );
			$thisLog = $logTemplate;
			$thisLog['afl_filter'] = $filter;
			$thisLog['afl_actions'] = implode( ',', $actions );

			// Don't log if we were only throttling.
			// TODO This check should be removed or rewritten using Consequence objects
			if ( $thisLog['afl_actions'] !== 'throttle' ) {
				$logRows[] = $thisLog;
				// Global logging
				if ( $global ) {
					$centralLog = $thisLog + $centralLogTemplate;
					$centralLog['afl_filter'] = $filterID;
					$centralLog['afl_title'] = $this->title->getPrefixedText();
					$centralLog['afl_namespace'] = 0;

					$centralLogRows[] = $centralLog;
					$loggedGlobalFilters[] = $filterID;
				} else {
					$loggedLocalFilters[] = $filterID;
				}
			}
		}

		if ( !count( $logRows ) ) {
			return [ 'local' => [], 'global' => [] ];
		}

		$localLogIDs = $this->insertLocalLogEntries( $logRows, $dbw );

		$globalLogIDs = [];
		if ( count( $loggedGlobalFilters ) ) {
			$fdb = $this->centralDBManager->getConnection( DB_MASTER );
			$globalLogIDs = $this->insertGlobalLogEntries( $centralLogRows, $fdb );
		}

		AbuseFilter::$logIds[ $this->title->getPrefixedText() ] = [
			'local' => $localLogIDs,
			'global' => $globalLogIDs
		];

		return [ 'local' => $loggedLocalFilters, 'global' => $loggedGlobalFilters ];
	}

	/**
	 * Creates a template to use for logging taken actions
	 *
	 * @return array
	 */
	private function buildLogTemplate() : array {
		// If $this->user isn't safe to load (e.g. a failure during
		// AbortAutoAccount), create a dummy anonymous user instead.
		$user = $this->user->isSafeToLoad() ? $this->user : new User;
		// Create a template
		$logTemplate = [
			'afl_user' => $user->getId(),
			'afl_user_text' => $user->getName(),
			'afl_timestamp' => $this->loadBalancer->getConnectionRef( DB_REPLICA )->timestamp(),
			'afl_namespace' => $this->title->getNamespace(),
			'afl_title' => $this->title->getDBkey(),
			'afl_action' => $this->action,
			'afl_ip' => $this->options->get( 'AbuseFilterLogIP' ) ? $this->requestIP : ''
		];
		// Hack to avoid revealing IPs of people creating accounts
		if ( ( $this->action === 'createaccount' || $this->action === 'autocreateaccount' ) && !$user->getId() ) {
			$logTemplate['afl_user_text'] = $this->vars->getVar( 'accountname' )->toString();
		}
		return $logTemplate;
	}

	/**
	 * @param array[] $logRows
	 * @param IDatabase $dbw
	 * @return array
	 */
	private function insertLocalLogEntries( array $logRows, IDatabase $dbw ) : array {
		$varDump = AbuseFilter::storeVarDump( $this->vars );
		$varDump = "tt:$varDump";

		$loggedIDs = [];
		foreach ( $logRows as $data ) {
			$data['afl_var_dump'] = $varDump;
			$dbw->insert( 'abuse_filter_log', $data, __METHOD__ );
			$loggedIDs[] = $data['afl_id'] = $dbw->insertId();
			// Give grep a chance to find the usages:
			// logentry-abusefilter-hit
			$entry = new ManualLogEntry( 'abusefilter', 'hit' );
			// Construct a user object
			$user = User::newFromId( $data['afl_user'] );
			$user->setName( $data['afl_user_text'] );
			$entry->setPerformer( $user );
			$entry->setTarget( $this->title );
			// Additional info
			$entry->setParameters( [
				'action' => $data['afl_action'],
				'filter' => $data['afl_filter'],
				'actions' => $data['afl_actions'],
				'log' => $data['afl_id'],
			] );

			// Send data to CheckUser if installed and we
			// aren't already sending a notification to recentchanges
			if ( ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' )
				&& strpos( $this->options->get( 'AbuseFilterNotifications' ), 'rc' ) === false
			) {
				global $wgCheckUserLogAdditionalRights;
				$wgCheckUserLogAdditionalRights[] = 'abusefilter-view';
				$rc = $entry->getRecentChange();
				CheckUserHooks::updateCheckUserData( $rc );
			}

			if ( $this->options->get( 'AbuseFilterNotifications' ) !== false ) {
				list( $filterID, $global ) = AbuseFilter::splitGlobalName( $data['afl_filter'] );
				if (
					!$this->options->get( 'AbuseFilterNotificationsPrivate' ) &&
					$this->filterLookup->getFilter( $filterID, $global )->isHidden()
				) {
					continue;
				}
				$this->publishEntry( $dbw, $entry );
			}
		}
		return $loggedIDs;
	}

	/**
	 * @param array[] $centralLogRows
	 * @param IDatabase $fdb
	 * @return array
	 */
	private function insertGlobalLogEntries( array $centralLogRows, IDatabase $fdb ) : array {
		$this->vars->computeDBVars();
		$globalVarDump = AbuseFilter::storeVarDump( $this->vars, true );
		$globalVarDump = "tt:$globalVarDump";
		foreach ( $centralLogRows as $index => $data ) {
			$centralLogRows[$index]['afl_var_dump'] = $globalVarDump;
		}

		$loggedIDs = [];
		foreach ( $centralLogRows as $row ) {
			$fdb->insert( 'abuse_filter_log', $row, __METHOD__ );
			$loggedIDs[] = $fdb->insertId();
		}
		return $loggedIDs;
	}

	/**
	 * Like ManualLogEntry::publish, but doesn't require an ID (which we don't have) and skips the
	 * tagging part
	 *
	 * @param IDatabase $dbw To cancel the callback if the log insertion fails
	 * @param ManualLogEntry $entry
	 */
	private function publishEntry( IDatabase $dbw, ManualLogEntry $entry ) : void {
		DeferredUpdates::addCallableUpdate(
			function () use ( $entry ) {
				$rc = $entry->getRecentChange();
				$to = $this->options->get( 'AbuseFilterNotifications' );

				if ( $to === 'rc' || $to === 'rcandudp' ) {
					$rc->save( $rc::SEND_NONE );
				}
				if ( $to === 'udp' || $to === 'rcandudp' ) {
					$rc->notifyRCFeeds();
				}
			},
			DeferredUpdates::POSTSEND,
			$dbw
		);
	}

}
