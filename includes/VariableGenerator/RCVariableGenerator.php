<?php

namespace MediaWiki\Extension\AbuseFilter\VariableGenerator;

use AbuseFilter;
use AbuseFilterVariableHolder;
use DatabaseLogEntry;
use LogicException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWFileProps;
use MWTimestamp;
use RCDatabaseLogEntry;
use Title;

/**
 * This class contains the logic used to create AbuseFilterVariableHolder objects used to
 * examine a RecentChanges row.
 */
class RCVariableGenerator extends VariableGenerator {

	/**
	 * @param \stdClass $row
	 * @return AbuseFilterVariableHolder|null
	 */
	public static function getVarsFromRCRow( $row ) {
		$entry = DatabaseLogEntry::newFromRow( $row );

		if ( !( $entry instanceof RCDatabaseLogEntry ) ) {
			throw new LogicException( '$row must be a recentchanges row' );
		}

		if ( $entry->getType() === 'move' ) {
			$vars = self::getMoveVarsFromRCEntry( $entry );
		} elseif ( $entry->getType() === 'newusers' ) {
			$vars = self::getCreateVarsFromRCEntry( $entry );
		} elseif ( $entry->getType() === 'delete' ) {
			$vars = self::getDeleteVarsFromRCEntry( $entry );
		} elseif ( $entry->getType() === 'upload' ) {
			$vars = self::getUploadVarsFromRCEntry( $entry );
		} elseif ( $entry->getAssociatedRevId() ) {
			// It's an edit.
			$vars = self::getEditVarsFromRCEntry( $entry );
		} else {
			return null;
		}
		if ( $vars ) {
			$vars->setVar(
				'timestamp',
				MWTimestamp::convert( TS_UNIX, $entry->getTimestamp() )
			);
			$vars->addHolders( AbuseFilter::generateStaticVars() );
		}

		return $vars;
	}

	/**
	 * @param RCDatabaseLogEntry $entry
	 * @return AbuseFilterVariableHolder
	 */
	private static function getMoveVarsFromRCEntry( RCDatabaseLogEntry $entry ) {
		$user = $entry->getPerformer();

		$params = array_values( $entry->getParameters() );

		$oldTitle = $entry->getTarget();
		$newTitle = Title::newFromText( $params[0] );

		$vars = AbuseFilterVariableHolder::merge(
			AbuseFilter::generateUserVars( $user, $entry ),
			AbuseFilter::generateTitleVars( $oldTitle, 'moved_from', $entry ),
			AbuseFilter::generateTitleVars( $newTitle, 'moved_to', $entry )
		);

		$vars->setVar( 'summary', $entry->getComment() );
		$vars->setVar( 'action', 'move' );

		return $vars;
	}

	/**
	 * @param RCDatabaseLogEntry $entry
	 * @return AbuseFilterVariableHolder
	 */
	private static function getCreateVarsFromRCEntry( RCDatabaseLogEntry $entry ) {
		$vars = new AbuseFilterVariableHolder;

		$vars->setVar(
			'action',
			$entry->getSubtype() === 'autocreate' ? 'autocreateaccount' : 'createaccount'
		);

		$name = $entry->getTarget()->getText();
		// Add user data if the account was created by a registered user
		$user = $entry->getPerformer();
		if ( !$user->isAnon() && $name !== $user->getName() ) {
			$vars->addHolders( AbuseFilter::generateUserVars( $user, $entry ) );
		}

		$vars->setVar( 'accountname', $name );

		return $vars;
	}

	/**
	 * @param RCDatabaseLogEntry $entry
	 * @return AbuseFilterVariableHolder
	 */
	private static function getDeleteVarsFromRCEntry( RCDatabaseLogEntry $entry ) {
		$vars = new AbuseFilterVariableHolder;

		$title = $entry->getTarget();
		$user = $entry->getPerformer();

		$vars->addHolders(
			AbuseFilter::generateUserVars( $user, $entry ),
			AbuseFilter::generateTitleVars( $title, 'page', $entry )
		);

		$vars->setVar( 'action', 'delete' );
		$vars->setVar( 'summary', $entry->getComment() );

		return $vars;
	}

	/**
	 * @param RCDatabaseLogEntry $entry
	 * @return AbuseFilterVariableHolder
	 */
	private static function getUploadVarsFromRCEntry( RCDatabaseLogEntry $entry ) {
		$vars = new AbuseFilterVariableHolder;

		$title = $entry->getTarget();
		$user = $entry->getPerformer();

		$vars->addHolders(
			AbuseFilter::generateUserVars( $user, $entry ),
			AbuseFilter::generateTitleVars( $title, 'page', $entry )
		);

		$vars->setVar( 'action', 'upload' );
		$vars->setVar( 'summary', $entry->getComment() );

		$time = $entry->getParameters()['img_timestamp'];
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile(
			$title, [ 'time' => $time, 'private' => true ]
		);
		if ( !$file ) {
			// FixMe This shouldn't happen!
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->debug( "Cannot find file from RC row with title $title" );
			return $vars;
		}

		// This is the same as AbuseFilterHooks::filterUpload, but from a different source
		$vars->setVar( 'file_sha1', \Wikimedia\base_convert( $file->getSha1(), 36, 16, 40 ) );
		$vars->setVar( 'file_size', $file->getSize() );

		$vars->setVar( 'file_mime', $file->getMimeType() );
		$vars->setVar(
			'file_mediatype',
			MediaWikiServices::getInstance()->getMimeAnalyzer()
				->getMediaType( null, $file->getMimeType() )
		);
		$vars->setVar( 'file_width', $file->getWidth() );
		$vars->setVar( 'file_height', $file->getHeight() );

		$mwProps = new MWFileProps( MediaWikiServices::getInstance()->getMimeAnalyzer() );
		$bits = $mwProps->getPropsFromPath( $file->getLocalRefPath(), true )['bits'];
		$vars->setVar( 'file_bits_per_channel', $bits );

		return $vars;
	}

	/**
	 * @param RCDatabaseLogEntry $entry
	 * @return AbuseFilterVariableHolder
	 */
	private static function getEditVarsFromRCEntry( RCDatabaseLogEntry $entry ) {
		$vars = new AbuseFilterVariableHolder;

		$title = $entry->getTarget();
		$user = $entry->getPerformer();

		$vars->addHolders(
			AbuseFilter::generateUserVars( $user, $entry ),
			AbuseFilter::generateTitleVars( $title, 'page', $entry )
		);
		// @todo Set old_content_model and new_content_model

		$vars->setVar( 'action', 'edit' );
		$vars->setVar( 'summary', $entry->getComment() );

		$vars->setLazyLoadVar( 'new_wikitext', 'revision-text-by-id',
			[ 'revid' => $entry->getAssociatedRevId() ] );

		if ( $entry->getParentRevId() ) {
			$vars->setLazyLoadVar( 'old_wikitext', 'revision-text-by-id',
				[ 'revid' => $entry->getParentRevId() ] );
		} else {
			$vars->setVar( 'old_wikitext', '' );
		}

		$vars->addHolders( AbuseFilter::getEditVars( $title ) );

		return $vars;
	}
}
