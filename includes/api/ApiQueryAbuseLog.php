<?php
/**
 * Created on Mar 28, 2009
 *
 * AbuseFilter extension
 *
 * Copyright © 2008 Alex Z. mrzmanwiki AT gmail DOT com
 * Based mostly on code by Bryan Tong Minh and Roan Kattouw
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

/**
 * Query module to list abuse log entries.
 *
 * @ingroup API
 * @ingroup Extensions
 */
class ApiQueryAbuseLog extends ApiQueryBase {
	/**
	 * @param ApiQuery $query
	 * @param string $moduleName
	 */
	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'afl' );
	}

	/**
	 * @see ApiQueryBase::execute()
	 */
	public function execute() {
		$user = $this->getUser();
		$errors = $this->getTitle()->getUserPermissionsErrors(
			'abusefilter-log', $user, true, [ 'ns-specialprotected' ] );
		if ( count( $errors ) ) {
			$this->dieStatus( $this->errorArrayToStatus( $errors ) );
			return;
		}

		$params = $this->extractRequestParams();

		$prop = array_flip( $params['prop'] );
		$fld_ids = isset( $prop['ids'] );
		$fld_filter = isset( $prop['filter'] );
		$fld_user = isset( $prop['user'] );
		$fld_ip = isset( $prop['ip'] );
		$fld_title = isset( $prop['title'] );
		$fld_action = isset( $prop['action'] );
		$fld_details = isset( $prop['details'] );
		$fld_result = isset( $prop['result'] );
		$fld_timestamp = isset( $prop['timestamp'] );
		$fld_hidden = isset( $prop['hidden'] );
		$fld_revid = isset( $prop['revid'] );
		$isCentral = $this->getConfig()->get( 'AbuseFilterIsCentral' );
		$fld_wiki = $isCentral && isset( $prop['wiki'] );

		if ( $fld_ip ) {
			$this->checkUserRightsAny( 'abusefilter-private' );
		}
		if ( $fld_details ) {
			$this->checkUserRightsAny( 'abusefilter-log-detail' );
		}
		// Match permissions for viewing events on private filters to SpecialAbuseLog (bug 42814)
		if ( $params['filter'] &&
			!( AbuseFilterView::canViewPrivate() || $user->isAllowed( 'abusefilter-log-private' ) )
		) {
			// A specific filter parameter is set but the user isn't allowed to view all filters
			if ( !is_array( $params['filter'] ) ) {
				$params['filter'] = [ $params['filter'] ];
			}
			foreach ( $params['filter'] as $filter ) {
				if ( AbuseFilter::filterHidden( $filter ) ) {
					$this->dieWithError(
						[ 'apierror-permissiondenied', $this->msg( 'action-abusefilter-log-private' ) ]
					);
				}
			}
		}

		$result = $this->getResult();

		$this->addTables( 'abuse_filter_log' );
		$this->addFields( 'afl_timestamp' );
		$this->addFields( 'afl_rev_id' );
		$this->addFields( 'afl_deleted' );
		$this->addFields( 'afl_filter' );
		$this->addFieldsIf( 'afl_id', $fld_ids );
		$this->addFieldsIf( 'afl_user_text', $fld_user );
		$this->addFieldsIf( 'afl_ip', $fld_ip );
		$this->addFieldsIf( [ 'afl_namespace', 'afl_title' ], $fld_title );
		$this->addFieldsIf( 'afl_action', $fld_action );
		$this->addFieldsIf( 'afl_var_dump', $fld_details );
		$this->addFieldsIf( 'afl_actions', $fld_result );
		$this->addFieldsIf( 'afl_wiki', $fld_wiki );

		if ( $fld_filter ) {
			$this->addTables( 'abuse_filter' );
			$this->addFields( 'af_public_comments' );
			$this->addJoinConds( [ 'abuse_filter' => [ 'LEFT JOIN',
					'af_id=afl_filter' ] ] );
		}

		$this->addOption( 'LIMIT', $params['limit'] + 1 );

		$this->addWhereIf( [ 'afl_id' => $params['logid'] ], isset( $params['logid'] ) );

		$this->addWhereRange( 'afl_timestamp', $params['dir'], $params['start'], $params['end'] );

		$db = $this->getDB();
		$notDeletedCond = SpecialAbuseLog::getNotDeletedCond( $db );

		if ( isset( $params['user'] ) ) {
			$u = User::newFromName( $params['user'] );
			if ( $u ) {
				// Username normalisation
				$params['user'] = $u->getName();
				$userId = $u->getId();
			} elseif ( IP::isIPAddress( $params['user'] ) ) {
				// It's an IP, sanitize it
				$params['user'] = IP::sanitizeIP( $params['user'] );
				$userId = 0;
			}

			if ( isset( $userId ) ) {
				// Only add the WHERE for user in case it's either a valid user
				// (but not necessary an existing one) or an IP.
				$this->addWhere(
					[
						'afl_user' => $userId,
						'afl_user_text' => $params['user']
					]
				);
			}
		}

		$this->addWhereIf( [ 'afl_filter' => $params['filter'] ], isset( $params['filter'] ) );
		$this->addWhereIf( $notDeletedCond, !SpecialAbuseLog::canSeeHidden() );
		if ( isset( $params['wiki'] ) ) {
			// 'wiki' won't be set if $wgAbuseFilterIsCentral = false
			$this->addWhereIf( [ 'afl_wiki' => $params['wiki'] ], $isCentral );
		}

		$title = $params['title'];
		if ( !is_null( $title ) ) {
			$titleObj = Title::newFromText( $title );
			if ( is_null( $titleObj ) ) {
				$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $title ) ] );
			}
			$this->addWhereFld( 'afl_namespace', $titleObj->getNamespace() );
			$this->addWhereFld( 'afl_title', $titleObj->getDBkey() );
		}
		$res = $this->select( __METHOD__ );

		$count = 0;
		foreach ( $res as $row ) {
			if ( ++$count > $params['limit'] ) {
				// We've had enough
				$ts = new MWTimestamp( $row->afl_timestamp );
				$this->setContinueEnumParameter( 'start', $ts->getTimestamp( TS_ISO_8601 ) );
				break;
			}
			if ( SpecialAbuseLog::isHidden( $row ) &&
				!SpecialAbuseLog::canSeeHidden()
			) {
				continue;
			}
			$canSeeDetails = SpecialAbuseLog::canSeeDetails( $row->afl_filter );

			$entry = [];
			if ( $fld_ids ) {
				$entry['id'] = intval( $row->afl_id );
				$entry['filter_id'] = $canSeeDetails ? $row->afl_filter : '';
			}
			if ( $fld_filter ) {
				$globalIndex = AbuseFilter::decodeGlobalName( $row->afl_filter );
				if ( $globalIndex ) {
					$entry['filter'] = AbuseFilter::getGlobalFilterDescription( $globalIndex );
				} else {
					$entry['filter'] = $row->af_public_comments;
				}
			}
			if ( $fld_user ) {
				$entry['user'] = $row->afl_user_text;
			}
			if ( $fld_ip ) {
				$entry['ip'] = $row->afl_ip;
			}
			if ( $fld_wiki ) {
				$entry['wiki'] = $row->afl_wiki;
			}
			if ( $fld_title ) {
				$title = Title::makeTitle( $row->afl_namespace, $row->afl_title );
				ApiQueryBase::addTitleInfo( $entry, $title );
			}
			if ( $fld_action ) {
				$entry['action'] = $row->afl_action;
			}
			if ( $fld_result ) {
				$entry['result'] = $row->afl_actions;
			}
			if ( $fld_revid && !is_null( $row->afl_rev_id ) ) {
				$entry['revid'] = $canSeeDetails ? $row->afl_rev_id : '';
			}
			if ( $fld_timestamp ) {
				$ts = new MWTimestamp( $row->afl_timestamp );
				$entry['timestamp'] = $ts->getTimestamp( TS_ISO_8601 );
			}
			if ( $fld_details ) {
				$entry['details'] = [];
				if ( $canSeeDetails ) {
					$vars = AbuseFilter::loadVarDump( $row->afl_var_dump );
					if ( $vars instanceof AbuseFilterVariableHolder ) {
						$entry['details'] = $vars->exportAllVars();
					} else {
						$entry['details'] = array_change_key_case( $vars, CASE_LOWER );
					}
				}
			}

			if ( $fld_hidden ) {
				$val = SpecialAbuseLog::isHidden( $row );
				if ( $val ) {
					$entry['hidden'] = $val;
				}
			}

			if ( $entry ) {
				$fit = $result->addValue( [ 'query', $this->getModuleName() ], null, $entry );
				if ( !$fit ) {
					$ts = new MWTimestamp( $row->afl_timestamp );
					$this->setContinueEnumParameter( 'start', $ts->getTimestamp( TS_ISO_8601 ) );
					break;
				}
			}
		}
		$result->addIndexedTagName( [ 'query', $this->getModuleName() ], 'item' );
	}

	/**
	 * @see ApiQueryBase::getAllowedParams()
	 * @return array
	 */
	public function getAllowedParams() {
		$params = [
			'logid' => [
				ApiBase::PARAM_TYPE => 'integer'
			],
			'start' => [
				ApiBase::PARAM_TYPE => 'timestamp'
			],
			'end' => [
				ApiBase::PARAM_TYPE => 'timestamp'
			],
			'dir' => [
				ApiBase::PARAM_TYPE => [
					'newer',
					'older'
				],
				ApiBase::PARAM_DFLT => 'older',
				ApiBase::PARAM_HELP_MSG => 'api-help-param-direction',
			],
			'user' => null,
			'title' => null,
			'filter' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_ISMULTI => true
			],
			'limit' => [
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2
			],
			'prop' => [
				ApiBase::PARAM_DFLT => 'ids|user|title|action|result|timestamp|hidden|revid',
				ApiBase::PARAM_TYPE => [
					'ids',
					'filter',
					'user',
					'ip',
					'title',
					'action',
					'details',
					'result',
					'timestamp',
					'hidden',
					'revid',
				],
				ApiBase::PARAM_ISMULTI => true
			]
		];
		if ( $this->getConfig()->get( 'AbuseFilterIsCentral' ) ) {
			$params['wiki'] = [
				ApiBase::PARAM_TYPE => 'string',
			];
			$params['prop'][ApiBase::PARAM_DFLT] .= '|wiki';
			$params['prop'][ApiBase::PARAM_TYPE][] = 'wiki';
		}
		return $params;
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&list=abuselog'
				=> 'apihelp-query+abuselog-example-1',
			'action=query&list=abuselog&afltitle=API'
				=> 'apihelp-query+abuselog-example-2',
		];
	}
}
