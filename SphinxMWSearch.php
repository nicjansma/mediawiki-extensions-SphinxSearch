<?php

/**
 * Class file for the SphinxMWSearch extension
 *
 * https://www.mediawiki.org/wiki/Extension:SphinxSearch
 *
 * Released under GNU General Public License (see http://www.fsf.org/licenses/gpl.html)
 *
 * @file
 * @ingroup Extensions
 * @author Svemir Brkic <svemir@deveblog.com>
 */

use MediaWiki\MediaWikiServices;

class SphinxMWSearch extends SearchDatabase {

	/** @var array */
	public $categories = [];

	/** @var array */
	public $excludeCategories = [];

	/** @var SphinxClient|null */
	public $sphinxClient = null;

	/** @var string[] */
	public $prefixHandlers = [
		'intitle' => 'filterByTitle',
		'incategory' => 'filterByCategory',
		'prefix' => 'filterByPrefix',
	];

	public static function initialize() {
		global $wgSearchType, $wgDisableSearchUpdate;

		if ( !class_exists( SphinxClient::class ) ) {
			if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
				require_once __DIR__ . '/vendor/autoload.php';
			}

			if ( !class_exists( SphinxClient::class ) ) {
				require_once __DIR__ . '/sphinxapi.php';
			}
		}

		if ( $wgSearchType == 'SphinxMWSearch' ) {
			$wgDisableSearchUpdate = true;
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function doSearchTextInDB( $term ) {
		return $this->searchTextInternal( $term, $term );
	}

	/**
	 * @inheritDoc
	 */
	protected function doSearchTitleInDB( $term ) {
		return $this->searchTextInternal( $term, static::getTitleSearchClause( $term ) );
	}

	/**
	 * @param string $term Search term
	 * @return string Search clause
	 */
	protected static function getTitleSearchClause( $term ) {
		global $wgEnableSphinxInfixSearch;

		$searchPrefix = $wgEnableSphinxInfixSearch ? '*' : '^';

		return "@page_title: {$searchPrefix}{$term}*";
	}

	/**
	 * @param string $search
	 * @return SearchSuggestionSet
	 */
	protected function completionSearchBackend( $search ) {
		global $wgEnableSphinxPrefixSearch;

		// fallback to parent if we don't want to do prefix searching
		if ( $this->namespaces === [ -1 ] || !$wgEnableSphinxPrefixSearch ) {
			return parent::completionSearchBackend( $search );
		}

		// Get a new Sphinx search engine
		$searchEngine = new SphinxMWSearch( MediaWikiServices::getInstance()->getDBLoadBalancerFactory() );
		$searchEngine->namespaces = $this->namespaces;
		$searchEngine->setLimitOffset( $this->limit, $this->offset );

		// use own term and clause
		$resultSet = $searchEngine->searchTextInternal( $search, self::getTitleSearchClause( $search ) );

		// convert to search suggestions
		$results = [];
		if ( $resultSet ) {
			$res = $resultSet->next();
			while ( $res ) {
				$results[ ] = $res->getTitle();

				$res = $resultSet->next();
			}
		}

		return SearchSuggestionSet::fromTitles( $results );
	}

	/**
	 * Perform a full text search query and return a result set.
	 *
	 * @param string $term Raw search term
	 * @return SphinxMWSearchResultSet
	 */
	public function searchText( $term ) {
		return $this->searchTextInternal( $term, $term );
	}

	/**
	 * Perform a full text search query, based on a term and clause,
	 * and return a result set.
	 *
	 * @param string $term Raw search term
	 * @param string $searchClause Search clause
	 * @return SphinxMWSearchResultSet
	 */
	private function searchTextInternal( $term, $searchClause ) {
		global $wgSphinxSearch_index_list, $wgSphinxSuggestMode;

		wfDebug( "SphinxMWSearch::searchText: running for '{$term}', clause: '{$searchClause}'" );

		if ( !$this->sphinxClient ) {
			$this->sphinxClient = $this->prepareSphinxClient( $term );
		}

		if ( $this->sphinxClient ) {
			$this->searchTerms = $term;

			$escape = '/';
			$delims = [
				'(' => ')',
				'[' => ']',
				'"' => '',
			];

			// temporarily replace already escaped characters
			$placeholders = [
				'\\(' => '_PLC_O_PAR_',
				'\\)' => '_PLC_C_PAR_',
				'\\[' => '_PLC_O_BRA_',
				'\\]' => '_PLC_C_BRA_',
				'\\"' => '_PLC_QUOTE_',
			];

			$clause = str_replace( array_keys( $placeholders ), $placeholders, $searchClause );

			foreach ( $delims as $open => $close ) {
				$open_cnt = substr_count( $clause, $open );
				if ( $close ) {
					// if counts do not match, escape them all
					$close_cnt = substr_count( $clause, $close );

					if ( $open_cnt != $close_cnt ) {
						$escape .= $open . $close;
					}
				} elseif ( $open_cnt % 2 == 1 ) {
					// if there is no closing symbol, count should be even
					$escape .= $open;
				}
			}

			$clause = str_replace( $placeholders, array_keys( $placeholders ), $clause );
			$clause = addcslashes( $clause, $escape );

			$resultSet = $this->sphinxClient->Query(
				$clause,
				$wgSphinxSearch_index_list
			);
		} else {
			$resultSet = false;
		}

		if ( $resultSet === false && !$wgSphinxSuggestMode ) {
			return null;
		} else {
			return new SphinxMWSearchResultSet(
				$resultSet,
				$term,
				$this->sphinxClient,
				$this->dbProvider->getReplicaDatabase()
			);
		}
	}

	/**
	 * @param string &$term
	 * @return SphinxClient ready to run or false if term is empty
	 */
	private function prepareSphinxClient( &$term ) {
		global $wgSphinxSearch_sortmode, $wgSphinxSearch_sortby, $wgSphinxSearch_host,
			$wgSphinxSearch_port, $wgSphinxSearch_index_weights,
			$wgSphinxSearch_mode, $wgSphinxSearch_maxmatches,
			$wgSphinxSearch_cutoff, $wgSphinxSearch_weights;

		// don't do anything for blank searches
		if ( trim( $term ) === '' ) {
			return false;
		}

		$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
		$hookContainer->run( 'SphinxSearchBeforeResults', [
			&$term,
			&$this->offset,
			&$this->namespaces,
			&$this->categories,
			&$this->excludeCategories
		] );

		$cl = new SphinxClient();

		$cl->SetServer( $wgSphinxSearch_host, $wgSphinxSearch_port );
		if ( $wgSphinxSearch_weights && count( $wgSphinxSearch_weights ) ) {
			$cl->SetFieldWeights( $wgSphinxSearch_weights );
		}
		if ( is_array( $wgSphinxSearch_index_weights ) ) {
			$cl->SetIndexWeights( $wgSphinxSearch_index_weights );
		}
		if ( $wgSphinxSearch_mode ) {
			$cl->SetMatchMode( $wgSphinxSearch_mode );
		}
		if ( $this->namespaces && count( $this->namespaces ) ) {
			$cl->SetFilter( 'page_namespace', $this->namespaces );
		}
		if ( $this->categories && count( $this->categories ) ) {
			$cl->SetFilter( 'category', $this->categories );
			wfDebug( "SphinxSearch included categories: " . implode( ', ', $this->categories ) . "\n" );
		}
		if ( $this->excludeCategories && count( $this->excludeCategories ) ) {
			$cl->SetFilter( 'category', $this->excludeCategories, true );
			wfDebug( "SphinxSearch excluded categories: " . implode( ', ', $this->excludeCategories ) . "\n" );
		}

		$cl->SetSortMode( $wgSphinxSearch_sortmode, $wgSphinxSearch_sortby );

		$cl->SetLimits(
			$this->offset,
			$this->limit,
			$wgSphinxSearch_maxmatches,
			$wgSphinxSearch_cutoff
		);

		$hookContainer->run( 'SphinxSearchBeforeQuery', [ &$term, &$cl ] );

		return $cl;
	}

	/**
	 * Prepare query for sphinx search daemon
	 *
	 * @param string $query
	 * @return string rewritten query
	 */
	public function replacePrefixes( $query ) {
		if ( trim( $query ) === '' ) {
			return $query;
		}

		// ~ prefix is used to avoid near-term search, remove it now
		if ( $query[ 0 ] === '~' ) {
			$query = substr( $query, 1 );
		}

		$parts = preg_split( '/(")/', $query, -1, PREG_SPLIT_DELIM_CAPTURE );
		$inquotes = false;
		$rewritten = '';

		foreach ( $parts as $key => $part ) {
			if ( $part == '"' ) {
				// stuff in quotes doesn't get rewritten
				$rewritten .= $part;
				$inquotes = !$inquotes;
			} elseif ( $inquotes ) {
				$rewritten .= $part;
			} else {
				if ( strpos( $query, ':' ) !== false ) {
					$regexp = $this->preparePrefixRegexp();
					$part = preg_replace_callback(
						'/(^|[| :]|-)(' . $regexp . '):([^ ]+)/i',
						[ $this, 'replaceQueryPrefix' ],
						$part
					);
				}

				$rewritten .= str_replace(
					[ ' OR ', ' AND ' ],
					[ ' | ', ' & ' ],
					$part
				);
			}
		}
		return $rewritten;
	}

	/**
	 * @return string Regexp to match namespaces and other prefixes
	 */
	private function preparePrefixRegexp() {
		global $wgLang, $wgCanonicalNamespaceNames, $wgNamespaceAliases;

		// "search everything" keyword
		$allkeyword = wfMessage( 'searchall' )->inContentLanguage()->text();
		$this->prefixHandlers[ $allkeyword ] = 'searchAllNamespaces';

		$all_prefixes = array_merge(
			$wgLang->getNamespaces(),
			$wgCanonicalNamespaceNames,
			array_keys( array_merge( $wgNamespaceAliases, $wgLang->getNamespaceAliases() ) ),
			array_keys( $this->prefixHandlers )
		);

		$regexp_prefixes = [];
		foreach ( $all_prefixes as $prefix ) {
			if ( $prefix != '' ) {
				$regexp_prefixes[] = preg_quote( str_replace( ' ', '_', $prefix ), '/' );
			}
		}

		return implode( '|', array_unique( $regexp_prefixes ) );
	}

	/**
	 * preg callback to process foo: prefixes in the query
	 *
	 * @param array $matches
	 * @return string
	 */
	public function replaceQueryPrefix( $matches ) {
		if ( isset( $this->prefixHandlers[ $matches[ 2 ] ] ) ) {
			$callback = $this->prefixHandlers[ $matches[ 2 ] ];
			return $this->$callback( $matches );
		} else {
			return $this->filterByNamespace( $matches );
		}
	}

	/**
	 * @param string[] $matches
	 * @return string
	 */
	public function filterByNamespace( $matches ) {
		global $wgLang;
		$inx = $wgLang->getNsIndex( str_replace( ' ', '_', $matches[ 2 ] ) );

		if ( $inx === false ) {
			return $matches[ 0 ];
		} else {
			$this->namespaces[] = $inx;
			return $matches[ 3 ];
		}
	}

	/**
	 * @param string[] $matches
	 * @return string
	 */
	public function searchAllNamespaces( $matches ) {
		$this->namespaces = null;
		return $matches[ 3 ];
	}

	/**
	 * @param string[] $matches
	 * @return string
	 */
	public function filterByTitle( $matches ) {
		return '@page_title ' . $matches[ 3 ];
	}

	/**
	 * @param string[] $matches
	 * @return string
	 */
	public function filterByPrefix( $matches ) {
		$prefix = $matches[ 3 ];
		if ( strpos( $matches[ 3 ], ':' ) !== false ) {
			global $wgLang;

			[ $ns, $prefix ] = explode( ':', $matches[ 3 ] );

			$inx = $wgLang->getNsIndex( str_replace( ' ', '_', $ns ) );

			if ( $inx !== false ) {
				$this->namespaces = [ $inx ];
			}
		}
		return "@page_title ^{$prefix}*";
	}

	/**
	 * @param string[] $matches
	 * @return string
	 */
	public function filterByCategory( $matches ) {
		$page_id = $this->dbProvider->getReplicaDatabase()->selectField( 'page', 'page_id',
			[
				'page_title' => $matches[ 3 ],
				'page_namespace' => NS_CATEGORY
			],
			__METHOD__
		);

		$category = intval( $page_id );

		if ( $matches[ 1 ] === '-' ) {
			$this->excludeCategories[ ] = $category;
		} else {
			$this->categories[ ] = $category;
		}

		return '';
	}

	public static function regexTerm( $string, $wildcard ) {
		$regex = preg_quote( $string, '/' );

		if ( MediaWikiServices::getInstance()->getContentLanguage()->hasWordBreaks() ) {
			if ( $wildcard ) {
				// Don't cut off the final bit!
				$regex = "\b$regex";
			} else {
				$regex = "\b$regex\b";
			}
		} else {
			// For Chinese, words may legitimately abut other words in the text literal.
			// Don't add \b boundary checks... note this could cause false positives
			// for Latin chars.
		}

		return $regex;
	}
}
