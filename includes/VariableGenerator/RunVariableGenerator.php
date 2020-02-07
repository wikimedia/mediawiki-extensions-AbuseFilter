<?php

namespace MediaWiki\Extension\AbuseFilter\VariableGenerator;

use AbuseFilter;
use AbuseFilterVariableHolder;
use AFComputedVariable;
use Content;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MWException;
use MWFileProps;
use Title;
use UploadBase;
use User;
use WikiPage;

/**
 * This class contains the logic used to create AbuseFilterVariableHolder objects before filtering
 * an action.
 */
class RunVariableGenerator extends VariableGenerator {

	/**
	 * @param User $user
	 * @param Title $title
	 * @param WikiPage|null $page
	 * @param string $summary
	 * @param Content $newcontent
	 * @param string $text
	 * @param string $oldtext
	 * @param Content|null $oldcontent
	 * @return AbuseFilterVariableHolder
	 * @throws MWException
	 */
	public static function newVariableHolderForEdit(
		User $user, Title $title, $page, $summary, Content $newcontent,
		$text, $oldtext, Content $oldcontent = null
	) {
		$vars = new AbuseFilterVariableHolder();
		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $title, 'page' )
		);
		$vars->setVar( 'action', 'edit' );
		$vars->setVar( 'summary', $summary );
		if ( $oldcontent instanceof Content ) {
			$oldmodel = $oldcontent->getModel();
		} else {
			$oldmodel = '';
			$oldtext = '';
		}
		$vars->setVar( 'old_content_model', $oldmodel );
		$vars->setVar( 'new_content_model', $newcontent->getModel() );
		$vars->setVar( 'old_wikitext', $oldtext );
		$vars->setVar( 'new_wikitext', $text );
		$vars->addHolders( AbuseFilter::getEditVars( $title, $page ) );

		return $vars;
	}

	/**
	 * Get variables for filtering an edit.
	 * @todo Consider splitting into a separate class
	 * @see AbuseFilterHooks::filterEdit for more parameter docs
	 * @internal Until we'll find the right place for it
	 *
	 * @param Title $title
	 * @param User $user
	 * @param Content $content
	 * @param string $text
	 * @param string $summary
	 * @param string $slot
	 * @param WikiPage|null $page
	 * @return AbuseFilterVariableHolder|null
	 */
	public static function getEditVars(
		Title $title,
		User $user,
		Content $content,
		$text,
		$summary,
		$slot,
		WikiPage $page = null
	) {
		$oldContent = null;

		if ( $page !== null ) {
			$oldRevision = $page->getRevision();
			if ( !$oldRevision ) {
				return null;
			}

			$oldContent = $oldRevision->getContent( RevisionRecord::RAW );
			$oldAfText = AbuseFilter::revisionToString( $oldRevision, $user );

			// XXX: Recreate what the new revision will probably be so we can get the full AF
			// text for all slots
			$oldRevRecord = $oldRevision->getRevisionRecord();
			$newRevision = MutableRevisionRecord::newFromParentRevision( $oldRevRecord );
			$newRevision->setContent( $slot, $content );
			$text = AbuseFilter::revisionToString( $newRevision, $user );

			// Cache article object so we can share a parse operation
			$articleCacheKey = $title->getNamespace() . ':' . $title->getText();
			AFComputedVariable::$articleCache[$articleCacheKey] = $page;

			// Don't trigger for null edits. Compare Content objects if available, but check the
			// stringified contents as well, e.g. for line endings normalization (T240115).
			// Don't treat content model change as null edit though.
			if (
				( $oldContent && $content->equals( $oldContent ) ) ||
				( $oldContent->getModel() === $content->getModel() && strcmp( $oldAfText, $text ) === 0 )
			) {
				return null;
			}
		} else {
			$oldAfText = '';
		}

		return self::newVariableHolderForEdit(
			$user, $title, $page, $summary, $content, $text, $oldAfText, $oldContent
		);
	}

	/**
	 * Get variables used to filter a move.
	 * @param User $user
	 * @param Title $oldTitle
	 * @param Title $newTitle
	 * @param string $reason
	 * @return AbuseFilterVariableHolder
	 * @internal Until we'll find the right place for it
	 *
	 * @todo Consider splitting into a separate class
	 */
	public static function getMoveVars(
		User $user,
		Title $oldTitle,
		Title $newTitle,
		$reason
	) : AbuseFilterVariableHolder {
		$vars = new AbuseFilterVariableHolder;
		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $oldTitle, 'MOVED_FROM' ),
			AbuseFilter::generateTitleVars( $newTitle, 'MOVED_TO' )
		);
		$vars->setVar( 'summary', $reason );
		$vars->setVar( 'action', 'move' );
		return $vars;
	}

	/**
	 * Get variables for filtering a deletion.
	 * @param User $user
	 * @param WikiPage $article
	 * @param string $reason
	 * @return AbuseFilterVariableHolder
	 * @todo Consider splitting into a separate class
	 * @internal Until we'll find the right place for it
	 *
	 */
	public static function getDeleteVars(
		User $user,
		WikiPage $article,
		$reason
	) : AbuseFilterVariableHolder {
		$vars = new AbuseFilterVariableHolder;

		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $article->getTitle(), 'page' )
		);

		$vars->setVar( 'summary', $reason );
		$vars->setVar( 'action', 'delete' );
		return $vars;
	}

	/**
	 * Get variables for filtering an upload.
	 * @param string $action
	 * @param User $user
	 * @param Title $title
	 * @param UploadBase $upload
	 * @param string $summary
	 * @param string $text
	 * @param array $props
	 * @return AbuseFilterVariableHolder|null
	 * @todo Consider splitting into a separate class
	 * @internal Until we'll find the right place for it
	 */
	public static function getUploadVars(
		$action,
		User $user,
		Title $title,
		UploadBase $upload,
		$summary,
		$text,
		$props
	) {
		$mimeAnalyzer = MediaWikiServices::getInstance()->getMimeAnalyzer();
		if ( !$props ) {
			$props = ( new MWFileProps( $mimeAnalyzer ) )->getPropsFromPath(
				$upload->getTempPath(),
				true
			);
		}

		$vars = new AbuseFilterVariableHolder;
		$vars->addHolders(
			AbuseFilter::generateUserVars( $user ),
			AbuseFilter::generateTitleVars( $title, 'page' )
		);
		$vars->setVar( 'action', $action );

		// We use the hexadecimal version of the file sha1.
		// Use UploadBase::getTempFileSha1Base36 so that we don't have to calculate the sha1 sum again
		$sha1 = \Wikimedia\base_convert( $upload->getTempFileSha1Base36(), 36, 16, 40 );

		// This is the same as AbuseFilter::getUploadVarsFromRCEntry, but from a different source
		$vars->setVar( 'file_sha1', $sha1 );
		$vars->setVar( 'file_size', $upload->getFileSize() );

		$vars->setVar( 'file_mime', $props['mime'] );
		$vars->setVar( 'file_mediatype', $mimeAnalyzer->getMediaType( null, $props['mime'] ) );
		$vars->setVar( 'file_width', $props['width'] );
		$vars->setVar( 'file_height', $props['height'] );
		$vars->setVar( 'file_bits_per_channel', $props['bits'] );

		// We only have the upload comment and page text when using the UploadVerifyUpload hook
		if ( $summary !== null && $text !== null ) {
			// This block is adapted from self::filterEdit()
			if ( $title->exists() ) {
				$page = WikiPage::factory( $title );
				$revision = $page->getRevision();
				if ( !$revision ) {
					return null;
				}

				$oldcontent = $revision->getContent( RevisionRecord::RAW );
				$oldtext = AbuseFilter::contentToString( $oldcontent );

				// Cache article object so we can share a parse operation
				$articleCacheKey = $title->getNamespace() . ':' . $title->getText();
				AFComputedVariable::$articleCache[$articleCacheKey] = $page;

				// Page text is ignored for uploads when the page already exists
				$text = $oldtext;
			} else {
				$page = null;
				$oldtext = '';
			}

			// Load vars for filters to check
			$vars->setVar( 'summary', $summary );
			$vars->setVar( 'old_wikitext', $oldtext );
			$vars->setVar( 'new_wikitext', $text );
			// TODO: set old_content and new_content vars, use them
			$vars->addHolders( AbuseFilter::getEditVars( $title, $page ) );
		}
		return $vars;
	}
}
