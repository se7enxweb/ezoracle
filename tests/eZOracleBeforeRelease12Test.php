<?php
/**
 * The SQL that ezoracle writes for MD5 and for row limits on each side of
 * Oracle 12.1: 11g has neither STANDARD_HASH() nor OFFSET / FETCH NEXT. No
 * database is needed: the server version is set on a driver that is not
 * connected, and the statements are checked as text.
 *
 * Run from an Exponential root:
 *   php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php extension/ezoracle/tests/eZOracleBeforeRelease12Test.php
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/ezdb/dbms-drivers/ezoracledb.php';
require_once dirname( __DIR__ ) . '/clusterfilehandlers/dfsbackends/oracle.php';

class eZOracleBeforeRelease12Test extends TestCase
{
    /**
     * A driver that is not connected, as if its server were of release
     * $majorVersion; null leaves the version unknown.
     */
    private function driver( $majorVersion = null )
    {
        $db = ( new ReflectionClass( 'eZOracleDB' ) )->newInstanceWithoutConstructor();
        $db->ServerMajorVersion = $majorVersion;
        return $db;
    }

    /** the SQL literal escapeString() writes for $text */
    private function literal( $text )
    {
        return "'" . $this->driver()->escapeString( $text ) . "'";
    }

    public static function texts()
    {
        return array(
            'plain'            => array( 'fit-healthy' ),
            'empty string'     => array( '' ),
            'doubled quotes'   => array( "it's o'clock" ),
            'only a quote'     => array( "'" ),
            'quotes at edges'  => array( "'quoted'" ),
            'umlauts'          => array( 'größenänderung-über-straßen' ),
            'chinese, emoji'   => array( '中文测试-😀🎉' ),
            'white space'      => array( "  a\tb\nc  " ),
            'sql look-alike'   => array( "a' || 'b" ),
            'q quote syntax'   => array( "q'[x]'" ),
            'long'             => array( str_repeat( 'äöü-', 50000 ) ),
            '20000 quotes'     => array( str_repeat( "'", 20000 ) ),
            'long with quotes' => array( str_repeat( "o'k ", 100000 ) ),
        );
    }

    /**
     * @dataProvider texts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('texts')]
    public function testLiteralIsHashedByPhpBefore12( $text )
    {
        foreach ( array( 9, 10, 11 ) as $version )
        {
            $this->assertSame( " '" . md5( $text ) . "' ", $this->driver( $version )->md5( $this->literal( $text ) ),
                "release $version" );
        }
    }

    /**
     * @dataProvider texts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('texts')]
    public function testLiteralValueUndoesEscapeString( $text )
    {
        $this->assertSame( $text, eZOracleDB::stringLiteralValue( $this->literal( $text ) ) );
        $this->assertSame( $text, eZOracleDB::stringLiteralValue( " \n" . $this->literal( $text ) . "\t " ) );
    }

    public static function nonLiterals()
    {
        return array(
            'column'                 => array( 'text' ),
            'function of a column'   => array( 'LOWER( text )' ),
            'concatenation'          => array( "'a' || 'b'" ),
            'literal and column'     => array( "'a' || text" ),
            'two literals'           => array( "'a' 'b'" ),
            'national literal'       => array( "N'abc'" ),
            'alternative quoting'    => array( "q'[it's]'" ),
            'alternative, lowercase' => array( "Q'{x}'" ),
            'unclosed literal'       => array( "'abc" ),
            'odd quote inside'       => array( "'a'b'" ),
            'three quotes'           => array( "'''" ),
            'bind variable'          => array( ':text' ),
            'empty'                  => array( '' ),
        );
    }

    /**
     * @dataProvider nonLiterals
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonLiterals')]
    public function testAnythingElseGoesToMd5DigestBefore12( $sql )
    {
        $this->assertNull( eZOracleDB::stringLiteralValue( $sql ) );
        $this->assertSame( " md5_digest( $sql ) ", $this->driver( 11 )->md5( $sql ) );
    }

    public static function standardHashVersions()
    {
        return array( 'unknown' => array( null ), '12.1' => array( 12 ), '19c' => array( 19 ), '23ai' => array( 23 ) );
    }

    /**
     * @dataProvider standardHashVersions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('standardHashVersions')]
    public function testStandardHashFrom12AndWhenTheVersionIsUnknown( $version )
    {
        $db = $this->driver( $version );
        $this->assertTrue( $db->hasStandardHash() );
        foreach ( array( "'fit-healthy'", "''", 'text' ) as $sql )
        {
            $this->assertSame( " NVL( LOWER( RAWTOHEX( STANDARD_HASH( $sql, 'MD5' ) ) ), 'd41d8cd98f00b204e9800998ecf8427e' ) ",
                $db->md5( $sql ) );
        }
    }

    public function testUnknownVersionIsNotKept()
    {
        $db = $this->driver();
        $this->assertSame( 0, $db->serverMajorVersion() );
        $this->assertNull( $db->ServerMajorVersion, 'read again once connected' );
        $this->assertFalse( $this->driver( 11 )->hasStandardHash() );
        $this->assertSame( 11, $this->driver( 11 )->serverMajorVersion() );
    }

    public static function rowLimitCases()
    {
        return array(
            array( 'SELECT * FROM ezcontentobject ORDER BY id', true ),
            array( '  ( SELECT id FROM a ) UNION ( SELECT id FROM b )', true ),
            array( 'WITH t AS ( SELECT 1 x FROM dual ) SELECT * FROM t', true ),
            array( 'select id from ezuser', true ),
            array( 'SELECT * FROM ezcontentobject FOR UPDATE', false ),
            array( 'SELECT * FROM a FETCH FIRST 5 ROWS ONLY', false ),
            array( 'SELECT * FROM a OFFSET 10 ROWS', false ),
            array( 'SELECT * FROM a;', false ),
            array( 'UPDATE a SET b = 1', false ),
            array( 'DELETE FROM a', false ),
            array( 'BEGIN NULL; END;', false ),
        );
    }

    /**
     * @dataProvider rowLimitCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rowLimitCases')]
    public function testRowLimitIsAppendedFrom12Only( $sql, $from12 )
    {
        foreach ( array( array( 12, $from12 ), array( 19, $from12 ), array( 23, $from12 ),
                         array( 11, false ), array( 10, false ), array( null, false ) ) as list( $version, $expected ) )
        {
            $this->assertSame( $expected, $this->driver( $version )->canAppendRowLimit( $sql ), var_export( $version, true ) . ": $sql" );
        }
    }

    public function testClusterFileListLimitFrom12()
    {
        $this->assertSame( 'SELECT name FROM ezdfsfile ORDER BY name OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY',
            eZDFSFileHandlerOracleBackend::limitQuery( 'SELECT name FROM ezdfsfile ORDER BY name', 20, 10, 19 ) );
    }

    public function testClusterFileListLimitBefore12AndWhenUnknown()
    {
        foreach ( array( 11, 0 ) as $version )
        {
            $sql = eZDFSFileHandlerOracleBackend::limitQuery( 'SELECT name FROM ezdfsfile ORDER BY name', 20, 10, $version );
            $this->assertSame( 'SELECT name FROM ( SELECT q.name, ROWNUM ezdfs_rn FROM ( SELECT name FROM ezdfsfile ORDER BY name ) q ' .
                               'WHERE ROWNUM <= 30 ) WHERE ezdfs_rn > 20', $sql );
            $this->assertDoesNotMatchRegularExpression( '/\b(OFFSET|FETCH)\b/', $sql );
        }
    }

    public function testClusterServerVersionIsUnknownWithoutConnection()
    {
        $backend = ( new ReflectionClass( 'eZDFSFileHandlerOracleBackend' ) )->newInstanceWithoutConstructor();
        $this->assertSame( 0, $backend->serverMajorVersion() );
    }
}

?>
