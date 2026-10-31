<?php
/**
 * An error Oracle raises while the rows of a statement are fetched (ORA-01722 on
 * a row that does not convert, ORA-01555, a lost connection) is an error, not a
 * short result: in the driver's arrayQuery() (pull request #3), in the cluster
 * backends' _query() and _selectOne(), in the cluster gateways, and in the LOB
 * reads of the database cluster.
 *
 *  FE-01 — arrayQuery(), by row and by column: false, the Oracle error, no rows, the statement freed
 *  FE-02 — oci_fetch_all() false without an Oracle error: false, error -1
 *  FE-03 — exceptions: eZDBException with the Oracle error number
 *  FE-04 — the query cache keeps no partial result; the next call runs the statement again, then a hit
 *  FE-05 — rows that fetch without error are returned as before (offset keys, one row, empty result, cached)
 *  FE-06 — in a transaction a fetch error, and a statement that does not run, invalidate it (like query() and
 *          the MySQLi, PostgreSQL and SQLite drivers); outside one, nothing is rolled back
 *  FE-07 — a connection lost while the rows came: a read is run again once on a new connection, never twice,
 *          never inside a transaction, and not with RetryReads=disabled
 *  FE-08 — cluster backends (DFS and DB): _query() returns false on a fetch error, _selectOne() reports it
 *          instead of "no such row", and a failed execute no longer ends in a TypeError of a freed statement
 *  FE-09 — cluster gateways: a fetch error of the metadata is a RuntimeException (500), not "not found" (404)
 *  FE-10 — LOB reads (DB cluster): a read that stops before the end is reported; a last chunk "0" is data
 *
 * No database is needed: the oci8 functions are the stand-in of eZOracleOci8StandIn.php, so the tests run with a
 * PHP that has no oci8 loaded, and are skipped where it is. Run from an Exponential root:
 *   php -n -d extension=dom -d extension=mbstring -d extension=xmlwriter vendor/bin/phpunit --no-configuration \
 *       --bootstrap vendor/autoload.php extension/ezoracle/tests/eZOracleFetchErrorTest.php
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/eZOracleOci8StandIn.php';
require_once dirname( __DIR__ ) . '/ezdb/dbms-drivers/ezoracledb.php';
require_once dirname( __DIR__ ) . '/clusterfilehandlers/dfsbackends/oracle.php';
require_once dirname( __DIR__ ) . '/clusterfilehandlers/dbbackends/oracle.php';

/** the driver, with reconnect() and rollback() that only count (no connection, no settings) */
class eZOracleFetchErrorTestDriver extends eZOracleDB
{
    public $reconnects = 0;
    public $reconnectWorks = true;
    public $rollbacks = 0;

    function reconnect()
    {
        ++$this->reconnects;
        return $this->reconnectWorks;
    }

    function rollback()
    {
        ++$this->rollbacks;
        $this->TransactionCounter = 0;
        return true;
    }
}

/** a LOB that hands out $data in chunks and fails after $failAfter bytes (null: never) */
class eZOracleFetchErrorTestLob
{
    private $data;
    private $position = 0;
    private $failAfter;
    public $freed = false;

    public function __construct( $data, $failAfter = null )
    {
        $this->data = $data;
        $this->failAfter = $failAfter;
    }

    public function read( $length )
    {
        if ( $this->failAfter !== null && $this->position >= $this->failAfter )
        {
            return false;
        }
        if ( $this->position >= strlen( $this->data ) )
        {
            return false;
        }
        $chunk = (string)substr( $this->data, $this->position, $length );
        $this->position += strlen( $chunk );
        return $chunk;
    }

    public function eof()
    {
        return $this->position >= strlen( $this->data );
    }

    public function load()
    {
        return $this->data;
    }

    public function free()
    {
        $this->freed = true;
        return true;
    }
}

/** the database cluster backend with the LOB of the test */
class eZOracleFetchErrorTestDBBackend extends eZDBFileHandlerOracleBackend
{
    public $testLob;

    function _fetchLob( $filePath, $fname = '' )
    {
        return array( 'filesize' => 0, 'lob' => $this->testLob );
    }
}

class eZOracleFetchErrorTest extends TestCase
{
    private $stateDir;

    protected function setUp(): void
    {
        if ( !eZOracleOci8StandIn::available() )
        {
            $this->markTestSkipped( 'oci8 is loaded: the stand-in for its functions cannot be defined (run with a PHP without oci8)' );
        }
        eZDebug::setLogFileEnabled( false );
        eZOracleOci8StandIn::reset();
        $this->stateDir = getcwd() . '/var/tmp/ezoracle-fetch-error-test-' . getmypid() . '-' . mt_rand();
        mkdir( $this->stateDir, 0770, true );
        eZDBQueryCache::setStateDir( $this->stateDir );
        eZDBQueryCache::resetRequest();
        eZDBQueryCache::setSettings( array( 'mode' => 'off', 'maxAge' => 300, 'maxRows' => 5000, 'exclude' => array() ) );
    }

    protected function tearDown(): void
    {
        if ( $this->stateDir === null )
        {
            return;
        }
        eZDBQueryCache::resetRequest();
        eZDBQueryCache::setStateDir( null );
        eZDBQueryCache::setSettings( null );
        $this->removeDir( $this->stateDir );
    }

    private function removeDir( $dir )
    {
        foreach ( array_merge( glob( $dir . '/*' ) ?: array(), glob( $dir . '/.[!.]*' ) ?: array() ) as $f )
        {
            is_dir( $f ) ? $this->removeDir( $f ) : unlink( $f );
        }
        rmdir( $dir );
    }

    private function useQueryCache()
    {
        eZDBQueryCache::resetRequest();
        eZDBQueryCache::setSettings( array( 'mode' => 'request', 'maxAge' => 300, 'maxRows' => 5000, 'exclude' => array() ) );
    }

    /** a connected driver on the stand-in, standard error handling, no transaction */
    private function driver( $exceptions = false )
    {
        $db = ( new ReflectionClass( 'eZOracleFetchErrorTestDriver' ) )->newInstanceWithoutConstructor();
        $db->DBConnection = 'stand-in';
        $db->IsConnected = true;
        $db->DB = 'xe';
        $db->Server = 'localhost';
        $db->Mode = OCI_COMMIT_ON_SUCCESS;
        $db->TransactionCounter = 0;
        $db->TransactionIsValid = false;
        $db->BindVariableArray = array();
        $db->OutputSQL = false;
        $db->QueryAnalysisOutput = false;
        $db->setErrorHandling( $exceptions ? eZDB::ERROR_HANDLING_EXCEPTIONS : eZDB::ERROR_HANDLING_STANDARD );
        return $db;
    }

    /** 600 rows; the fetch fails after 403 of them, as oci8 3.4 did when row 500 failed to convert */
    private function failingScenario( array $extra = array() )
    {
        return $extra + array( 'rows' => eZOracleOci8StandIn::numberRows( 600 ), 'failAfter' => 403 );
    }

    const SQL = 'SELECT n FROM ezcontentobject';

    /** FE-01 */
    public function testFetchErrorByRowGivesFalseAndTheOracleError()
    {
        eZOracleOci8StandIn::reset( array( $this->failingScenario() ) );
        $db = $this->driver();
        $this->assertFalse( $db->arrayQuery( self::SQL ) );
        $this->assertSame( 1722, $db->ErrorNumber );
        $this->assertStringContainsString( 'ORA-01722', $db->ErrorMessage );
        $this->assertTrue( eZOracleOci8StandIn::allFreed(), 'the statement is freed' );
        $this->assertSame( 0, $db->rollbacks, 'no transaction, nothing rolled back' );
    }

    /** FE-01 */
    public function testFetchErrorByColumnGivesFalseAndTheOracleError()
    {
        foreach ( array( 'n', 0 ) as $column )
        {
            eZOracleOci8StandIn::reset( array( $this->failingScenario() ) );
            $db = $this->driver();
            $this->assertFalse( $db->arrayQuery( self::SQL, array( 'column' => $column ) ), "column $column" );
            $this->assertSame( 1722, $db->ErrorNumber );
            $this->assertTrue( eZOracleOci8StandIn::allFreed() );
        }
    }

    /** FE-01: with a limit that stops before the failing row, the rows are whole and there is no error */
    public function testLimitBeforeTheFailingRowIsNoError()
    {
        eZOracleOci8StandIn::reset( array( $this->failingScenario() ) );
        $db = $this->driver();
        $db->ServerMajorVersion = 11; // oci8 skips and cuts the rows, as before 12.1
        $rows = $db->arrayQuery( self::SQL, array( 'offset' => 10, 'limit' => 5 ) );
        $this->assertSame( array( 10, 11, 12, 13, 14 ), array_keys( $rows ) );
        $this->assertSame( array( 'n' => 11 ), $rows[10] );
        $this->assertFalse( $db->ErrorNumber );
    }

    /** FE-02 */
    public function testFetchAllFalseWithoutAnOracleErrorIsAnErrorToo()
    {
        eZOracleOci8StandIn::reset( array( array( 'rows' => eZOracleOci8StandIn::numberRows( 3 ), 'fetchAllFalse' => true ) ) );
        $db = $this->driver();
        $this->assertFalse( $db->arrayQuery( self::SQL ) );
        $this->assertSame( -1, $db->ErrorNumber );
        $this->assertSame( 'Fetching the rows failed', $db->ErrorMessage );
        $this->assertTrue( eZOracleOci8StandIn::allFreed() );
    }

    /** FE-03 */
    public function testFetchErrorThrowsInExceptionMode()
    {
        foreach ( array( false, array( 'column' => 'n' ) ) as $params )
        {
            eZOracleOci8StandIn::reset( array( $this->failingScenario() ) );
            $db = $this->driver( true );
            try
            {
                $db->arrayQuery( self::SQL, $params );
                $this->fail( 'an eZDBException was expected' );
            }
            catch ( eZDBException $e )
            {
                $this->assertSame( 1722, $e->getCode() );
                $this->assertStringContainsString( 'ORA-01722', $e->getMessage() );
            }
            $this->assertTrue( eZOracleOci8StandIn::allFreed() );
        }
    }

    /** FE-03 */
    public function testFetchAllFalseThrowsInExceptionMode()
    {
        eZOracleOci8StandIn::reset( array( array( 'rows' => eZOracleOci8StandIn::numberRows( 3 ), 'fetchAllFalse' => true ) ) );
        $this->expectException( eZDBException::class );
        $this->driver( true )->arrayQuery( self::SQL );
    }

    /** FE-04 */
    public function testQueryCacheKeepsNoPartialResult()
    {
        $this->useQueryCache();
        eZOracleOci8StandIn::reset( array( $this->failingScenario(),
                                           array( 'rows' => eZOracleOci8StandIn::numberRows( 600 ) ) ) );
        $db = $this->driver();
        $this->assertFalse( $db->arrayQuery( self::SQL ) );
        $this->assertSame( 0, eZDBQueryCache::$stats['stores'], 'nothing stored after the error' );

        $rows = $db->arrayQuery( self::SQL );
        $this->assertCount( 600, $rows, 'run again, not answered from the cache' );
        $this->assertSame( 2, eZOracleOci8StandIn::$executes );
        $this->assertSame( 0, eZDBQueryCache::$stats['hits'] );
        $this->assertSame( 1, eZDBQueryCache::$stats['stores'] );

        $this->assertSame( $rows, $db->arrayQuery( self::SQL ), 'the whole result is kept' );
        $this->assertSame( 2, eZOracleOci8StandIn::$executes );
        $this->assertSame( 1, eZDBQueryCache::$stats['hits'] );
    }

    /** FE-04: by column, and in exception mode */
    public function testQueryCacheKeepsNoPartialColumnResultInExceptionMode()
    {
        $this->useQueryCache();
        eZOracleOci8StandIn::reset( array( $this->failingScenario(), array( 'rows' => eZOracleOci8StandIn::numberRows( 4 ) ) ) );
        $db = $this->driver( true );
        try
        {
            $db->arrayQuery( self::SQL, array( 'column' => 'n' ) );
            $this->fail( 'an eZDBException was expected' );
        }
        catch ( eZDBException $e )
        {
        }
        $this->assertSame( 0, eZDBQueryCache::$stats['stores'] );
        $this->assertSame( array( 1, 2, 3, 4 ), $db->arrayQuery( self::SQL, array( 'column' => 'n' ) ) );
        $this->assertSame( 0, eZDBQueryCache::$stats['hits'] );
    }

    /** FE-05 */
    public function testRowsWithoutErrorAreReturnedAsBefore()
    {
        $this->useQueryCache();
        eZOracleOci8StandIn::reset( array( array( 'rows' => eZOracleOci8StandIn::numberRows( 600 ) ) ) );
        $db = $this->driver();
        $rows = $db->arrayQuery( self::SQL );
        $this->assertCount( 600, $rows );
        $this->assertSame( array( 'n' => 600 ), $rows[599] );
        $this->assertFalse( $db->ErrorNumber );

        eZOracleOci8StandIn::reset( array( array( 'rows' => array( array( 'N' => 7 ) ) ) ) );
        $this->assertSame( array( 7 ), $db->arrayQuery( 'SELECT n FROM ezsection', array( 'column' => 'n' ) ) );

        eZOracleOci8StandIn::reset( array( array( 'rows' => array() ) ) );
        $this->assertSame( array(), $db->arrayQuery( 'SELECT n FROM eznode_assignment' ) );
        $this->assertSame( array(), $db->arrayQuery( 'SELECT n FROM eznode_assignment' ), 'an empty result is kept as before' );
        $this->assertSame( 1, eZDBQueryCache::$stats['hits'] );
        $this->assertTrue( eZOracleOci8StandIn::allFreed() );
    }

    /** FE-06 */
    public function testFetchErrorInvalidatesTheTransaction()
    {
        eZOracleOci8StandIn::reset( array( $this->failingScenario() ) );
        $db = $this->driver( true );
        $db->TransactionCounter = 1;
        $db->TransactionIsValid = true;
        try
        {
            $db->arrayQuery( self::SQL );
            $this->fail( 'an eZDBException was expected' );
        }
        catch ( eZDBException $e )
        {
            $this->assertSame( 1722, $e->getCode(), 'the fetch error, not the rollback' );
        }
        $this->assertFalse( $db->isTransactionValid() );
        $this->assertSame( 1, $db->rollbacks );
        $this->assertSame( 0, $db->reconnects );
    }

    /** FE-06: the same for a statement that does not run, which arrayQuery() did not report to the transaction */
    public function testExecuteErrorInvalidatesTheTransaction()
    {
        eZOracleOci8StandIn::reset( array( array( 'executeError' => array( 'code' => 942, 'message' => 'ORA-00942: table or view does not exist', 'offset' => 15, 'sqltext' => '' ) ) ) );
        $db = $this->driver( true );
        $db->TransactionCounter = 1;
        $db->TransactionIsValid = true;
        try
        {
            $db->arrayQuery( self::SQL );
            $this->fail( 'an eZDBException was expected' );
        }
        catch ( eZDBException $e )
        {
            $this->assertSame( 942, $e->getCode() );
        }
        $this->assertFalse( $db->isTransactionValid() );
        $this->assertSame( 1, $db->rollbacks );
        $this->assertTrue( eZOracleOci8StandIn::allFreed() );
    }

    /** FE-06: outside a transaction a failed statement is only reported */
    public function testExecuteErrorOutsideATransaction()
    {
        eZOracleOci8StandIn::reset( array( array( 'executeError' => array( 'code' => 942, 'message' => 'ORA-00942: table or view does not exist', 'offset' => 15, 'sqltext' => '' ) ) ) );
        $db = $this->driver();
        $this->assertFalse( $db->arrayQuery( self::SQL ) );
        $this->assertSame( 942, $db->ErrorNumber );
        $this->assertSame( 0, $db->rollbacks );
    }

    private function lostConnection()
    {
        return $this->failingScenario( array( 'error' => array( 'code' => 3113, 'message' => 'ORA-03113: end-of-file on communication channel', 'offset' => 0, 'sqltext' => '' ) ) );
    }

    /** FE-07 */
    public function testLostConnectionWhileFetchingRunsTheReadAgain()
    {
        $this->useQueryCache();
        eZOracleOci8StandIn::reset( array( $this->lostConnection(), array( 'rows' => eZOracleOci8StandIn::numberRows( 600 ) ) ) );
        $db = $this->driver();
        $rows = $db->arrayQuery( self::SQL, array( 'offset' => 400, 'limit' => 5 ) );   // reaches past row 403
        $this->assertSame( array( 400, 401, 402, 403, 404 ), array_keys( $rows ) );
        $this->assertSame( array( 'n' => 405 ), $rows[404] );
        $this->assertSame( 1, $db->reconnects );
        $this->assertSame( 2, eZOracleOci8StandIn::$executes );
        $this->assertFalse( $db->ErrorNumber );
        $this->assertTrue( eZOracleOci8StandIn::allFreed() );
        $this->assertSame( 1, eZDBQueryCache::$stats['stores'], 'only the whole result' );
    }

    /** FE-07 */
    public function testLostConnectionIsRunAgainOnlyOnce()
    {
        eZOracleOci8StandIn::reset( array( $this->lostConnection() ) );
        $db = $this->driver();
        $this->assertFalse( $db->arrayQuery( self::SQL ) );
        $this->assertSame( 3113, $db->ErrorNumber );
        $this->assertSame( 1, $db->reconnects );
        $this->assertSame( 2, eZOracleOci8StandIn::$executes );

        // the next statement may be run again once more
        eZOracleOci8StandIn::reset( array( $this->lostConnection(), array( 'rows' => eZOracleOci8StandIn::numberRows( 2 ) ) ) );
        $this->assertCount( 2, $db->arrayQuery( self::SQL ) );
    }

    /** FE-07 */
    public function testLostConnectionThatCannotBeOpenedAgain()
    {
        eZOracleOci8StandIn::reset( array( $this->lostConnection() ) );
        $db = $this->driver( true );
        $db->reconnectWorks = false;
        try
        {
            $db->arrayQuery( self::SQL );
            $this->fail( 'an eZDBException was expected' );
        }
        catch ( eZDBException $e )
        {
            $this->assertSame( 3113, $e->getCode() );
        }
        $this->assertSame( 1, eZOracleOci8StandIn::$executes );
        $this->assertTrue( eZOracleOci8StandIn::allFreed() );
    }

    /** FE-07 */
    public function testLostConnectionIsNotRunAgainInATransactionOrWithoutRetryReads()
    {
        eZOracleOci8StandIn::reset( array( $this->lostConnection() ) );
        $db = $this->driver( true );
        $db->TransactionCounter = 1;
        $db->TransactionIsValid = true;
        try
        {
            $db->arrayQuery( self::SQL );
            $this->fail( 'an eZDBException was expected' );
        }
        catch ( eZDBException $e )
        {
            $this->assertSame( 3113, $e->getCode() );
        }
        $this->assertSame( 0, $db->reconnects );
        $this->assertFalse( $db->isTransactionValid() );

        eZOracleOci8StandIn::reset( array( $this->lostConnection() ) );
        $db = $this->driver();
        $db->RetryReads = false;
        $this->assertFalse( $db->arrayQuery( self::SQL ) );
        $this->assertSame( 0, $db->reconnects );
        $this->assertSame( 1, eZOracleOci8StandIn::$executes );
    }

    // ── cluster backends ───────────────────────────────────────────────

    private function dfsBackend()
    {
        $backend = ( new ReflectionClass( 'eZDFSFileHandlerOracleBackend' ) )->newInstanceWithoutConstructor();
        Closure::bind( function () { self::$dbparams = array( 'sql_output' => false ); }, null, 'eZDFSFileHandlerOracleBackend' )();
        $backend->db = 'stand-in';
        return $backend;
    }

    private function dbBackend()
    {
        $backend = ( new ReflectionClass( 'eZOracleFetchErrorTestDBBackend' ) )->newInstanceWithoutConstructor();
        $backend->dbparams = array( 'sql_output' => false, 'chunk_size' => 4 );
        $backend->db = 'stand-in';
        return $backend;
    }

    /** the last error of a cluster backend (protected in the DFS one) */
    private function errorOf( $backend )
    {
        return Closure::bind( function () { return $this->error; }, $backend, get_class( $backend ) )();
    }

    /** runs a protected method of $object */
    private function call( $object, $method, array $arguments )
    {
        return Closure::bind( function () use ( $method, $arguments ) { return $this->$method( ...$arguments ); }, $object, get_class( $object ) )();
    }

    /** FE-08 */
    public function testDfsQueryFetchErrorIsNoResult()
    {
        $rows = array( array( 'NAME' => 'a' ), array( 'NAME' => 'b' ), array( 'NAME' => 'c' ) );
        foreach ( array( eZDFSFileHandlerOracleBackend::RETURN_DATA, eZDFSFileHandlerOracleBackend::RETURN_DATA_BY_COL ) as $type )
        {
            eZOracleOci8StandIn::reset( array( array( 'rows' => $rows, 'failAfter' => 2 ) ) );
            $backend = $this->dfsBackend();
            $this->assertFalse( $this->call( $backend, '_query', array( 'SELECT name FROM ezdfsfile', 'test', true, array(), $type ) ) );
            $this->assertSame( 1722, $this->errorOf( $backend )['code'] );
            $this->assertTrue( eZOracleOci8StandIn::allFreed() );

            eZOracleOci8StandIn::reset( array( array( 'rows' => $rows ) ) );
            $result = $this->call( $backend, '_query', array( 'SELECT name FROM ezdfsfile', 'test', true, array(), $type ) );
            $this->assertSame( $type == eZDFSFileHandlerOracleBackend::RETURN_DATA ? $rows : array( 'NAME' => array( 'a', 'b', 'c' ) ), $result );
        }
    }

    /** FE-08 */
    public function testDfsSelectOneFetchErrorIsReported()
    {
        eZOracleOci8StandIn::reset( array( array( 'rows' => array( array( 'NAME' => 'a' ) ), 'failAfter' => 0 ) ) );
        $backend = $this->dfsBackend();
        $this->assertFalse( $this->call( $backend, '_selectOne', array( 'SELECT name FROM ezdfsfile', 'test', false, false, array(), OCI_ASSOC ) ) );
        $this->assertSame( 1722, $this->errorOf( $backend )['code'], 'an error, not "no such row"' );

        eZOracleOci8StandIn::reset( array( array( 'rows' => array() ) ) );
        $this->assertFalse( $this->call( $backend, '_selectOne', array( 'SELECT name FROM ezdfsfile', 'test', false, false, array(), OCI_ASSOC ) ) );
        $this->assertFalse( $this->errorOf( $backend ), 'no such row: no error' );

        eZOracleOci8StandIn::reset( array( array( 'rows' => array( array( 'NAME' => 'a' ) ) ) ) );
        $this->assertSame( array( 'name' => 'a' ), $this->call( $backend, '_selectOne', array( 'SELECT name FROM ezdfsfile', 'test', false, false, array(), OCI_ASSOC ) ) );
    }

    /** FE-08: before, oci_error() was called on the freed statement, a TypeError since PHP 8 */
    public function testDfsSelectOneExecuteErrorIsReportedWithoutTypeError()
    {
        foreach ( array( $this->dfsBackend(), $this->dbBackend() ) as $backend )
        {
            eZOracleOci8StandIn::reset( array( array( 'executeError' => array( 'code' => 942, 'message' => 'ORA-00942: table or view does not exist' ) ) ) );
            $this->assertFalse( $this->call( $backend, '_selectOne', array( 'SELECT name FROM ezdfsfile', 'test', false, false, array(), OCI_ASSOC ) ) );
            $this->assertSame( 942, $this->errorOf( $backend )['code'], get_class( $backend ) );
            $this->assertTrue( eZOracleOci8StandIn::allFreed() );
        }
    }

    /** FE-08 */
    public function testDbBackendQueryAndSelectOneFetchErrors()
    {
        $rows = array( array( 'NAME' => 'a' ), array( 'NAME' => 'b' ) );
        eZOracleOci8StandIn::reset( array( array( 'rows' => $rows, 'failAfter' => 1 ) ) );
        $backend = $this->dbBackend();
        $this->assertFalse( $this->call( $backend, '_query', array( 'SELECT name FROM ezdbfile', 'test', true, array(), eZDBFileHandlerOracleBackend::RETURN_DATA ) ) );
        $this->assertSame( 1722, $this->errorOf( $backend )['code'] );

        eZOracleOci8StandIn::reset( array( array( 'rows' => $rows ) ) );
        $this->assertSame( $rows, $this->call( $backend, '_query', array( 'SELECT name FROM ezdbfile', 'test', true, array(), eZDBFileHandlerOracleBackend::RETURN_DATA ) ) );

        eZOracleOci8StandIn::reset( array( array( 'rows' => $rows, 'failAfter' => 0 ) ) );
        $this->assertFalse( $this->call( $backend, '_selectOne', array( 'SELECT name FROM ezdbfile', 'test', false, false, array(), OCI_ASSOC ) ) );
        $this->assertSame( 1722, $this->errorOf( $backend )['code'] );
        $this->assertTrue( eZOracleOci8StandIn::allFreed() );
    }

    // ── cluster gateways ───────────────────────────────────────────────

    private function gateway( $class )
    {
        require_once dirname( __DIR__ ) . '/clusterfilehandlers/gateways/' . ( $class === 'ezpDfsOracleClusterGateway' ? 'dfs.php' : 'db.php' );
        $gateway = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
        Closure::bind( function () { $this->db = 'stand-in'; }, $gateway, $class )();
        return $gateway;
    }

    /** FE-09 */
    public function testGatewayMetadataFetchErrorIsNotNotFound()
    {
        foreach ( array( 'ezpDfsOracleClusterGateway', 'ezpDbOracleClusterGateway' ) as $class )
        {
            $gateway = $this->gateway( $class );
            eZOracleOci8StandIn::reset( array( array( 'rows' => array( array( 'FILESIZE' => 3, 'MTIME' => 1, 'LOB' => null ) ), 'failAfter' => 0 ) ) );
            try
            {
                $gateway->fetchFileMetadata( 'var/storage/a.png' );
                $this->fail( "$class: a RuntimeException was expected" );
            }
            catch ( RuntimeException $e )
            {
                $this->assertStringContainsString( '1722', $e->getMessage() );
            }
            $this->assertTrue( eZOracleOci8StandIn::allFreed(), $class );

            eZOracleOci8StandIn::reset( array( array( 'rows' => array() ) ) );
            $this->assertFalse( $gateway->fetchFileMetadata( 'var/storage/a.png' ), "$class: not found" );
            $this->assertTrue( eZOracleOci8StandIn::allFreed(), $class );

            eZOracleOci8StandIn::reset( array( array( 'rows' => array( array( 'FILESIZE' => 3, 'MTIME' => 1, 'LOB' => null ) ) ) ) );
            $metadata = $gateway->fetchFileMetadata( 'var/storage/a.png' );
            $this->assertSame( 3, $metadata['size'], $class );
        }
    }

    /** FE-10 */
    public function testGatewayPassthroughSendsALastChunkOfZero()
    {
        $gateway = $this->gateway( 'ezpDbOracleClusterGateway' );
        $data = str_repeat( 'abcd', 16383 ) . 'abc' . '0';   // 65535 bytes, then a last chunk "0" (no CLUSTER_CHUNK_SIZE)
        $lob = new eZOracleFetchErrorTestLob( $data );
        Closure::bind( function () use ( $lob ) { $this->lob = $lob; }, $gateway, 'ezpDbOracleClusterGateway' )();
        ob_start();
        $gateway->passthrough( 'var/storage/a.txt', strlen( $data ) );
        $this->assertSame( $data, ob_get_clean() );
        $this->assertTrue( $lob->freed );
    }

    /** FE-10 */
    public function testDbBackendPassThroughReportsAReadThatStops()
    {
        $backend = $this->dbBackend();
        $backend->testLob = new eZOracleFetchErrorTestLob( 'abcdefghij0' );
        ob_start();
        $this->assertTrue( $this->call( $backend, '_passThrough', array( 'var/storage/a.txt' ) ) );
        $this->assertSame( 'abcdefghij0', ob_get_clean(), 'the last chunk "0" is sent' );

        $backend->testLob = new eZOracleFetchErrorTestLob( 'abcdefghijkl', 8 );
        ob_start();
        $this->assertFalse( $this->call( $backend, '_passThrough', array( 'var/storage/a.txt' ) ) );
        $this->assertSame( 'abcdefgh', ob_get_clean() );
        $this->assertTrue( $backend->testLob->freed );
    }
}

?>
