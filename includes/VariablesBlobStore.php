<?php

namespace MediaWiki\Extension\AbuseFilter;

use AbuseFilterVariableHolder;
use FormatJson;
use MediaWiki\Storage\BlobAccessException;
use MediaWiki\Storage\BlobStore;
use MediaWiki\Storage\BlobStoreFactory;

/**
 * This service is used to store and load var dumps to a BlobStore
 */
class VariablesBlobStore {
	public const SERVICE_NAME = 'AbuseFilterVariablesBlobStore';

	/** @var BlobStoreFactory */
	private $blobStoreFactory;

	/** @var BlobStore */
	private $blobStore;

	/** @var string|null */
	private $centralDB;

	/**
	 * @param BlobStoreFactory $blobStoreFactory
	 * @param BlobStore $blobStore
	 * @param string|null $centralDB
	 */
	public function __construct( BlobStoreFactory $blobStoreFactory, BlobStore $blobStore, ?string $centralDB ) {
		$this->blobStoreFactory = $blobStoreFactory;
		$this->blobStore = $blobStore;
		$this->centralDB = $centralDB;
	}

	/**
	 * Store a var dump to a BlobStore.
	 *
	 * @param AbuseFilterVariableHolder $varsHolder
	 * @param bool $global
	 *
	 * @return string Address of the record
	 */
	public function storeVarDump( AbuseFilterVariableHolder $varsHolder, $global = false ) {
		// Get all variables yet set and compute old and new wikitext if not yet done
		// as those are needed for the diff view on top of the abuse log pages
		$vars = $varsHolder->dumpAllVars( [ 'old_wikitext', 'new_wikitext' ] );

		// Vars is an array with native PHP data types (non-objects) now
		$text = FormatJson::encode( $vars );

		$dbDomain = $global ? $this->centralDB : false;
		$blobStore = $this->blobStoreFactory->newBlobStore( $dbDomain );

		$hints = [
			BlobStore::DESIGNATION_HINT => 'AbuseFilter',
			BlobStore::MODEL_HINT => 'AbuseFilter',
		];
		return $blobStore->storeBlob( $text, $hints );
	}

	/**
	 * Retrieve a var dump from a BlobStore.
	 *
	 * @param string $address
	 *
	 * @return AbuseFilterVariableHolder
	 */
	public function loadVarDump( string $address ) : AbuseFilterVariableHolder {
		try {
			$blob = $this->blobStore->getBlob( $address );
		} catch ( BlobAccessException $ex ) {
			return new AbuseFilterVariableHolder;
		}

		$vars = FormatJson::decode( $blob, true );
		$obj = AbuseFilterVariableHolder::newFromArray( $vars );
		$obj->translateDeprecatedVars();
		return $obj;
	}
}
