<?php
/**
 * Comparisons with the empty string (eZOracleDB::rewriteEmptyStringComparisons()). Oracle stores '' as NULL, so
 * "column <> ''" and "column = ''" are never true there, and on a CLOB column they fail with ORA-22848. The driver
 * sends them as IS NOT NULL and IS NULL. No database is needed: the statements are checked as text; the same
 * statements were run against Oracle AI Database 26ai Free (the CLOB columns of explayouts_block_parameter and
 * expai_usage_log).
 *
 * Run from an Exponential root:
 *   php vendor/bin/phpunit --bootstrap tests/bootstrap.php extension/ezoracle/tests/eZOracleEmptyStringComparisonTest.php
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/ezdb/dbms-drivers/ezoracledb.php';

class eZOracleEmptyStringComparisonTest extends TestCase
{
    public static function statements()
    {
        return array(
            'not equal, both spellings' => array(
                "SELECT id FROM t WHERE value IS NOT NULL AND value <> '' AND b.name != ''",
                "SELECT id FROM t WHERE value IS NOT NULL AND value IS NOT NULL AND b.name IS NOT NULL" ),
            'equal after WHERE, AND, OR, ON, WHEN and a bracket' => array(
                "SELECT a FROM t JOIN u ON u.x = '' WHERE t.a = '' OR (b = '') AND CASE WHEN c = '' THEN 1 END = 1",
                "SELECT a FROM t JOIN u ON u.x IS NULL WHERE t.a IS NULL OR (b IS NULL) AND CASE WHEN c IS NULL THEN 1 END = 1" ),
            'an assignment is kept' => array(
                "UPDATE t SET a = '', b = '' WHERE c <> ''",
                "UPDATE t SET a = '', b = '' WHERE c IS NOT NULL" ),
            'values and literals are kept' => array(
                "INSERT INTO t ( a, b ) VALUES ( '', 'x <> '''' and y = ''' )",
                "INSERT INTO t ( a, b ) VALUES ( '', 'x <> '''' and y = ''' )" ),
            'a quoted literal that only looks empty' => array(
                "SELECT a FROM t WHERE a = 'it''s' AND b = ''''",
                "SELECT a FROM t WHERE a = 'it''s' AND b = ''''" ),
            'nothing to do' => array(
                "SELECT a FROM t WHERE a = 'x'",
                "SELECT a FROM t WHERE a = 'x'" ),
        );
    }

    /**
     * @dataProvider statements
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('statements')]
    public function testRewrite( $sql, $expected )
    {
        $this->assertSame( $expected, eZOracleDB::rewriteEmptyStringComparisons( $sql ) );
    }
}
