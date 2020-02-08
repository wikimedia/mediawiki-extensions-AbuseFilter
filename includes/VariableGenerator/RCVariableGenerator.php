<?php

namespace MediaWiki\Extension\AbuseFilter\VariableGenerator;

use AbuseFilterVariableHolder;
use DatabaseLogEntry;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWFileProps;
use MWTimestamp;
use RCDatabaseLogEntry;
use RecentChange;
use Title;
use Wikimedia\Rdbms\IDatabase;

/**
 * This class contains the logic used to create AbuseFilterVariableHolder objects used to
 * examine a RecentChanges row.
 */
class RCVariableGenerator extends VariableGenerator {
	/**
	 * @var RCDatabaseLogEntry
	 */
	protected $entry;

	/**
	 * @param AbuseFilterVariableHolder $vars
	 * @param RCDatabaseLogEntry $entry
	 */
	public function __construct( AbuseFilterVariableHolder $vars, RCDatabaseLogEntry $entry ) {
		parent::__construct( $vars );

		$this->entry = $entry;
	}

	/**
	 * Get an instance for a given rc_id.
	 *
	 * @param int $id
	 * @param AbuseFilterVariableHolder $vars
	 * @param IDatabase $db
	 * @return self|null
	 * @todo This could be greatly simplified, if only RCDatabaseLogEntry implemented its
	 *   own newFromId method
	 */
	public static function newFromId(
		int $id,
		AbuseFilterVariableHolder $vars,
		IDatabase $db
	) : ?self {
		$rcQuery = RecentChange::getQueryInfo();
		$row = $db->selectRow(
			$rcQuery['tables'],
			$rcQuery['fields'],
			[ 'rc_id' => $id ],
			__METHOD__,
			[],
			$rcQuery['joins']
		);

		if ( !$row ) {
			return null;
		}
		$entry = DatabaseLogEntry::newFromRow( $row );
		'@phan-var RCDatabaseLogEntry $entry';
		return new self( $vars, $entry );
	}

	/**
	 * @return AbuseFilterVariableHolder|null
	 */
	public function getVars() : ?AbuseFilterVariableHolder {
		if ( $this->entry->getType() === 'move' ) {
			$this->addMoveVars();
		} elseif ( $this->entry->getType() === 'newusers' ) {
			$this->addCreateAccountVars();
		} elseif ( $this->entry->getType() === 'delete' ) {
			$this->addDeleteVars();
		} elseif ( $this->entry->getType() === 'upload' ) {
			$this->addUploadVars();
		} elseif ( $this->entry->getAssociatedRevId() ) {
			// It's an edit.
			$this->addEditVarsForRow();
		} else {
			// @todo Ensure this cannot happen, and throw if it does
			return null;
		}

		$this->addStaticVars();
		$this->vars->setVar(
			'timestamp',
			MWTimestamp::convert( TS_UNIX, $this->entry->getTimestamp() )
		);

		return $this->vars;
	}

	/**
	 * @return $this
	 */
	private function addMoveVars() : self {
		$user = $this->entry->getPerformer();

		$params = array_values( $this->entry->getParameters() );

		$oldTitle = $this->entry->getTarget();
		$newTitle = Title::newFromText( $params[0] );

		$this->addUserVars( $user, $this->entry )
			->addTitleVars( $oldTitle, 'moved_from', $this->entry )
			->addTitleVars( $newTitle, 'moved_to', $this->entry );

		$this->vars->setVar( 'summary', $this->entry->getComment() );
		$this->vars->setVar( 'action', 'move' );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addCreateAccountVars() : self {
		$this->vars->setVar(
			'action',
			$this->entry->getSubtype() === 'autocreate' ? 'autocreateaccount' : 'createaccount'
		);

		$name = $this->entry->getTarget()->getText();
		// Add user data if the account was created by a registered user
		$user = $this->entry->getPerformer();
		if ( !$user->isAnon() && $name !== $user->getName() ) {
			$this->addUserVars( $user, $this->entry );
		}

		$this->vars->setVar( 'accountname', $name );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addDeleteVars() : self {
		$title = $this->entry->getTarget();
		$user = $this->entry->getPerformer();

		$this->addUserVars( $user, $this->entry )
			->addTitleVars( $title, 'page', $this->entry );

		$this->vars->setVar( 'action', 'delete' );
		$this->vars->setVar( 'summary', $this->entry->getComment() );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addUploadVars() : self {
		$title = $this->entry->getTarget();
		$user = $this->entry->getPerformer();

		$this->addUserVars( $user, $this->entry )
			->addTitleVars( $title, 'page', $this->entry );

		$this->vars->setVar( 'action', 'upload' );
		$this->vars->setVar( 'summary', $this->entry->getComment() );

		$time = $this->entry->getParameters()['img_timestamp'];
		$file = MediaWikiServices::getInstance()->getRepoGroup()->findFile(
			$title, [ 'time' => $time, 'private' => true ]
		);
		if ( !$file ) {
			// FixMe This shouldn't happen!
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->debug( "Cannot find file from RC row with title $title" );
			return $this;
		}

		// This is the same as AbuseFilterHooks::filterUpload, but from a different source
		$this->vars->setVar( 'file_sha1', \Wikimedia\base_convert( $file->getSha1(), 36, 16, 40 ) );
		$this->vars->setVar( 'file_size', $file->getSize() );

		$this->vars->setVar( 'file_mime', $file->getMimeType() );
		$this->vars->setVar(
			'file_mediatype',
			MediaWikiServices::getInstance()->getMimeAnalyzer()
				->getMediaType( null, $file->getMimeType() )
		);
		$this->vars->setVar( 'file_width', $file->getWidth() );
		$this->vars->setVar( 'file_height', $file->getHeight() );

		$mwProps = new MWFileProps( MediaWikiServices::getInstance()->getMimeAnalyzer() );
		$bits = $mwProps->getPropsFromPath( $file->getLocalRefPath(), true )['bits'];
		$this->vars->setVar( 'file_bits_per_channel', $bits );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addEditVarsForRow() : self {
		$title = $this->entry->getTarget();
		$user = $this->entry->getPerformer();

		$this->addUserVars( $user, $this->entry )
			->addTitleVars( $title, 'page', $this->entry );

		// @todo Set old_content_model and new_content_model
		$this->vars->setVar( 'action', 'edit' );
		$this->vars->setVar( 'summary', $this->entry->getComment() );

		$this->vars->setLazyLoadVar( 'new_wikitext', 'revision-text-by-id',
			[ 'revid' => $this->entry->getAssociatedRevId() ] );

		if ( $this->entry->getParentRevId() ) {
			$this->vars->setLazyLoadVar( 'old_wikitext', 'revision-text-by-id',
				[ 'revid' => $this->entry->getParentRevId() ] );
		} else {
			$this->vars->setVar( 'old_wikitext', '' );
		}

		$this->addEditVars( $title );

		return $this;
	}
}
