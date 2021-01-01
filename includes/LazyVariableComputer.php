<?php

namespace MediaWiki\Extension\AbuseFilter;

use AbuseFilter;
use AbuseFilterVariableHolder;
use AFComputedVariable;
use ContentHandler;
use Diff;
use Language;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Extension\AbuseFilter\Parser\AFPData;
use MediaWiki\Extension\AbuseFilter\Parser\AFPException;
use MediaWiki\Storage\RevisionLookup;
use MediaWiki\Storage\RevisionStore;
use Parser;
use ParserOptions;
use Psr\Log\LoggerInterface;
use stdClass;
use StringUtils;
use TextContent;
use Title;
use TitleFactory;
use UnifiedDiffFormatter;
use User;
use WANObjectCache;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\ILoadBalancer;
use WikiPage;

/**
 * Service used to compute lazy-loaded variable.
 * @internal
 */
class LazyVariableComputer {
	public const SERVICE_NAME = 'AbuseFilterLazyVariableComputer';

	/**
	 * @var WikiPage[] Cache containing Page objects already constructed
	 * @todo Is this necessary?
	 */
	public static $articleCache = [];

	/**
	 * @var float The amount of time to subtract from profiling
	 * @todo This is a hack
	 */
	public static $profilingExtraTime = 0;

	/** @var AbuseFilterHookRunner */
	private $hookRunner;

	/** @var TitleFactory */
	private $titleFactory;

	/** @var LoggerInterface */
	private $logger;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var WANObjectCache */
	private $wanCache;

	/** @var RevisionLookup */
	private $revisionLookup;

	/** @var RevisionStore */
	private $revisionStore;

	/** @var Language */
	private $contentLanguage;

	/** @var Parser */
	private $parser;

	/** @var string */
	private $wikiID;

	/**
	 * @param AbuseFilterHookRunner $hookRunner
	 * @param TitleFactory $titleFactory
	 * @param LoggerInterface $logger
	 * @param ILoadBalancer $loadBalancer
	 * @param WANObjectCache $wanCache
	 * @param RevisionLookup $revisionLookup
	 * @param RevisionStore $revisionStore
	 * @param Language $contentLanguage
	 * @param Parser $parser
	 * @param string $wikiID
	 */
	public function __construct(
		AbuseFilterHookRunner $hookRunner,
		TitleFactory $titleFactory,
		LoggerInterface $logger,
		ILoadBalancer $loadBalancer,
		WANObjectCache $wanCache,
		RevisionLookup $revisionLookup,
		RevisionStore $revisionStore,
		Language $contentLanguage,
		Parser $parser,
		string $wikiID
	) {
		$this->hookRunner = $hookRunner;
		$this->titleFactory = $titleFactory;
		$this->logger = $logger;
		$this->loadBalancer = $loadBalancer;
		$this->wanCache = $wanCache;
		$this->revisionLookup = $revisionLookup;
		$this->revisionStore = $revisionStore;
		$this->contentLanguage = $contentLanguage;
		$this->parser = $parser;
		$this->wikiID = $wikiID;
	}

	/**
	 * @param AFComputedVariable $var
	 * @param AbuseFilterVariableHolder $vars
	 * @return AFPData
	 * @throws AFPException
	 */
	public function compute( AFComputedVariable $var, AbuseFilterVariableHolder $vars ) {
		$vars->setLogger( $this->logger );
		$parameters = $var->mParameters;
		$result = null;

		if ( !$this->hookRunner->onAbuseFilterInterceptVariable(
			$var->mMethod,
			$vars,
			$parameters,
			$result
		) ) {
			return $result instanceof AFPData
				? $result : AFPData::newFromPHPVar( $result );
		}

		switch ( $var->mMethod ) {
			case 'diff':
				$text1Var = $parameters['oldtext-var'];
				$text2Var = $parameters['newtext-var'];
				$text1 = $vars->getVar( $text1Var )->toString();
				$text2 = $vars->getVar( $text2Var )->toString();
				// T74329: if there's no text, don't return an array with the empty string
				$text1 = $text1 === '' ? [] : explode( "\n", $text1 );
				$text2 = $text2 === '' ? [] : explode( "\n", $text2 );
				$diffs = new Diff( $text1, $text2 );
				$format = new UnifiedDiffFormatter();
				$result = $format->format( $diffs );
				break;
			case 'diff-split':
				$diff = $vars->getVar( $parameters['diff-var'] )->toString();
				$line_prefix = $parameters['line-prefix'];
				$diff_lines = explode( "\n", $diff );
				$result = [];
				foreach ( $diff_lines as $line ) {
					if ( substr( $line, 0, 1 ) === $line_prefix ) {
						$result[] = substr( $line, strlen( $line_prefix ) );
					}
				}
				break;
			case 'links-from-wikitext':
				// This should ONLY be used when sharing a parse operation with the edit.

				/** @var WikiPage $article */
				$article = $parameters['article'];
				if ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					// Shared with the edit, don't count it in profiling
					$startTime = microtime( true );
					$textVar = $parameters['text-var'];

					$new_text = $vars->getVar( $textVar )->toString();
					$content = ContentHandler::makeContent( $new_text, $article->getTitle() );
					$editInfo = $article->prepareContentForEdit( $content );
					$result = array_keys( $editInfo->output->getExternalLinks() );
					self::$profilingExtraTime += ( microtime( true ) - $startTime );
					break;
				}
			// Otherwise fall back to database
			case 'links-from-wikitext-or-database':
				// Recreate the Page from namespace and title; this discards the $article
				// used in links-from-wikitext.
				// TODO: use Content object instead, if available!
				$article = $this->pageFromTitle(
					$parameters['namespace'],
					$parameters['title']
				);

				if ( $vars->forFilter ) {
					$links = $this->getLinksFromDB( $article );
					$this->logger->debug( 'Loading old links from DB' );
				} elseif ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					$this->logger->debug( 'Loading old links from Parser' );
					$textVar = $parameters['text-var'];

					$wikitext = $vars->getVar( $textVar )->toString();
					$editInfo = $this->parseNonEditWikitext(
						$wikitext,
						$article,
						$parameters['contextUser']
					);
					$links = array_keys( $editInfo->output->getExternalLinks() );
				} else {
					// TODO: Get links from Content object. But we don't have the content object.
					// And for non-text content, $wikitext is usually not going to be a valid
					// serialization, but rather some dummy text for filtering.
					$links = [];
				}

				$result = $links;
				break;
			case 'link-diff-added':
			case 'link-diff-removed':
				$oldLinkVar = $parameters['oldlink-var'];
				$newLinkVar = $parameters['newlink-var'];

				$oldLinks = $vars->getVar( $oldLinkVar )->toString();
				$newLinks = $vars->getVar( $newLinkVar )->toString();

				$oldLinks = explode( "\n", $oldLinks );
				$newLinks = explode( "\n", $newLinks );

				if ( $var->mMethod === 'link-diff-added' ) {
					$result = array_diff( $newLinks, $oldLinks );
				}
				if ( $var->mMethod === 'link-diff-removed' ) {
					$result = array_diff( $oldLinks, $newLinks );
				}
				break;
			case 'parse-wikitext':
				// Should ONLY be used when sharing a parse operation with the edit.
				/* @var WikiPage $article */
				$article = $parameters['article'];
				if ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					// Shared with the edit, don't count it in profiling
					$startTime = microtime( true );
					$textVar = $parameters['wikitext-var'];

					$new_text = $vars->getVar( $textVar )->toString();
					$content = ContentHandler::makeContent( $new_text, $article->getTitle() );
					$editInfo = $article->prepareContentForEdit( $content );
					if ( isset( $parameters['pst'] ) && $parameters['pst'] ) {
						$result = $editInfo->pstContent->serialize( $editInfo->format );
					} else {
						$newHTML = $editInfo->output->getText();
						// Kill the PP limit comments. Ideally we'd just remove these by not setting the
						// parser option, but then we can't share a parse operation with the edit, which is bad.
						// @fixme No awfulness scale can measure how awful this hack is.
						$re = '/<!--\s*NewPP limit [^>]*-->\s*(?:<!--\s*Transclusion [^>]+-->\s*)?(?:<\/div>\s*)?$/i';
						$result = preg_replace( $re, '', $newHTML );
					}
					self::$profilingExtraTime += ( microtime( true ) - $startTime );
					break;
				}

				// Otherwise fall back to database

				// TODO: use Content object instead, if available!
				$article = $this->pageFromTitle( $parameters['namespace'], $parameters['title'] );
				$textVar = $parameters['wikitext-var'];

				if ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					if ( isset( $parameters['pst'] ) && $parameters['pst'] ) {
						// $textVar is already PSTed when it's not loaded from an ongoing edit.
						$result = $vars->getVar( $textVar )->toString();
					} else {
						$text = $vars->getVar( $textVar )->toString();
						$editInfo = $this->parseNonEditWikitext(
							$text,
							$article,
							$parameters['contextUser']
						);
						$result = $editInfo->output->getText();
					}
				} else {
					// TODO: Parser Output from Content object. But we don't have the content object.
					// And for non-text content, $wikitext is usually not going to be a valid
					// serialization, but rather some dummy text for filtering.
					$result = '';
				}

				break;
			case 'strip-html':
				$htmlVar = $parameters['html-var'];
				$html = $vars->getVar( $htmlVar )->toString();
				$stripped = StringUtils::delimiterReplace( '<', '>', '', $html );
				// We strip extra spaces to the right because the stripping above
				// could leave a lot of whitespace.
				// @fixme Find a better way to do this.
				$result = TextContent::normalizeLineEndings( $stripped );
				break;
			case 'load-recent-authors':
				$title = $this->titleFactory->makeTitle( $parameters['namespace'], $parameters['title'] );
				$result = $this->getLastPageAuthors( $title );
				break;
			case 'load-first-author':
				$title = $this->titleFactory->makeTitle( $parameters['namespace'], $parameters['title'] );
				$revision = $this->revisionLookup->getFirstRevision( $title );
				if ( $revision ) {
					$user = $revision->getUser();
					$result = $user === null ? '' : $user->getName();
				} else {
					$result = '';
				}
				break;
			case 'get-page-restrictions':
				$action = $parameters['action'];
				$title = $this->titleFactory->makeTitle( $parameters['namespace'], $parameters['title'] );

				$result = $title->getRestrictions( $action );
				break;
			case 'simple-user-accessor':
				$user = $parameters['user'];
				$method = $parameters['method'];

				$result = $user->$method();
				break;
			case 'user-block':
				// @todo Support partial blocks
				$user = $parameters['user'];
				$result = (bool)$user->getBlock();
				break;
			case 'user-age':
				$user = $parameters['user'];
				$asOf = $parameters['asof'];

				if ( $user->getId() === 0 ) {
					$result = 0;
				} else {
					$registration = $user->getRegistration();
					// HACK: If there's no registration date, assume 2008-01-15, Wikipedia Day
					// in the year before the new user log was created. See T243469.
					if ( $registration === null ) {
						$registration = "20080115000000";
					}
					$result = (int)wfTimestamp( TS_UNIX, $asOf ) - (int)wfTimestamp( TS_UNIX, $registration );
				}
				break;
			case 'page-age':
				$title = $this->titleFactory->makeTitle( $parameters['namespace'], $parameters['title'] );

				$firstRevisionTime = $title->getEarliestRevTime();
				if ( !$firstRevisionTime ) {
					$result = 0;
					break;
				}

				$asOf = $parameters['asof'];
				$result = (int)wfTimestamp( TS_UNIX, $asOf ) - (int)wfTimestamp( TS_UNIX, $firstRevisionTime );
				break;
			case 'length':
				$s = $vars->getVar( $parameters['length-var'] )->toString();
				$result = strlen( $s );
				break;
			case 'subtract-int':
				$v1 = $vars->getVar( $parameters['val1-var'] )->toInt();
				$v2 = $vars->getVar( $parameters['val2-var'] )->toInt();
				$result = $v1 - $v2;
				break;
			case 'revision-text-by-id':
				$revRec = $this->revisionLookup->getRevisionById( $parameters['revid'] );
				$result = AbuseFilter::revisionToString( $revRec, $parameters['contextUser'] );
				break;
			case 'get-wiki-name':
				$result = $this->wikiID;
				break;
			case 'get-wiki-language':
				$result = $this->contentLanguage->getCode();
				break;
			default:
				if ( $this->hookRunner->onAbuseFilterComputeVariable(
					$var->mMethod,
					$vars,
					$parameters,
					$result
				) ) {
					throw new AFPException( 'Unknown variable compute type ' . $var->mMethod );
				}
		}

		return $result instanceof AFPData
			? $result : AFPData::newFromPHPVar( $result );
	}

	/**
	 * @param WikiPage $article
	 * @return array
	 */
	private function getLinksFromDB( WikiPage $article ) {
		// Stolen from ConfirmEdit, SimpleCaptcha::getLinksFromTracker
		$id = $article->getId();
		if ( !$id ) {
			return [];
		}

		$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );
		return $dbr->selectFieldValues(
			'externallinks',
			'el_to',
			[ 'el_from' => $id ],
			__METHOD__
		);
	}

	/**
	 * @todo Is this method necessary
	 * @param int $namespace
	 * @param string $title
	 * @return WikiPage
	 */
	private function pageFromTitle( $namespace, $title ) {
		if ( isset( self::$articleCache["$namespace:$title"] ) ) {
			return self::$articleCache["$namespace:$title"];
		}

		if ( count( self::$articleCache ) > 1000 ) {
			self::$articleCache = [];
		}

		$this->logger->debug( "Creating wikipage object for $namespace:$title in cache" );

		$t = $this->titleFactory->makeTitle( $namespace, $title );
		self::$articleCache["$namespace:$title"] = WikiPage::factory( $t );

		return self::$articleCache["$namespace:$title"];
	}

	/**
	 * @param Title $title
	 * @return string[] Usernames of the last 10 (unique) authors from $title
	 */
	private function getLastPageAuthors( Title $title ) {
		if ( !$title->exists() ) {
			return [];
		}

		$fname = __METHOD__;

		return $this->wanCache->getWithSetCallback(
			$this->wanCache->makeKey( 'last-10-authors', 'revision', $title->getLatestRevID() ),
			WANObjectCache::TTL_MINUTE,
			function ( $oldValue, &$ttl, array &$setOpts ) use ( $title, $fname ) {
				$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );
				$setOpts += Database::getCacheSetOptions( $dbr );
				// Get the last 100 edit authors with a trivial query (avoid T116557)
				$revQuery = $this->revisionStore->getQueryInfo();
				$revAuthors = $dbr->selectFieldValues(
					$revQuery['tables'],
					$revQuery['fields']['rev_user_text'],
					[ 'rev_page' => $title->getArticleID() ],
					$fname,
					// Some pages have < 10 authors but many revisions (e.g. bot pages)
					[ 'ORDER BY' => 'rev_timestamp DESC, rev_id DESC',
						'LIMIT' => 100,
						// Force index per T116557
						'USE INDEX' => [ 'revision' => 'page_timestamp' ],
					],
					$revQuery['joins']
				);
				// Get the last 10 distinct authors within this set of edits
				$users = [];
				foreach ( $revAuthors as $author ) {
					$users[$author] = 1;
					if ( count( $users ) >= 10 ) {
						break;
					}
				}

				return array_keys( $users );
			}
		);
	}

	/**
	 * It's like Article::prepareContentForEdit, but not for editing (old wikitext usually)
	 *
	 *
	 * @param string $wikitext
	 * @param WikiPage $article
	 * @param User $user Context user
	 *
	 * @return stdClass
	 */
	private function parseNonEditWikitext( $wikitext, WikiPage $article, User $user ) {
		static $cache = [];

		$cacheKey = md5( $wikitext ) . ':' . $article->getTitle()->getPrefixedText();

		if ( isset( $cache[$cacheKey] ) ) {
			return $cache[$cacheKey];
		}

		$edit = (object)[];
		$options = ParserOptions::newFromUser( $user );
		$edit->output = $this->parser->parse( $wikitext, $article->getTitle(), $options );
		$cache[$cacheKey] = $edit;

		return $edit;
	}
}
