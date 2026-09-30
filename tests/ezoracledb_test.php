<?php
/**
 * @todo move the relationlist/relationcount tests out of this into a generic db testsuite
 * @todo test using alphanumeric col name in arrayquery
 * @todo test arrayquery with all combinations of offset, limit (in generic db testsuite)
 * @todo test arrayquery with and without offset, limit and enabled driver charset conversion
 * @todo test fix for issue #015436 (in generic db testsuite )
 */

class eZOracleDBTest extends ezpDatabaseTestCase
{
    protected $insertDefaultData = false;

    public function __construct()
    {
        parent::__construct();
        $this->setName( "eZOracleDB Unit Tests" );
    }

    protected function setUp()
    {
        parent::setUp();

        if ( $this->sharedFixture->databaseName() !== "oracle" )
            self::markTestSkipped( "Not running Oracle, skipping" );

        ezpTestDatabaseHelper::clean( $this->sharedFixture );
    }

    public function testRelationCounts()
    {
        $db = $this->sharedFixture;
        $db->query( "CREATE TABLE a ( name varchar(40) )" );
        $db->query( "CREATE TABLE b ( name varchar(40) )" );
        $db->query( "CREATE TABLE c ( name varchar(40) )" );

        $relationCount = $db->relationCounts( eZDBInterface::RELATION_TABLE_BIT );
        self::assertEquals( 3, (int) $relationCount );
    }

    public function testRelationCount()
    {
        $db = $this->sharedFixture;
        $db->query( "CREATE TABLE a ( name varchar(40) )" );
        $db->query( "CREATE TABLE b ( name varchar(40) )" );
        $db->query( "CREATE TABLE c ( name varchar(40) )" );

        $relationCount = $db->relationCount( eZDBInterface::RELATION_TABLE );
        self::assertEquals( 3, (int) $relationCount );
    }

    public function testRelationList()
    {
        $db = $this->sharedFixture;
        $db->query( "CREATE TABLE a ( name varchar(40) )" );
        $db->query( "CREATE TABLE b ( name varchar(40) )" );
        $db->query( "CREATE TABLE c ( name varchar(40) )" );

        $relationList = $db->relationList( eZDBInterface::RELATION_TABLE );
        $relationArray = array( "a", "b", "c" );
        self::assertEquals( $relationArray, $relationList );
    }

    /**
     * A CLOB longer than 2015 characters is read whole, also with LobPrefetch
     * set above the cap (a prefetch size from 2015 up cut such CLOBs short).
     */
    public function testLongClobIsReadWholeWithLobPrefetchSet()
    {
        $db = $this->sharedFixture;
        $db->query( "CREATE TABLE a ( id INTEGER PRIMARY KEY, data_text CLOB )" );
        $lengths = array( 1, 2015, 2016, 4001, 8000, 70000 );
        foreach ( $lengths as $id => $length )
        {
            $bound = $db->bindVariable( str_repeat( 'x', $length ), array( 'name' => 'data_text' ) );
            $db->query( "INSERT INTO a ( id, data_text ) VALUES ( $id, $bound )" );
        }

        $lobPrefetch = $db->LobPrefetch;
        $db->LobPrefetch = 65536;
        $rows = $db->arrayQuery( "SELECT id, data_text FROM a ORDER BY id" );
        $one = $db->arrayQuery( "SELECT data_text FROM a WHERE id = 4", array( 'column' => 'data_text' ) );
        $db->LobPrefetch = $lobPrefetch;

        foreach ( $rows as $row )
        {
            self::assertEquals( $lengths[$row['id']], strlen( $row['data_text'] ) );
        }
        self::assertEquals( 8000, strlen( $one[0] ) );
    }
}

?>