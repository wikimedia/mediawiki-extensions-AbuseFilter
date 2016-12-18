<?php

class AFComputedVariable {
	public $mMethod, $mParameters;
	public static $userCache = array();
	public static $articleCache = array();

	/**
	 * @param $method
	 * @param $parameters
	 */
	function __construct( $method, $parameters ) {
		$this->mMethod = $method;
		$this->mParameters = $parameters;
	}

	/**
	 * It's like Article::prepareContentForEdit, but not for editing (old wikitext usually)
	 *
	 *
	 * @param string $wikitext
	 * @param WikiPage $article
	 *
	 * @return object
	 */
	function parseNonEditWikitext( $wikitext, $article ) {
		static $cache = array();

		$cacheKey = md5( $wikitext ) . ':' . $article->getTitle()->getPrefixedText();

		if ( isset( $cache[$cacheKey] ) ) {
			return $cache[$cacheKey];
		}

		global $wgParser;
		$edit = (object)array();
		$options = new ParserOptions;
		$options->setTidy( true );
		$edit->output = $wgParser->parse( $wikitext, $article->getTitle(), $options );
		$cache[$cacheKey] = $edit;

		return $edit;
	}

	/**
	 * For backwards compatibility: Get the user object belonging to a certain name
	 * in case a user name is given as argument. Nowadays user objects are passed
	 * directly but many old log entries rely on this.
	 *
	 * @param $user string|User
	 * @return User
	 */
	static function getUserObject( $user ) {
		if ( $user instanceof User ) {
			$username = $user->getName();
		} else {
			$username = $user;
			if ( isset( self::$userCache[$username] ) ) {
				return self::$userCache[$username];
			}

			wfDebug( "Couldn't find user $username in cache\n" );
		}

		if ( count( self::$userCache ) > 1000 ) {
			self::$userCache = array();
		}

		if ( $user instanceof User ) {
			$userCache[$username] = $user;
			return $user;
		}

		if ( IP::isIPAddress( $username ) ) {
			$u = new User;
			$u->setName( $username );
			self::$userCache[$username] = $u;
			return $u;
		}

		$user = User::newFromName( $username );
		$user->load();
		self::$userCache[$username] = $user;

		return $user;
	}

	/**
	 * @param $namespace
	 * @param $title Title
	 * @return Article
	 */
	static function articleFromTitle( $namespace, $title ) {
		if ( isset( self::$articleCache["$namespace:$title"] ) ) {
			return self::$articleCache["$namespace:$title"];
		}

		if ( count( self::$articleCache ) > 1000 ) {
			self::$articleCache = array();
		}

		wfDebug( "Creating article object for $namespace:$title in cache\n" );

		// TODO: use WikiPage instead!
		$t = Title::makeTitle( $namespace, $title );
		self::$articleCache["$namespace:$title"] = new Article( $t );

		return self::$articleCache["$namespace:$title"];
	}

	/**
	 * @param WikiPage $article
	 * @return array
	 */
	static function getLinksFromDB( $article ) {
		// Stolen from ConfirmEdit
		$id = $article->getId();
		if ( !$id ) {
			return array();
		}

		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			'externallinks',
			array( 'el_to' ),
			array( 'el_from' => $id ),
			__METHOD__
		);
		$links = array();
		foreach ( $res as $row ) {
			$links[] = $row->el_to;
		}
		return $links;
	}

	/**
	 * @param $vars AbuseFilterVariableHolder
	 * @return AFPData|array|int|mixed|null|string
	 * @throws MWException
	 * @throws AFPException
	 */
	function compute( $vars ) {
		$parameters = $this->mParameters;
		$result = null;

		if ( !Hooks::run( 'AbuseFilter-interceptVariable',
							array( $this->mMethod, $vars, $parameters, &$result ) ) ) {
			return $result instanceof AFPData
				? $result : AFPData::newFromPHPVar( $result );
		}

		switch ( $this->mMethod ) {
			case 'diff':
				$text1Var = $parameters['oldtext-var'];
				$text2Var = $parameters['newtext-var'];
				$text1 = $vars->getVar( $text1Var )->toString() . "\n";
				$text2 = $vars->getVar( $text2Var )->toString() . "\n";
				$result = wfDiff( $text1, $text2 );
				break;
			case 'diff-split':
				$diff = $vars->getVar( $parameters['diff-var'] )->toString();
				$line_prefix = $parameters['line-prefix'];
				$diff_lines = explode( "\n", $diff );
				$interest_lines = array();
				foreach ( $diff_lines as $line ) {
					if ( substr( $line, 0, 1 ) === $line_prefix ) {
						$interest_lines[] = substr( $line, strlen( $line_prefix ) );
					}
				}
				$result = $interest_lines;
				break;
			case 'links-from-wikitext':
				// This should ONLY be used when sharing a parse operation with the edit.

				/* @var WikiPage $article */
				if ( isset( $parameters['article'] ) ) {
					$article = $parameters['article'];
				} else {
					$article = self::articleFromTitle(
						$parameters['namespace'],
						$parameters['title']
					);
				}
				if ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					$textVar = $parameters['text-var'];

					// XXX: Use prepareContentForEdit. But we need a Content object for that.
					$new_text = $vars->getVar( $textVar )->toString();
					$content = ContentHandler::makeContent( $new_text, $article->getTitle() );
					$editInfo = $article->prepareContentForEdit( $content );
					$links = array_keys( $editInfo->output->getExternalLinks() );
					$result = $links;
					break;
				}
				// Otherwise fall back to database
			case 'links-from-wikitext-nonedit':
			case 'links-from-wikitext-or-database':
				// TODO: use Content object instead, if available! In any case, use WikiPage, not Article.
				$article = self::articleFromTitle(
					$parameters['namespace'],
					$parameters['title']
				);

				if ( $vars->getVar( 'context' )->toString() == 'filter' ) {
					$links = $this->getLinksFromDB( $article );
					wfDebug( "AbuseFilter: loading old links from DB\n" );
				} elseif ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {

					wfDebug( "AbuseFilter: loading old links from Parser\n" );
					$textVar = $parameters['text-var'];

					$wikitext = $vars->getVar( $textVar )->toString();
					$editInfo = $this->parseNonEditWikitext( $wikitext, $article );
					$links = array_keys( $editInfo->output->getExternalLinks() );
				} else {
					// TODO: Get links from Content object. But we don't have the content object.
					//      And for non-text content, $wikitext is usually not going to be a valid
					//      serialization, but rather some dummy text for filtering.
					$links = array();
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

				if ( $this->mMethod == 'link-diff-added' ) {
					$result = array_diff( $newLinks, $oldLinks );
				}
				if ( $this->mMethod == 'link-diff-removed' ) {
					$result = array_diff( $oldLinks, $newLinks );
				}
				break;
			case 'parse-wikitext':
				// Should ONLY be used when sharing a parse operation with the edit.
				if ( isset( $parameters['article'] ) ) {
					$article = $parameters['article'];
				} else {
					$article = self::articleFromTitle(
						$parameters['namespace'],
						$parameters['title']
					);
				}
				if ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
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
						$result = preg_replace( '/<!--\s*NewPP limit report[^>]*-->\s*$/si', '', $newHTML );
					}
					break;
				}
				// Otherwise fall back to database
			case 'parse-wikitext-nonedit':
				// TODO: use Content object instead, if available! In any case, use WikiPage, not Article.
				$article = self::articleFromTitle( $parameters['namespace'], $parameters['title'] );
				$textVar = $parameters['wikitext-var'];

				if ( $article->getContentModel() === CONTENT_MODEL_WIKITEXT ) {

					if ( isset( $parameters['pst'] ) && $parameters['pst'] ) {
						// $textVar is already PSTed when it's not loaded from an ongoing edit.
						$result = $vars->getVar( $textVar )->toString();
					} else {
						$text = $vars->getVar( $textVar )->toString();
						$editInfo = $this->parseNonEditWikitext( $text, $article );
						$result = $editInfo->output->getText();
					}
				} else {
					// TODO: Parser Output from Content object. But we don't have the content object.
					//      And for non-text content, $wikitext is usually not going to be a valid
					//      serialization, but rather some dummy text for filtering.
					$result = '';
				}

				break;
			case 'strip-html':
				$htmlVar = $parameters['html-var'];
				$html = $vars->getVar( $htmlVar )->toString();
				$result = StringUtils::delimiterReplace( '<', '>', '', $html );
				break;
			case 'load-recent-authors':
				$title = Title::makeTitle( $parameters['namespace'], $parameters['title'] );
				if ( !$title->exists() ) {
					$result = '';
					break;
				}

				$result = self::getLastPageAuthors( $title );
				break;
			case 'load-first-author':
				$title = Title::makeTitle( $parameters['namespace'], $parameters['title'] );

				$revision = $title->getFirstRevision();
				if ( $revision ) {
					$result = $revision->getUserText();
				} else {
					$result = '';
				}

				break;
			case 'get-page-restrictions':
				$action = $parameters['action'];
				$title = Title::makeTitle( $parameters['namespace'], $parameters['title'] );

				$rights = $title->getRestrictions( $action );
				$rights = count( $rights ) ? $rights : array();
				$result = $rights;
				break;
			case 'simple-user-accessor':
				$user = $parameters['user'];
				$method = $parameters['method'];

				if ( !$user ) {
					throw new MWException( 'No user parameter given.' );
				}

				$obj = self::getUserObject( $user );

				if ( !$obj ) {
					throw new MWException( "Invalid username $user" );
				}

				$result = call_user_func( array( $obj, $method ) );
				break;
			case 'user-age':
				$user = $parameters['user'];
				$asOf = $parameters['asof'];
				$obj = self::getUserObject( $user );

				if ( $obj->getId() == 0 ) {
					$result = 0;
					break;
				}

				$registration = $obj->getRegistration();
				$result = wfTimestamp( TS_UNIX, $asOf ) - wfTimestampOrNull( TS_UNIX, $registration );
				break;
			case 'user-groups':
				// Deprecated but needed by old log entries
				$user = $parameters['user'];
				$obj = self::getUserObject( $user );
				$result = $obj->getEffectiveGroups();
				break;
			case 'length':
				$s = $vars->getVar( $parameters['length-var'] )->toString();
				$result = strlen( $s );
				break;
			case 'subtract':
				$v1 = $vars->getVar( $parameters['val1-var'] )->toFloat();
				$v2 = $vars->getVar( $parameters['val2-var'] )->toFloat();
				$result = $v1 - $v2;
				break;
			case 'revision-text-by-id':
				$rev = Revision::newFromId( $parameters['revid'] );
				$result = AbuseFilter::revisionToString( $rev );
				break;
			case 'revision-text-by-timestamp':
				$timestamp = $parameters['timestamp'];
				$title = Title::makeTitle( $parameters['namespace'], $parameters['title'] );
				$dbr = wfGetDB( DB_SLAVE );
				$rev = Revision::loadFromTimestamp( $dbr, $title, $timestamp );
				$result = AbuseFilter::revisionToString( $rev );
				break;
			default:
				if ( Hooks::run( 'AbuseFilter-computeVariable',
									array( $this->mMethod, $vars, $parameters, &$result ) ) ) {
					throw new AFPException( 'Unknown variable compute type ' . $this->mMethod );
				}
		}

		return $result instanceof AFPData
			? $result : AFPData::newFromPHPVar( $result );
	}

	/**
	 * @param Title $title
	 * @return string[] List of the last 10 (unique) authors from $title
	 */
	public static function getLastPageAuthors( Title $title ) {
		if ( !$title->exists() ) {
			return array();
		}

		$cache = ObjectCache::getMainWANInstance();

		return $cache->getWithSetCallback(
			$cache->makeKey( 'last-10-authors', 'revision', $title->getLatestRevID() ),
			$cache::TTL_MINUTE,
			function ( $oldValue, &$ttl, array &$setOpts ) use ( $title ) {
				$dbr = wfGetDB( DB_SLAVE );
				$setOpts += Database::getCacheSetOptions( $dbr );
				// Get the last 100 edit authors with a trivial query (avoid T116557)
				$revAuthors = $dbr->selectFieldValues(
					'revision',
					'rev_user_text',
					array( 'rev_page' => $title->getArticleID() ),
					__METHOD__,
					// Some pages have < 10 authors but many revisions (e.g. bot pages)
					array( 'ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 100 )
				);
				// Get the last 10 distinct authors within this set of edits
				$users = array();
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
