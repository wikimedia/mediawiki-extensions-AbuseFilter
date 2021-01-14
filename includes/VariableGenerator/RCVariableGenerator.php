<?php

namespace MediaWiki\Extension\AbuseFilter\VariableGenerator;

use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Logger\LoggerFactory;
use MimeAnalyzer;
use MWFileProps;
use MWTimestamp;
use RecentChange;
use RepoGroup;
use Title;
use User;

/**
 * This class contains the logic used to create variable holders used to
 * examine a RecentChanges row.
 */
class RCVariableGenerator extends VariableGenerator {
	/**
	 * @var RecentChange
	 */
	protected $rc;

	/** @var User */
	private $contextUser;

	/** @var MimeAnalyzer */
	private $mimeAnalyzer;
	/** @var RepoGroup */
	private $repoGroup;

	/**
	 * @param AbuseFilterHookRunner $hookRunner
	 * @param MimeAnalyzer $mimeAnalyzer
	 * @param RepoGroup $repoGroup
	 * @param RecentChange $rc
	 * @param User $contextUser
	 * @param VariableHolder|null $vars
	 */
	public function __construct(
		AbuseFilterHookRunner $hookRunner,
		MimeAnalyzer $mimeAnalyzer,
		RepoGroup $repoGroup,
		RecentChange $rc,
		User $contextUser,
		VariableHolder $vars = null
	) {
		parent::__construct( $hookRunner, $vars );

		$this->mimeAnalyzer = $mimeAnalyzer;
		$this->repoGroup = $repoGroup;
		$this->rc = $rc;
		$this->contextUser = $contextUser;
	}

	/**
	 * @return VariableHolder|null
	 */
	public function getVars() : ?VariableHolder {
		if ( $this->rc->getAttribute( 'rc_type' ) == RC_LOG ) {
			switch ( $this->rc->getAttribute( 'rc_log_type' ) ) {
				case 'move':
					$this->addMoveVars();
					break;
				case 'newusers':
					$this->addCreateAccountVars();
					break;
				case 'delete':
					$this->addDeleteVars();
					break;
				case 'upload':
					$this->addUploadVars();
					break;
				default:
					return null;
			}
		} elseif ( $this->rc->getAttribute( 'rc_this_oldid' ) ) {
			// It's an edit.
			$this->addEditVarsForRow();
		} else {
			// @todo Ensure this cannot happen, and throw if it does
			// @codeCoverageIgnoreStart
			wfLogWarning( 'Cannot understand the given recentchanges row!' );
			return null;
			// @codeCoverageIgnoreEnd
		}

		$this->addGenericVars();
		$this->vars->setVar(
			'timestamp',
			MWTimestamp::convert( TS_UNIX, $this->rc->getAttribute( 'rc_timestamp' ) )
		);

		return $this->vars;
	}

	/**
	 * @return $this
	 */
	private function addMoveVars() : self {
		$user = $this->rc->getPerformer();

		$oldTitle = $this->rc->getTitle();
		$newTitle = Title::newFromText( $this->rc->getParam( '4::target' ) );

		$this->addUserVars( $user, $this->rc )
			->addTitleVars( $oldTitle, 'moved_from', $this->rc )
			->addTitleVars( $newTitle, 'moved_to', $this->rc );

		$this->vars->setVar( 'summary', $this->rc->getAttribute( 'rc_comment' ) );
		$this->vars->setVar( 'action', 'move' );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addCreateAccountVars() : self {
		$this->vars->setVar(
			'action',
			$this->rc->getAttribute( 'rc_log_action' ) === 'autocreate'
				? 'autocreateaccount'
				: 'createaccount'
		);

		$name = $this->rc->getTitle()->getText();
		// Add user data if the account was created by a registered user
		$user = $this->rc->getPerformer();
		if ( !$user->isAnon() && $name !== $user->getName() ) {
			$this->addUserVars( $user, $this->rc );
		}

		$this->vars->setVar( 'accountname', $name );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addDeleteVars() : self {
		$title = $this->rc->getTitle();
		$user = $this->rc->getPerformer();

		$this->addUserVars( $user, $this->rc )
			->addTitleVars( $title, 'page', $this->rc );

		$this->vars->setVar( 'action', 'delete' );
		$this->vars->setVar( 'summary', $this->rc->getAttribute( 'rc_comment' ) );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addUploadVars() : self {
		$title = $this->rc->getTitle();
		$user = $this->rc->getPerformer();

		$this->addUserVars( $user, $this->rc )
			->addTitleVars( $title, 'page', $this->rc );

		$this->vars->setVar( 'action', 'upload' );
		$this->vars->setVar( 'summary', $this->rc->getAttribute( 'rc_comment' ) );

		$time = $this->rc->getParam( 'img_timestamp' );
		$file = $this->repoGroup->findFile(
			$title, [ 'time' => $time, 'private' => $this->contextUser ]
		);
		if ( !$file ) {
			// @fixme Ensure this cannot happen!
			// @codeCoverageIgnoreStart
			$logger = LoggerFactory::getInstance( 'AbuseFilter' );
			$logger->warning( "Cannot find file from RC row with title $title" );
			return $this;
			// @codeCoverageIgnoreEnd
		}

		// This is the same as AbuseFilterHooks::filterUpload, but from a different source
		$this->vars->setVar( 'file_sha1', \Wikimedia\base_convert( $file->getSha1(), 36, 16, 40 ) );
		$this->vars->setVar( 'file_size', $file->getSize() );

		$this->vars->setVar( 'file_mime', $file->getMimeType() );
		$this->vars->setVar(
			'file_mediatype',
			$this->mimeAnalyzer->getMediaType( null, $file->getMimeType() )
		);
		$this->vars->setVar( 'file_width', $file->getWidth() );
		$this->vars->setVar( 'file_height', $file->getHeight() );

		$mwProps = new MWFileProps( $this->mimeAnalyzer );
		$bits = $mwProps->getPropsFromPath( $file->getLocalRefPath(), true )['bits'];
		$this->vars->setVar( 'file_bits_per_channel', $bits );

		return $this;
	}

	/**
	 * @return $this
	 */
	private function addEditVarsForRow() : self {
		$title = $this->rc->getTitle();
		$user = $this->rc->getPerformer();

		$this->addUserVars( $user, $this->rc )
			->addTitleVars( $title, 'page', $this->rc );

		// @todo Set old_content_model and new_content_model
		$this->vars->setVar( 'action', 'edit' );
		$this->vars->setVar( 'summary', $this->rc->getAttribute( 'rc_comment' ) );

		$this->vars->setLazyLoadVar( 'new_wikitext', 'revision-text-by-id',
			[ 'revid' => $this->rc->getAttribute( 'rc_this_oldid' ), 'contextUser' => $this->contextUser ] );

		$parentId = $this->rc->getAttribute( 'rc_last_oldid' );
		if ( $parentId ) {
			$this->vars->setLazyLoadVar( 'old_wikitext', 'revision-text-by-id',
				[ 'revid' => $parentId, 'contextUser' => $this->contextUser ] );
		} else {
			$this->vars->setVar( 'old_wikitext', '' );
		}

		$this->addEditVars( \WikiPage::factory( $title ), $this->contextUser );

		return $this;
	}
}
