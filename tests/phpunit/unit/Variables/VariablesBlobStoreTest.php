<?php

namespace MediaWiki\Extension\AbuseFilter\Tests\Unit\Variables;

use InvalidArgumentException;
use MediaWiki\Extension\AbuseFilter\KeywordsManager;
use MediaWiki\Extension\AbuseFilter\Parser\AFPData;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesBlobStore;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Json\FormatJson;
use MediaWiki\Storage\BlobAccessException;
use MediaWiki\Storage\BlobStore;
use MediaWiki\Storage\BlobStoreFactory;
use MediaWikiUnitTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Test
 * @group AbuseFilter
 * @covers \MediaWiki\Extension\AbuseFilter\Variables\VariablesBlobStore
 */
class VariablesBlobStoreTest extends MediaWikiUnitTestCase {

	private function getStore(
		?BlobStoreFactory $blobStoreFactory = null,
		?BlobStore $blobStore = null
	): VariablesBlobStore {
		$manager = $this->createMock( VariablesManager::class );
		$manager->method( 'dumpAllVars' )->willReturnCallback( static function ( VariableHolder $holder ) {
			$ret = [];
			foreach ( $holder->getVars() as $name => $var ) {
				if ( $var instanceof AFPData ) {
					$ret[$name] = $var->toNative();
				}
			}
			return $ret;
		} );
		$manager->method( 'translateDeprecatedVars' )->willReturnCallback( static function ( VariableHolder $holder ) {
			$depVars = TestingAccessWrapper::constant( KeywordsManager::class, 'DEPRECATED_VARS' );
			foreach ( $holder->getVars() as $name => $value ) {
				if ( array_key_exists( $name, $depVars ) ) {
					$holder->setVar( $depVars[$name], $value );
					$holder->removeVar( $name );
				}
			}
		} );
		return new VariablesBlobStore(
			$manager,
			$blobStoreFactory ?? $this->createMock( BlobStoreFactory::class ),
			$blobStore ?? $this->createMock( BlobStore::class ),
			null
		);
	}

	public function testStoreVarDump() {
		$expectID = 123456;
		$blobStore = $this->createMock( BlobStore::class );
		$blobStore->expects( $this->once() )->method( 'storeBlob' )->willReturn( $expectID );
		$blobStoreFactory = $this->createMock( BlobStoreFactory::class );
		$blobStoreFactory->method( 'newBlobStore' )->willReturn( $blobStore );
		$varBlobStore = $this->getStore( $blobStoreFactory );
		$this->assertSame( $expectID, $varBlobStore->storeVarDump( new VariableHolder() ) );
	}

	public function testLoadVarDump() {
		$vars = [ 'foo-variable' => 42 ];
		$blob = FormatJson::encode( $vars );
		$blobStore = $this->createMock( BlobStore::class );
		$blobStore->expects( $this->once() )->method( 'getBlob' )->willReturn( $blob );

		$row = (object)[
			'afl_var_dump' => $blob,
			'afl_ip' => '',
		];
		$varBlobStore = $this->getStore( null, $blobStore );
		$loadedVars = $varBlobStore->loadVarDump( $row )->getVars();
		$this->assertArrayHasKey( 'foo-variable', $loadedVars );
		$this->assertSame( 42, $loadedVars['foo-variable']->toNative() );
	}

	/**
	 * @dataProvider provideLoadVarDumpVarTransformation
	 */
	public function testLoadVarDumpVarTransformation( $data, $expected ) {
		$vars = [
			'user_unnamed_ip' => $data[ 'user_unnamed_ip' ]
		];

		$manager = $this->createMock( VariablesManager::class );
		$manager->method( 'getVar' )->willReturn( AFPData::newFromPHPVar( $data['user_unnamed_ip'] ) );
		$blob = FormatJson::encode( $vars );
		$blobStore = $this->createMock( BlobStore::class );
		$blobStore->expects( $this->once() )->method( 'getBlob' )->willReturn( $blob );
		$row = (object)[
			'afl_var_dump' => $blob,
			'afl_ip' => $data[ 'afl_ip' ],
		];
		$varBlobStore = new VariablesBlobStore(
			$manager,
			$this->createMock( BlobStoreFactory::class ),
			$blobStore,
			null
		);
		$loadedVars = $varBlobStore->loadVarDump( $row )->getVars();
		$this->assertArrayHasKey( 'user_unnamed_ip', $loadedVars );
		$this->assertSame( $expected, $loadedVars[ 'user_unnamed_ip' ]->toNative() );
	}

	/**
	 * Data provider for testLoadVarDumpVarTransformation
	 *
	 * @return array
	 */
	public static function provideLoadVarDumpVarTransformation() {
		return [
			'ip visible, ip available' => [
				[
					'user_unnamed_ip' => true,
					'afl_ip' => '1.2.3.4',
				],
				'1.2.3.4'
			],
			'ip visible, ip cleared' => [
				[
					'user_unnamed_ip' => true,
					'afl_ip' => '',
				],
				''
			],
			'ip not visible, ip available' => [
				[
					'user_unnamed_ip' => null,
					'afl_ip' => '1.2.3.4',
				],
				null
			]
		];
	}

	public function testLoadVarDump_fail() {
		$blobStore = $this->createMock( BlobStore::class );
		$blobStore->expects( $this->once() )->method( 'getBlob' )->willThrowException( new BlobAccessException );
		$varBlobStore = $this->getStore( null, $blobStore );
		$row = (object)[
			'afl_var_dump' => '',
			'afl_ip' => '',
		];
		$this->assertCount( 0, $varBlobStore->loadVarDump( $row )->getVars() );

		$row = (object)[];
		$this->expectException( InvalidArgumentException::class );
		$varBlobStore->loadVarDump( $row )->getVars();
	}

	private function getBlobStore(): BlobStore {
		return new class implements BlobStore {
			private $blobs;

			private function getKey( string $data ) {
				return md5( $data );
			}

			public function getBlob( $blobAddress, $queryFlags = 0 ) {
				return $this->blobs[$blobAddress];
			}

			public function getBlobBatch( $blobAddresses, $queryFlags = 0 ) {
			}

			public function storeBlob( $data, $hints = [] ) {
				$key = $this->getKey( $data );
				$this->blobs[$key] = $data;
				return $key;
			}

			public function isReadOnly() {
			}
		};
	}

	/**
	 * @dataProvider provideVariables
	 */
	public function testRoundTrip( array $toStore, ?array $expected = null ) {
		$blobStore = $this->getBlobStore();
		$blobStoreFactory = $this->createMock( BlobStoreFactory::class );
		$blobStoreFactory->method( 'newBlobStore' )->willReturn( $blobStore );
		$varBlobStore = $this->getStore( $blobStoreFactory, $blobStore );

		$storeID = $varBlobStore->storeVarDump( VariableHolder::newFromArray( $toStore ) );
		$this->assertIsString( $storeID );
		$row = (object)[
			'afl_var_dump' => $storeID,
			'afl_ip' => '',
		];
		$loadedVars = $varBlobStore->loadVarDump( $row )->getVars();
		$nativeLoadedVars = array_map( static function ( AFPData $el ) {
			return $el->toNative();
		}, $loadedVars );
		$this->assertSame( $expected ?? $toStore, $nativeLoadedVars );
	}

	/**
	 * Data provider for testVarDump
	 *
	 * @return array
	 */
	public static function provideVariables() {
		return [
			'Only basic variables' => [
				[
					'action' => 'edit',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text'
				]
			],
			'Normal case' => [
				[
					'action' => 'edit',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'user_editcount' => 15,
					'added_lines' => [ 'Foo', '', 'Bar' ]
				]
			],
			'Deprecated variables' => [
				[
					'action' => 'edit',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'article_articleid' => 11745,
					'article_first_contributor' => 'Good guy'
				],
				[
					'action' => 'edit',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'page_id' => 11745,
					'page_first_contributor' => 'Good guy'
				]
			],
			'Move action' => [
				[
					'action' => 'move',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'all_links' => [ 'https://en.wikipedia.org' ],
					'moved_to_id' => 156,
					'moved_to_prefixedtitle' => 'MediaWiki:Foobar.js',
					'new_content_model' => CONTENT_MODEL_JAVASCRIPT
				]
			],
			'Delete action' => [
				[
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'timestamp' => 1546000295,
					'action' => 'delete',
					'page_namespace' => 114
				]
			],
			'Disabled vars' => [
				[
					'action' => 'edit',
					'old_wikitext' => 'Old text',
					'new_wikitext' => 'New text',
					'old_html' => 'Foo <small>bar</small> <s>lol</s>.',
					'old_text' => 'Foobar'
				]
			],
			'Account creation' => [
				[
					'action' => 'createaccount',
					'accountname' => 'XXX'
				]
			],
			'User IP' => [
				[
					'user_unnamed_ip' => '1.2.3.4'
				],
				[
					'user_unnamed_ip' => true
				]
			],
		];
	}
}
