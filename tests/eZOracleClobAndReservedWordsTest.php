<?php
/**
 * The SQL that ezoracle generates for long CLOB values and for columns named by
 * Oracle reserved words. No database is needed: the statements are checked as
 * text. The same cases run against Oracle are in the extension's history (2.3.6).
 *
 * Run from an Exponential root:
 *   php vendor/bin/phpunit --bootstrap tests/bootstrap.php extension/ezoracle/tests/eZOracleClobAndReservedWordsTest.php
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/ezdb/dbms-drivers/ezoracledb.php';
require_once dirname( __DIR__ ) . '/ezdb/dbms-schema/ezoracleschema.php';

class eZOracleClobAndReservedWordsTest extends TestCase
{
    /** an Oracle driver object that is not connected, with its default settings */
    private function driver()
    {
        return ( new ReflectionClass( 'eZOracleDB' ) )->newInstanceWithoutConstructor();
    }

    private function schemaHandler()
    {
        return ( new ReflectionClass( 'eZOracleSchema' ) )->newInstanceWithoutConstructor();
    }

    public static function texts()
    {
        return array(
            'german above 4000 bytes' => array( str_repeat( "Größenänderung über Straßen: ", 300 ) ),
            'chinese above 32 KB'     => array( str_repeat( "中文测试长文本的分块", 1200 ) ),
            'emoji above 32 KB'       => array( str_repeat( "Emoji 😀🎉🚀 'q' ", 2500 ) ),
            'multibyte at the edge'   => array( str_repeat( 'a', 3999 ) . "€😀" . str_repeat( 'b', 4100 ) ),
        );
    }

    /**
     * @dataProvider texts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('texts')]
    public function testClobTextIsSplitAtCharacterBoundaries( $text )
    {
        $chunks = eZOracleSchema::splitClobText( $text );
        $this->assertSame( $text, implode( '', $chunks ) );
        foreach ( $chunks as $chunk )
        {
            $this->assertLessThanOrEqual( 4000, strlen( $chunk ) );
            $this->assertSame( 1, preg_match( '//u', $chunk ), 'every piece is whole UTF-8' );
        }
    }

    /**
     * @dataProvider texts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('texts')]
    public function testLongClobInsertNeedsNoByteCountAndNoCommit( $text )
    {
        $table = array( 'fields' => array( 'id' => array( 'type' => 'int' ), 'body' => array( 'type' => 'longtext' ) ), 'indexes' => array() );
        $sql = implode( "\n", $this->schemaHandler()->generateTableInsertSQLList( 't', $table,
            array( 'fields' => array( 'id', 'body' ), 'rows' => array( array( 1, $text ) ) ), array(), false ) );
        $this->assertDoesNotMatchRegularExpression( '/\bCOMMIT\b/i', $sql, 'the caller owns the transaction' );
        $this->assertStringNotContainsString( 'writeappend', $sql, 'no amount counted in bytes' );
        $this->assertStringContainsString( 'dbms_lob.append(v_1, TO_CLOB(', $sql );
        preg_match_all( "/TO_CLOB\\('((?:[^']|'')*)'\\)/", $sql, $m );
        $this->assertSame( $text, str_replace( "''", "'", implode( '', $m[1] ) ) );
    }

    public function testReservedColumnNamesAreQuotedInTheSchemaSql()
    {
        $this->assertSame( '"COMMENT"', eZOracleSchema::quoteIdentifier( 'comment' ) );
        $this->assertSame( '"MODE"', eZOracleSchema::quoteIdentifier( 'mode' ) );
        $this->assertSame( 'comment_id', eZOracleSchema::quoteIdentifier( 'comment_id' ) );

        $table = array( 'fields' => array( 'id' => array( 'type' => 'int', 'not_null' => '1', 'default' => 0 ),
                                           'mode' => array( 'type' => 'varchar', 'length' => 50, 'default' => 'latest' ),
                                           'comment' => array( 'type' => 'longtext' ) ),
                        'indexes' => array( 'PRIMARY' => array( 'type' => 'primary', 'fields' => array( 'id' ) ),
                                            'mode_idx' => array( 'type' => 'non-unique', 'fields' => array( 'mode' ) ) ) );
        $sql = implode( "\n", $this->schemaHandler()->generateTableSQLList( 'cjwnl_x', $table, array(), false ) );
        $this->assertStringContainsString( '"MODE" VARCHAR2(50)', $sql );
        $this->assertStringContainsString( '"COMMENT" CLOB', $sql );
        $this->assertStringContainsString( 'ON cjwnl_x ( "MODE" )', $sql );

        $insert = implode( "\n", $this->schemaHandler()->generateTableInsertSQLList( 'cjwnl_x', $table,
            array( 'fields' => array( 'id', 'mode', 'comment' ), 'rows' => array( array( 1, 'copy', str_repeat( 'ü', 3000 ) ) ) ), array(), false ) );
        $this->assertStringContainsString( '(id, "MODE", "COMMENT")', $insert );
        $this->assertStringContainsString( 'RETURNING "COMMENT" INTO', $insert );
    }

    public static function statements()
    {
        return array(
            array( 'SELECT comment FROM cjwnl_approval', 'SELECT "COMMENT" FROM cjwnl_approval' ),
            array( 'SELECT a.comment, a.Mode FROM t a WHERE a.mode = \'mode\' ORDER BY mode', 'SELECT a."COMMENT", a."MODE" FROM t a WHERE a."MODE" = \'mode\' ORDER BY "MODE"' ),
            array( "INSERT INTO cjwnl_approval (id, comment) VALUES (1, 'a comment, it''s mode')", "INSERT INTO cjwnl_approval (id, \"COMMENT\") VALUES (1, 'a comment, it''s mode')" ),
            array( 'UPDATE t SET comment=:ezb_Comment0 WHERE id=1', 'UPDATE t SET "COMMENT"=:ezb_Comment0 WHERE id=1' ),
            array( 'SELECT comment_id, t_mode, "COMMENT" FROM t -- comment', 'SELECT comment_id, t_mode, "COMMENT" FROM t -- comment' ),
            array( 'INSERT INTO t (x) VALUES (:comment)', 'INSERT INTO t (x) VALUES (:comment)' ),
            array( 'LOCK TABLE t IN EXCLUSIVE MODE', 'LOCK TABLE t IN EXCLUSIVE MODE' ),
            array( "COMMENT ON TABLE t IS 'x'", "COMMENT ON TABLE t IS 'x'" ),
            array( 'SELECT id FROM ezcontentobject', 'SELECT id FROM ezcontentobject' ),
        );
    }

    /**
     * @dataProvider statements
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statements')]
    public function testDriverQuotesReservedColumnNames( $sql, $expected )
    {
        $this->assertSame( $expected, $this->driver()->quoteReservedColumnNames( $sql ) );
    }

    public static function typeColumnStatements()
    {
        return array(
            // a column named number in DML is quoted
            array( "SELECT * FROM ticketsystem_ticket WHERE number='TS-2026-00001'", "SELECT * FROM ticketsystem_ticket WHERE \"NUMBER\"='TS-2026-00001'" ),
            array( 'INSERT INTO ticketsystem_ticket (id, number, prefix) VALUES (:id, :ezb_Number1, :prefix)',
                   'INSERT INTO ticketsystem_ticket (id, "NUMBER", prefix) VALUES (:id, :ezb_Number1, :prefix)' ),
            array( 'UPDATE ticketsystem_ticket SET number=:ezb_Number0, status=:status WHERE id=1',
                   'UPDATE ticketsystem_ticket SET "NUMBER"=:ezb_Number0, status=:status WHERE id=1' ),
            array( 'SELECT number, comment FROM t ORDER BY number', 'SELECT "NUMBER", "COMMENT" FROM t ORDER BY "NUMBER"' ),
            // where it is the type it stays
            array( 'SELECT CAST( x AS NUMBER ), TO_NUMBER( y ), z_number FROM t', 'SELECT CAST( x AS NUMBER ), TO_NUMBER( y ), z_number FROM t' ),
            array( 'SELECT NUMBER(5) FROM dual', 'SELECT NUMBER(5) FROM dual' ),
            // DDL and PL/SQL: never quoted (the schema handler quotes the column in its own DDL)
            array( 'CREATE TABLE t ( id NUMBER(11,0) NOT NULL, n NUMBER DEFAULT 0 )', 'CREATE TABLE t ( id NUMBER(11,0) NOT NULL, n NUMBER DEFAULT 0 )' ),
            array( 'ALTER TABLE ezurlalias_ml ADD alias_redirects number(11) default 1 NOT NULL', 'ALTER TABLE ezurlalias_ml ADD alias_redirects number(11) default 1 NOT NULL' ),
            array( "DECLARE m NUMBER; v NUMBER; BEGIN EXECUTE IMMEDIATE 'SELECT 1 FROM dual' INTO m; END;", "DECLARE m NUMBER; v NUMBER; BEGIN EXECUTE IMMEDIATE 'SELECT 1 FROM dual' INTO m; END;" ),
            array( 'CREATE OR replace FUNCTION bitor( x IN NUMBER, y IN NUMBER ) RETURN NUMBER AS BEGIN RETURN x; END;',
                   'CREATE OR replace FUNCTION bitor( x IN NUMBER, y IN NUMBER ) RETURN NUMBER AS BEGIN RETURN x; END;' ),
            // the reserved names as before
            array( 'LOCK TABLE t IN EXCLUSIVE MODE', 'LOCK TABLE t IN EXCLUSIVE MODE' ),
            array( 'SELECT x AS comment FROM t', 'SELECT x AS "COMMENT" FROM t' ),
        );
    }

    /**
     * @dataProvider typeColumnStatements
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('typeColumnStatements')]
    public function testDriverQuotesTypeColumnNamesOnlyWhereTheyAreColumns( $sql, $expected )
    {
        $db = $this->driver();
        $db->QuotedTypeColumnNames = array( 'number' );
        $this->assertSame( $expected, $db->quoteReservedColumnNames( $sql ) );
    }

    public function testTypeColumnNamesAreOffByDefault()
    {
        $this->assertSame( "SELECT number FROM t", $this->driver()->quoteReservedColumnNames( "SELECT number FROM t" ) );
    }

    public function testBindVariableNamedByATypeColumnGetsAPrefix()
    {
        $db = $this->driver();
        $db->QuotedTypeColumnNames = array( 'number' );
        $this->assertSame( ':ezb_Number0', $db->bindVariable( 'x', array( 'name' => 'Number' ) ) );
    }

    public function testBindVariableNamedByAReservedWordGetsAPrefix()
    {
        $db = $this->driver();
        $this->assertSame( ':ezb_Comment0', $db->bindVariable( 'x', array( 'name' => 'Comment' ) ) );
        $this->assertSame( ':LongDescription', $db->bindVariable( 'y', array( 'name' => 'LongDescription' ) ) );
    }
}
