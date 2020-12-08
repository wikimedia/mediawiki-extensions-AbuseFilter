<?php

use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterHookRunner;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\Database;

class AFComputedVariable {
	/**
	 * @var string The method used to compute the variable
	 */
	public $mMethod;
	/**
	 * @var array Parameters to be used with the specified method
	 */
	public $mParameters;
	/**
	 * @var WikiPage[] Cache containing Page objects already constructed
	 */
	public static $articleCache = [];

	/** @var float The amount of time to subtract from profiling */
	public static $profilingExtraTime = 0;

	/**
	 * @param string $method
	 * @param array $parameters
	 */
	public function __construct( $method, $parameters ) {
		$this->mMethod = $method;
		$this->mParameters = $parameters;
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
	public function parseNonEditWikitext( $wikitext, WikiPage $article, User $user ) {
		static $cache = [];

		$cacheKey = md5( $wikitext ) . ':' . $article->getTitle()->getPrefixedText();

		if ( isset( $cache[$cacheKey] ) ) {
			return $cache[$cacheKey];
		}

		$edit = (object)[];
		$options = ParserOptions::newFromUser( $user );
		$parser = MediaWikiServices::getInstance()->getParser();
		$edit->output = $parser->parse( $wikitext, $article->getTitle(), $options );
		$cache[$cacheKey] = $edit;

		return $edit;
	}

	/**
	 * @param int $namespace
	 * @param string $title
	 * @return WikiPage
	 */
	public function pageFromTitle( $namespace, $title ) {
		if ( isset( self::$articleCache["$namespace:$title"] ) ) {
			return self::$articleCache["$namespace:$title"];
		}

		if ( count( self::$articleCache ) > 1000 ) {
			self::$articleCache = [];
		}

		$logger = LoggerFactory::getInstance( 'AbuseFilter' );
		$logger->debug( "Creating wikipage object for $namespace:$title in cache" );

		$t = $this->buildTitle( $namespace, $title );
		self::$articleCache["$namespace:$title"] = WikiPage::factory( $t );

		return self::$articleCache["$namespace:$title"];
	}

	/**
	 * Mockable wrapper
	 *
	 * @param int $namespace
	 * @param string $title
	 * @return Title
	 */
	protected function buildTitle( $namespace, $title ) : Title {
		return Title::makeTitle( $namespace, $title );
	}

	/**
	 * @param WikiPage $article
	 * @return array
	 */
	public static function getLinksFromDB( WikiPage $article ) {
		// Stolen from ConfirmEdit, SimpleCaptcha::getLinksFromTracker
		$id = $article->getId();
		if ( !$id ) {
			return [];
		}

		$dbr = wfGetDB( DB_REPLICA );
		return $dbr->selectFieldValues(
			'externallinks',
			'el_to',
			[ 'el_from' => $id ],
			__METHOD__
		);
	}

	/**
	 * @param AbuseFilterVariableHolder $vars
	 * @return AFPData
	 * @throws MWException
	 * @throws AFPException
	 */
	public function compute( AbuseFilterVariableHolder $vars ) {
		// phpcs:ignore MediaWiki.Usage.DeprecatedGlobalVariables.Deprecated$wgUser
		global $wgUser;

		// Used for parsing wikitext from saved revisions and checking for
		// whether to show fields. Do not use $wgUser below here, in preparation
		// for eventually injecting. See T246733
		$computeForUser = $wgUser;

		$vars->setLogger( LoggerFactory::getInstance( 'AbuseFilter' ) );
		$parameters = $this->mParameters;
		$result = null;

		$hookRunner = AbuseFilterHookRunner::getRunner();

		if ( !$hookRunner->onAbuseFilterInterceptVariable(
			$this->mMethod,
			$vars,
			$parameters,
			$result
		) ) {
			return $result instanceof AFPData
				? $result : AFPData::newFromPHPVar( $result );
		}

		$services = MediaWikiServices::getInstance();
		switch ( $this->mMethod ) {
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
				if ( isset( $parameters['article'] ) ) {
					$article = $parameters['article'];
				} else {
					$article = $this->pageFromTitle(
						$parameters['namespace'],
						$parameters['title']
					);
				}
				if ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					// Shared with the edit, don't count it in profiling
					$startTime = microtime( true );
					$textVar = $parameters['text-var'];

					$new_text = $vars->getVar( $textVar )->toString();
					$content = ContentHandler::makeContent( $new_text, $article->getTitle() );
					try {
						// @fixme TEMPORARY WORKAROUND FOR T187153
						$editInfo = $article->prepareContentForEdit( $content );
						$links = array_keys( $editInfo->output->getExternalLinks() );
					} catch ( Error $e ) {
						$logger = LoggerFactory::getInstance( 'AbuseFilter' );
						$logger->warning( 'Caught Error, case 1 - T187153' );
						$links = [];
					}
					$result = $links;
					self::$profilingExtraTime += ( microtime( true ) - $startTime );
					break;
				}
				// Otherwise fall back to database
			case 'links-from-wikitext-nonedit':
			case 'links-from-wikitext-or-database':
				// TODO: use Content object instead, if available!
				$article = $this->pageFromTitle(
					$parameters['namespace'],
					$parameters['title']
				);

				$logger = LoggerFactory::getInstance( 'AbuseFilter' );
				if ( $vars->forFilter ) {
					$links = $this->getLinksFromDB( $article );
					$logger->debug( 'Loading old links from DB' );
				} elseif ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					$logger->debug( 'Loading old links from Parser' );
					$textVar = $parameters['text-var'];

					$wikitext = $vars->getVar( $textVar )->toString();
					$editInfo = $this->parseNonEditWikitext(
						$wikitext,
						$article,
						$computeForUser
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

				if ( $this->mMethod === 'link-diff-added' ) {
					$result = array_diff( $newLinks, $oldLinks );
				}
				if ( $this->mMethod === 'link-diff-removed' ) {
					$result = array_diff( $oldLinks, $newLinks );
				}
				break;
			case 'parse-wikitext':
				// Should ONLY be used when sharing a parse operation with the edit.
				if ( isset( $parameters['article'] ) ) {
					$article = $parameters['article'];
				} else {
					$article = $this->pageFromTitle(
						$parameters['namespace'],
						$parameters['title']
					);
				}
				if ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					// Shared with the edit, don't count it in profiling
					$startTime = microtime( true );
					$textVar = $parameters['wikitext-var'];

					$new_text = $vars->getVar( $textVar )->toString();
					$content = ContentHandler::makeContent( $new_text, $article->getTitle() );
					try {
						// @fixme TEMPORARY WORKAROUND FOR T187153
						$editInfo = $article->prepareContentForEdit( $content );
					} catch ( Error $e ) {
						$logger = LoggerFactory::getInstance( 'AbuseFilter' );
						$logger->warning( 'Caught Error, case 2 - T187153' );
						$result = '';
						break;
					}
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
			case 'parse-wikitext-nonedit':
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
							$computeForUser
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
				$title = $this->buildTitle( $parameters['namespace'], $parameters['title'] );
				if ( !$title->exists() ) {
					$result = '';
					break;
				}

				$result = self::getLastPageAuthors( $title );
				break;
			case 'load-first-author':
				$title = $this->buildTitle( $parameters['namespace'], $parameters['title'] );

				$revision = $services->getRevisionLookup()->getFirstRevision( $title );
				if ( $revision ) {
					$user = $revision->getUser();
					$result = $user === null ? '' : $user->getName();
				} else {
					$result = '';
				}

				break;
			case 'get-page-restrictions':
				$action = $parameters['action'];
				$title = $this->buildTitle( $parameters['namespace'], $parameters['title'] );

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
				$title = $this->buildTitle( $parameters['namespace'], $parameters['title'] );

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
				$revRec = $services
					->getRevisionLookup()
					->getRevisionById( $parameters['revid'] );
				$result = AbuseFilter::revisionToString( $revRec, $computeForUser );
				break;
			case 'get-wiki-name':
				$result = WikiMap::getCurrentWikiDbDomain()->getId();
				break;
			case 'get-wiki-language':
				$result = $services->getContentLanguage()->getCode();
				break;
			default:
				if ( $hookRunner->onAbuseFilterComputeVariable(
					$this->mMethod,
					$vars,
					$parameters,
					$result
				) ) {
					throw new AFPException( 'Unknown variable compute type ' . $this->mMethod );
				}
		}

		return $result instanceof AFPData
			? $result : AFPData::newFromPHPVar( $result );
	}

	/**
	 * @param Title $title
	 * @return string[] Usernames of the last 10 (unique) authors from $title
	 */
	public static function getLastPageAuthors( Title $title ) {
		if ( !$title->exists() ) {
			return [];
		}

		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$fname = __METHOD__;

		return $cache->getWithSetCallback(
			$cache->makeKey( 'last-10-authors', 'revision', $title->getLatestRevID() ),
			$cache::TTL_MINUTE,
			function ( $oldValue, &$ttl, array &$setOpts ) use ( $title, $fname ) {
				$dbr = wfGetDB( DB_REPLICA );
				$setOpts += Database::getCacheSetOptions( $dbr );
				// Get the last 100 edit authors with a trivial query (avoid T116557)
				$revQuery = MediaWikiServices::getInstance()->getRevisionStore()->getQueryInfo();
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
}
