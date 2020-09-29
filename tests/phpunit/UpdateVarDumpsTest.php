<?php

use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 * @coversDefaultClass UpdateVarDumps
 * @property TestingAccessWrapper|UpdateVarDumps $maintenance
 *
 * NOTE: This test is likely going to break once we remove the BC code after T213006 is done.
 * Since maintaining the test would be expensive, and the script was single-use anyway, this
 * test can be just removed (and the script marked as codeCoverageIgnore) as soon as it starts breaking.
 */
class UpdateVarDumpsTest extends MaintenanceBaseTestCase {
	private const TIMESTAMP = '20000102030405';
	private static $aflRow = [
		// 'afl_id'
		'afl_filter' => 1,
		'afl_global' => 0,
		'afl_filter_id' => 1,
		'afl_user' => 1,
		'afl_user_text' => 'Foo',
		'afl_ip' => '127.0.0.1',
		'afl_action' => 'edit',
		'afl_actions' => '',
		// 'afl_var_dump'
		// 'afl_timestamp' added in __construct
		'afl_namespace' => 1,
		'afl_title' => 'Foobar',
		'afl_wiki' => null,
		'afl_deleted' => 0,
		'afl_patrolled_by' => 1,
		'afl_rev_id' => 123
	];

	private const TEXT_ROW = [
		// 'old_id'
		'old_flags' => ''
		// 'old_text'
	];

	private const VARS = [
		'action' => 'edit',
		'page_id' => 12,
		'user_blocked' => true,
		'accountname' => null,
		'user_groups' => [ 'x', 'y' ]
	];

	/**
	 * A serialized AbuseFilterVariableHolder object holding self::VARS. Don't try serializing
	 * the object in tests, because that's going to break too easily.
	 */
	private const SERIALIZED_VH = 'O:25:"AbuseFilterVariableHolder":3:{s:5:"mVars";a:5:{s:6:"action";O:7:"AFPData":' .
		'2:{s:4:"type";s:6:"string";s:4:"data";s:4:"edit";}s:7:"page_id";O:7:"AFPData":2:{s:4:"type";s:3:"int";s:4:' .
		'"data";i:12;}s:12:"user_blocked";O:7:"AFPData":2:{s:4:"type";s:4:"bool";s:4:"data";b:1;}s:11:"accountname";' .
		'O:7:"AFPData":2:{s:4:"type";s:4:"null";s:4:"data";N;}s:11:"user_groups";O:7:"AFPData":2:{s:4:"type";s:5:' .
		'"array";s:4:"data";a:2:{i:0;O:7:"AFPData":2:{s:4:"type";s:6:"string";s:4:"data";s:1:"x";}i:1;O:7:"AFPData"' .
		':2:{s:4:"type";s:6:"string";s:4:"data";s:1:"y";}}}}s:9:"forFilter";b:0;s:12:"mVarsVersion";i:2;}';

	/**
	 * @inheritDoc
	 */
	protected $tablesUsed = [ 'abuse_filter_log', 'text' ];

	/**
	 * @inheritDoc
	 */
	public function __construct( $name = null, array $data = [], $dataName = '' ) {
		parent::__construct( $name, $data, $dataName );
		self::$aflRow['afl_timestamp'] = wfGetDB( DB_REPLICA )->timestamp( self::TIMESTAMP );
	}

	/**
	 * @inheritDoc
	 */
	public function setUp(): void {
		parent::setUp();
		$this->maintenance->dbr = $this->maintenance->dbw = $this->db;
		// This isn't really necessary
		$this->maintenance->allRowsCount = 50;
	}

	/**
	 * @inheritDoc
	 */
	public function getMaintenanceClass() {
		return UpdateVarDumps::class;
	}

	/**
	 * Shorthand to select all rows on a table (either abuse_filter_log or text), ordering
	 * by pkey ASC
	 * @param string $table
	 * @return IResultWrapper
	 */
	private function selectAllAscending( string $table ) : IResultWrapper {
		$key = $table === 'abuse_filter_log' ? 'afl_id' : 'old_id';
		return $this->db->select(
			$table,
			'*',
			'',
			wfGetCaller(),
			[ 'ORDER_BY' => "$key ASC" ]
		);
	}

	/**
	 * @covers ::doDBUpdates
	 */
	public function testOnEmptyDB() {
		$this->expectOutputRegex( '/the abuse_filter_log table is empty/' );
		$this->maintenance->execute();
	}

	/**
	 * @covers ::fixMissingDumps
	 * @covers ::doFixMissingDumps
	 */
	public function testFixMissingDumps() {
		$expected = $this->insertMissingDumps();
		$this->maintenance->fixMissingDumps();
		$rows = $this->selectAllAscending( 'abuse_filter_log' );
		$actual = [];
		foreach ( $rows as $row ) {
			$actual[] = [ 'afl_id' => (int)$row->afl_id, 'afl_var_dump' => $row->afl_var_dump ];
		}
		$this->assertSame( $expected, $actual );
	}

	/**
	 * @return array Expected content of abuse_filter_log after the cleanup
	 */
	private function insertMissingDumps() : array {
		$insertRows = [
			'Wrong duplicate 1' => [ 'afl_id' => 1, 'afl_var_dump' => '' ] + self::$aflRow,
			'Good duplicate 1' => [ 'afl_id' => 2, 'afl_var_dump' => 'stored-text:12345' ] + self::$aflRow,

			'Wrong duplicate 2' => [ 'afl_id' => 3, 'afl_var_dump' => '' ] + self::$aflRow,
			'Good duplicate 2' => [ 'afl_id' => 4, 'afl_var_dump' => 'stored-text:12345' ] + self::$aflRow,

			'Wrong duplicate, 3' => [ 'afl_id' => 5, 'afl_var_dump' => '' ] + self::$aflRow,
			'Extraneous row' => [ 'afl_id' => 6, 'afl_var_dump' => 'stored-text:444' ] + self::$aflRow,
			'Good duplicate 3' => [ 'afl_id' => 7, 'afl_var_dump' => 'stored-text:12345' ] + self::$aflRow,
		];
		$this->db->insert( 'abuse_filter_log', array_values( $insertRows ), __METHOD__ );
		$expected = [
			[ 'afl_id' => 2, 'afl_var_dump' => 'stored-text:12345' ],
			[ 'afl_id' => 4, 'afl_var_dump' => 'stored-text:12345' ],
			[ 'afl_id' => 6, 'afl_var_dump' => 'stored-text:444' ],
			[ 'afl_id' => 7, 'afl_var_dump' => 'stored-text:12345' ],
		];
		return $expected;
	}

	/**
	 * @covers ::fixMissingDumps
	 * @covers ::doFixMissingDumps
	 */
	public function testFixMissingDumpsToRebuild() {
		$expected = $this->insertMissingDumpsToRebuild();
		$this->maintenance->fixMissingDumps();
		$aflRows = $this->selectAllAscending( 'abuse_filter_log' );
		$aflActual = [];
		foreach ( $aflRows as $aflRow ) {
			$aflActual[] = [ 'afl_id' => (int)$aflRow->afl_id, 'afl_var_dump' => $aflRow->afl_var_dump ];
		}
		$this->assertSame( $expected['abuse_filter_log'], $aflActual );

		$textRows = $this->selectAllAscending( 'text' );
		$textActual = [];
		foreach ( $textRows as $textRow ) {
			$textActual[] = [ 'old_id' => (int)$textRow->old_id, 'old_text' => $textRow->old_text ];
		}
		$this->assertSame( $expected['text'], $textActual );
	}

	/**
	 * @return array Expected content of abuse_filter_log after the cleanup
	 */
	private function insertMissingDumpsToRebuild() : array {
		$baseVars = [
			'timestamp' => wfTimestamp( TS_UNIX, self::TIMESTAMP ),
		];

		$insertRows = [
			'Edit' => [ 'afl_id' => 1, 'afl_var_dump' => '' ] + self::$aflRow,
			// afl_action added below in order to keep the same order of rows
			'Createaccount' => [ 'afl_id' => 2, 'afl_var_dump' => '' ] + self::$aflRow,
			'Move' => [ 'afl_id' => 3, 'afl_var_dump' => '' ] + self::$aflRow,
		];
		$insertRows['Createaccount']['afl_action'] = 'createaccount';
		$insertRows['Move']['afl_action'] = 'move';

		$this->db->insert( 'abuse_filter_log', array_values( $insertRows ), __METHOD__ );

		$title = Title::makeTitle( self::$aflRow['afl_namespace'], self::$aflRow['afl_title'] );
		$expected = [
			'abuse_filter_log' => [
				[ 'afl_id' => 1, 'afl_var_dump' => 'tt:1' ],
				[ 'afl_id' => 2, 'afl_var_dump' => 'tt:2' ],
				[ 'afl_id' => 3, 'afl_var_dump' => 'tt:3' ],
			],
			'text' => [
				[
					'old_id' => 1,
					'old_text' => FormatJson::encode( $baseVars + [
						'action' => 'edit',
						'user_name' => self::$aflRow['afl_user_text'],
						'page_title' => self::$aflRow['afl_title'],
						'page_prefixedtitle' => $title->getPrefixedText()
					] )
				],
				[
					'old_id' => 2,
					'old_text' => FormatJson::encode( $baseVars + [
						'action' => 'createaccount',
							'accountname' => self::$aflRow['afl_user_text']
					] )
				],
				[
					'old_id' => 3,
					'old_text' => FormatJson::encode( $baseVars + [
						'action' => 'move',
						'user_name' => self::$aflRow['afl_user_text'],
						'moved_from_title' => self::$aflRow['afl_title'],
						'moved_from_prefixedtitle' => $title->getPrefixedText()
					] )
				],
			]
		];
		return $expected;
	}

	/**
	 * @covers ::moveToText
	 * @covers ::doMoveToText
	 */
	public function testMoveToText() {
		$expected = $this->insertMoveToText();
		$this->maintenance->moveToText();

		$aflRows = $this->selectAllAscending( 'abuse_filter_log' );
		$aflActual = [];
		foreach ( $aflRows as $row ) {
			$aflActual[] = [ 'afl_id' => (int)$row->afl_id, 'afl_var_dump' => $row->afl_var_dump ];
		}
		$this->assertSame( $expected['abuse_filter_log'], $aflActual );

		$textRows = $this->selectAllAscending( 'text' );
		$textActual = [];
		foreach ( $textRows as $row ) {
			$textActual[] = [ 'old_id' => (int)$row->old_id, 'old_text' => $row->old_text ];
		}
		$this->assertSame( $expected['text'], $textActual );
	}

	/**
	 * @return array Expected contents of abuse_filter_log and text tables
	 */
	private function insertMoveToText() : array {
		$serializedArr = serialize( self::VARS );

		$truncatedArr = substr( $serializedArr, 0, -5 );
		$expectedTruncated = FormatJson::encode( array_diff_key( self::VARS, [ 'user_groups' => 1 ] ) );

		$insertRows = [
			'Truncated arr' => [ 'afl_id' => 1, 'afl_var_dump' => $truncatedArr ] + self::$aflRow,
			'Serialized array' => [ 'afl_id' => 2, 'afl_var_dump' => $serializedArr ] + self::$aflRow,
			'Serialized VariableHolder' =>
				[ 'afl_id' => 3, 'afl_var_dump' => self::SERIALIZED_VH ] + self::$aflRow,
		];
		$this->db->insert( 'abuse_filter_log', array_values( $insertRows ), __METHOD__ );
		$expected = [
			'abuse_filter_log' => [
				[ 'afl_id' => 1, 'afl_var_dump' => 'tt:1' ],
				[ 'afl_id' => 2, 'afl_var_dump' => 'tt:2' ],
				[ 'afl_id' => 3, 'afl_var_dump' => 'tt:3' ],
			],
			'text' => [
				[ 'old_id' => 1, 'old_text' => $expectedTruncated ],
				[ 'old_id' => 2, 'old_text' => FormatJson::encode( self::VARS ) ],
				[ 'old_id' => 3, 'old_text' => FormatJson::encode( self::VARS ) ],
			]
		];
		return $expected;
	}

	/**
	 * @return TestingAccessWrapper|UpdateVarDumps
	 */
	private function getMaintenanceWithoutExit() {
		// We first need to mock UpdateVarDumps, because fatalError kills PHP.
		$maint = $this->getMockBuilder( UpdateVarDumps::class )
			->setMethods( [ 'fatalError' ] )
			->getMock();
		$maint->method( 'fatalError' )->willThrowException( new LogicException() );
		// Then use an access wrapper to call private methods.
		$wrapper = TestingAccessWrapper::newFromObject( $maint );
		$wrapper->allRowsCount = 50;
		$wrapper->dbr = $wrapper->dbw = $this->db;
		return $wrapper;
	}

	/**
	 * @param array $row
	 * @dataProvider provideMoveToTextUnexpectedTypes
	 * @covers ::doMoveToText
	 */
	public function testMoveToTextUnexpectedTypes( array $row ) {
		$this->db->insert( 'abuse_filter_log', $row, __METHOD__ );
		$maint = $this->getMaintenanceWithoutExit();
		$this->expectException( LogicException::class );
		$maint->moveToText();
	}

	/**
	 * @return array
	 */
	public function provideMoveToTextUnexpectedTypes() {
		return [
			'Truncated obj' => [
				[ 'afl_id' => 1, 'afl_var_dump' => substr( self::SERIALIZED_VH, 0, -5 ) ] + self::$aflRow
			],
			'Wrong type' => [
				[ 'afl_id' => 3, 'afl_var_dump' => serialize( 'foo bar baz' ) ] + self::$aflRow
			]
		];
	}

	/**
	 * @param string $str
	 * @param array $expected
	 * @covers UpdateVarDumps::restoreTruncatedDump
	 * @dataProvider provideTruncatedDump
	 */
	public function testRestoreTruncatedDump( string $str, array $expected ) {
		$this->assertSame( $expected, $this->maintenance->restoreTruncatedDump( $str ) );
	}

	/**
	 * @return array
	 */
	public function provideTruncatedDump() {
		$serialized = serialize( self::VARS );
		$varsWithoutKeys = function ( ...$keys ) {
			return array_diff_key( self::VARS, array_fill_keys( $keys, 1 ) );
		};
		return [
			[ substr( $serialized, 0, -1 ), $varsWithoutKeys( 'user_groups' ) ],
			[ substr( $serialized, 0, -7 ), $varsWithoutKeys( 'user_groups' ) ],
			[ substr( $serialized, 0, -16 ), $varsWithoutKeys( 'user_groups' ) ],
			[ substr( $serialized, 0, -32 ), $varsWithoutKeys( 'user_groups' ) ],
			[ substr( $serialized, 0, -46 ), $varsWithoutKeys( 'user_groups' ) ],
			[ substr( $serialized, 0, -56 ), $varsWithoutKeys( 'user_groups', 'accountname' ) ],
			[
				substr( $serialized, 0, -72 ),
				$varsWithoutKeys( 'user_groups', 'accountname', 'user_blocked' )
			],
			[
				substr( $serialized, 0, -96 ),
				$varsWithoutKeys( 'user_groups', 'accountname', 'user_blocked', 'page_id' )
			],
			[ substr( $serialized, 0, 17 ), [] ],
			[ substr( $serialized, 0, 10 ), [] ],
			[ substr( $serialized, 0, 5 ), [] ],
		];
	}

	/**
	 * @covers ::updateText
	 * @covers ::doUpdateText
	 */
	public function testUpdateText() {
		$expected = $this->insertUpdateText();
		$this->maintenance->updateText();
		$rows = $this->selectAllAscending( 'text' );
		$actual = [];
		foreach ( $rows as $row ) {
			$actual[] = [
				'old_id' => (int)$row->old_id,
				'old_flags' => $row->old_flags,
				'old_text' => $row->old_text
			];
		}
		$this->assertSame( $expected, $actual );
	}

	/**
	 * @return array Expected content of the text table
	 */
	private function insertUpdateText() {
		$serializedArr = serialize( self::VARS );
		$jsonArr = FormatJson::encode( self::VARS );

		$textRows = [
			'Serialized VH' => [ 'old_text' => self::SERIALIZED_VH ] + self::TEXT_ROW,
			'Serialized array' =>
				[ 'old_text' => $serializedArr, 'old_flags' => 'nativeDataArray' ] + self::TEXT_ROW,
			'JSON array' => [ 'old_text' => $jsonArr, 'old_flags' => 'utf-8' ] + self::TEXT_ROW,
		];
		$this->db->insert( 'text', array_values( $textRows ), __METHOD__ );

		$pointerRows = [
			[ 'afl_var_dump' => 'stored-text:1' ] + self::$aflRow,
			[ 'afl_var_dump' => 'stored-text:2' ] + self::$aflRow,
			[ 'afl_var_dump' => 'stored-text:3' ] + self::$aflRow,
		];
		$this->db->insert( 'abuse_filter_log', $pointerRows, __METHOD__ );

		return [
			[ 'old_id' => 1, 'old_flags' => 'utf-8', 'old_text' => $jsonArr ],
			[ 'old_id' => 2, 'old_flags' => 'utf-8', 'old_text' => $jsonArr ],
			[ 'old_id' => 3, 'old_flags' => 'utf-8', 'old_text' => $jsonArr ],
		];
	}

	/**
	 * @covers ::doUpdateText
	 */
	public function testUpdateTextWrongFlags() {
		$jsonArr = FormatJson::encode( self::VARS );
		$textRow = [ 'old_id' => 1, 'old_flags' => 'nativeDataArray,utf-8', 'old_text' => $jsonArr ];
		$this->db->insert( 'text', $textRow, __METHOD__ );

		$pointerRow = [ 'afl_var_dump' => 'stored-text:1' ] + self::$aflRow;
		$this->db->insert( 'abuse_filter_log', $pointerRow, __METHOD__ );

		$maint = $this->getMaintenanceWithoutExit();
		$this->expectException( LogicException::class );
		$maint->updateText();
	}

	/**
	 * @covers ::updateAflVarDump
	 */
	public function testUpdateAflVarDump() {
		$this->insertAflVarDump();
		$this->maintenance->updateAflVarDump();
		$vals = $this->db->selectFieldValues( 'abuse_filter_log', 'afl_var_dump' );

		$this->assertSame( [ 'tt:123' ], array_unique( $vals ) );
	}

	private function insertAflVarDump() {
		$rows = [
			'Old prefix' => [ 'afl_var_dump' => 'stored-text:123' ] + self::$aflRow,
			'New prefix' => [ 'afl_var_dump' => 'tt:123' ] + self::$aflRow
		];
		$this->db->insert( 'abuse_filter_log', array_values( $rows ), __METHOD__ );
	}

	/**
	 * @param array $old
	 * @param array $expected
	 * @covers UpdateVarDumps::updateVariables
	 * @dataProvider provideUpdateVariables
	 */
	public function testUpdateVariables( array $old, array $expected ) {
		$this->assertSame( $expected, $this->maintenance->updateVariables( $old ) );
	}

	/**
	 * @return array
	 */
	public function provideUpdateVariables() {
		return [
			'Fine' => [ self::VARS, self::VARS ],
			'Meta-variable' => [ [ 'action' => 'edit', 'context' => 'foo' ], [ 'action' => 'edit' ] ],
			'Uppercase' => [ [ 'USER_GROUPS' => [ 'bot' ] ], [ 'user_groups' => [ 'bot' ] ] ],
			'Deprecated' => [
				[ 'article_text' => 'foo', 'moved_to_prefixedtext' => 'bar' ],
				[ 'page_title' => 'foo', 'moved_to_prefixedtitle' => 'bar' ]
			],
			'Mixed' => [
				[ 'ARTICLE_ARTICLEID' => 1, 'logged_local_ids' => [ 1, 2, 3 ], 'OLD_HTML' => '' ],
				[ 'page_id' => 1, 'old_html' => '' ]
			]
		];
	}
}
