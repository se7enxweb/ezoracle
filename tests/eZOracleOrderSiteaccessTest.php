<?php
/**
 * ezorder.siteaccess of Exponential 6.0.15 on Oracle: the siteaccess an order was placed in. The update adds it as a
 * nullable VARCHAR2(255) only when user_tab_columns does not list it, so it can run again; a fresh install gets the
 * same column from the kernel's share/db_schema.dba (varchar(255) NOT NULL default ''), which the schema handler
 * makes nullable without a default, as Oracle stores '' as NULL. No database is needed: the files and the generated
 * column definition are checked as text.
 *
 * Run from an Exponential root:
 *   php vendor/bin/phpunit --bootstrap tests/bootstrap.php extension/ezoracle/tests/eZOracleOrderSiteaccessTest.php
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/ezdb/dbms-schema/ezoracleschema.php';

class eZOracleOrderSiteaccessTest extends TestCase
{
    const UPDATE = '/update/database/ezpublish/6.0/dbupdate-6.0.0-6.0.15-order-siteaccess.sql';

    /** the statements of the update, without its comment lines */
    private function statements()
    {
        return preg_replace( '/^\s*--.*$/m', '', file_get_contents( dirname( __DIR__ ) . self::UPDATE ) );
    }

    public function testUpdateAddsTheColumnOnlyWhenMissing()
    {
        $sql = $this->statements();
        $this->assertSame( 1, preg_match_all( "/EXECUTE IMMEDIATE 'ALTER TABLE ezorder ADD siteaccess VARCHAR2\(255\)'/", $sql ), 'one ADD' );
        $this->assertMatchesRegularExpression( "/SELECT COUNT\(\*\) INTO n FROM user_tab_columns\s+WHERE table_name = 'EZORDER' AND column_name = 'SITEACCESS';\s+IF n = 0 THEN/", $sql );
        $this->assertStringNotContainsString( 'NOT NULL', $sql, "'' is NULL in Oracle: the column must take an order without a siteaccess" );
        // a PL/SQL block, ended by a slash on its own line for SQL*Plus and SQLcl
        $this->assertMatchesRegularExpression( "/^DECLARE\s.*^END;\n\/\s*$/ms", trim( $sql ) . "\n" );
    }

    public function testFreshInstallMakesTheColumnNullable()
    {
        $ini = file_get_contents( dirname( __DIR__ ) . '/settings/dbschema.ini.append.php' );
        $this->assertStringContainsString( "\nColumnOptionTranslations[ezorder.siteaccess]=null\n", $ini );

        // the column as the kernel's schema declares it, after the transformation for Oracle (no NOT NULL, no '')
        $handler = ( new ReflectionClass( 'eZOracleSchema' ) )->newInstanceWithoutConstructor();
        $this->assertSame( 'siteaccess VARCHAR2(255)',
                           $handler->generateFieldDef( 'siteaccess', array( 'length' => 255, 'type' => 'varchar', 'default' => null ) ) );
    }

    public function testKernelSchemaDeclaresTheColumn()
    {
        $dba = dirname( __DIR__, 3 ) . '/share/db_schema.dba';
        if ( !is_file( $dba ) )
            $this->markTestSkipped( 'not inside an Exponential installation' );
        $schema = null;
        include $dba;
        if ( !isset( $schema['ezorder']['fields']['siteaccess'] ) )
            $this->markTestSkipped( 'the kernel of this installation predates ezorder.siteaccess (Exponential 6.0.15)' );
        $this->assertSame( 'varchar', $schema['ezorder']['fields']['siteaccess']['type'] );
        $this->assertSame( 255, $schema['ezorder']['fields']['siteaccess']['length'] );
    }
}
