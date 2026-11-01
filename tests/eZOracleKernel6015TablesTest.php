<?php
/**
 * The kernel tables and columns of Exponential 6.0.15 on Oracle: every one has an update in
 * update/database/ezpublish/6.0/ that creates it only when missing, and every text column the
 * kernel declares NOT NULL (outside the primary key) is nullable on Oracle, which stores '' as
 * NULL: the updates write no NOT NULL text column, and settings/dbschema.ini.append.php names
 * each one in ColumnOptionTranslations. No database is needed: the files are checked as text,
 * against the kernel's share/db_schema.dba when the extension sits in an Exponential installation
 * (or the one EZORACLE_KERNEL_ROOT names).
 *
 * Run from an Exponential root:
 *   php vendor/bin/phpunit --bootstrap tests/bootstrap.php extension/ezoracle/tests/eZOracleKernel6015TablesTest.php
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/ezdb/dbms-schema/ezoracleschema.php';

class eZOracleKernel6015TablesTest extends TestCase
{
    /** update file (without dbupdate-6.0.0-6.0.15- and .sql) => the tables it creates and the tables it adds columns to */
    const UPDATES = array(
        'audit-tables'      => array( 'create' => array( 'expaudit_cursor', 'expaudit_event', 'expaudit_file' ) ),
        'bookmark-folders'  => array( 'create' => array( 'expbookmark_folder', 'expbookmark_view' ),
                                      'columns' => array( 'ezcontentbrowsebookmark' => array( 'folder_id', 'priority' ),
                                                          'expbookmark_view' => array( 'module_name', 'view_name', 'view_parameters' ) ) ),
        'mail-preferences'  => array( 'create' => array( 'expmail_category', 'expmail_consent_log', 'expmail_pending', 'expmail_preference', 'expmail_suppression' ) ),
        'api-keys'          => array( 'create' => array( 'expapikey' ) ),
        'passkeys'          => array( 'create' => array( 'exppasskey' ) ),
        'transfer-runs'     => array( 'create' => array( 'exp_transfer_run' ) ),
        'data-tables'       => array( 'create' => array( 'exp_data_connection', 'exp_secret', 'exp_data_sync', 'exp_data_sync_conflict', 'exp_data_sync_link' ) ),
        'user-sessions'     => array( 'create' => array( 'expuser_session', 'expuser_session_info' ) ),
        'user-audit'        => array( 'create' => array( 'expuser_audit' ) ),
        'help-tables'       => array( 'create' => array( 'exphelp_help_id', 'exphelp_feedback' ) ),
        'migration-tables'  => array( 'create' => array( 'exp_migration', 'exp_migration_run' ) ),
        'pdf-export-footer' => array( 'columns' => array( 'ezpdf_export' => array( 'show_footer', 'footer_text' ) ) ),
        'rss-opml-podcast'  => array( 'create' => array( 'ezrss_export_opml_item' ),
                                      'columns' => array( 'ezrss_export' => array( 'opml_head', 'podcast_head' ) ) ),
        'rest-pkce'         => array( 'columns' => array( 'ezprest_authcode' => array( 'code_challenge', 'code_challenge_method' ) ) ),
        'trash-columns'     => array( 'columns' => array( 'ezcontentobject_trash' => array( 'trashed_by', 'trashed_via' ) ) ),
        'order-siteaccess'  => array( 'columns' => array( 'ezorder' => array( 'siteaccess' ) ) ),
    );

    const TEXT_TYPES = array( 'varchar', 'char', 'longtext', 'mediumtext', 'text', 'tinytext' );

    private static function sql( $update )
    {
        $file = dirname( __DIR__ ) . "/update/database/ezpublish/6.0/dbupdate-6.0.0-6.0.15-$update.sql";
        return is_file( $file ) ? preg_replace( '/^\s*--.*$/m', '', file_get_contents( $file ) ) : null;
    }

    private static function kernelSchema()
    {
        $root = getenv( 'EZORACLE_KERNEL_ROOT' ) ?: dirname( __DIR__, 3 );
        if ( !is_file( "$root/share/db_schema.dba" ) )
            return null;
        $schema = null;
        include "$root/share/db_schema.dba";
        return $schema;
    }

    public function testEveryTableIsCreatedOnlyWhenMissing()
    {
        foreach ( self::UPDATES as $update => $what )
        {
            $sql = self::sql( $update );
            $this->assertNotNull( $sql, "update $update exists" );
            foreach ( isset( $what['create'] ) ? $what['create'] : array() as $table )
            {
                $this->assertMatchesRegularExpression( "/FROM user_tables WHERE table_name = '" . strtoupper( $table ) . "';\s+IF n = 0 THEN/", $sql, "$update checks $table" );
                $this->assertSame( 1, preg_match_all( "/EXECUTE IMMEDIATE 'CREATE TABLE $table \(/", $sql ), "$update creates $table once" );
            }
            foreach ( isset( $what['columns'] ) ? $what['columns'] : array() as $table => $cols )
                foreach ( $cols as $col )
                    $this->assertMatchesRegularExpression( "/table_name = '" . strtoupper( $table ) . "' AND column_name = '" . strtoupper( $col ) . "';\s+IF n = 0 THEN\s+EXECUTE IMMEDIATE 'ALTER TABLE $table ADD $col /",
                                                           $sql, "$update adds $table.$col only when missing" );
        }
    }

    public function testUpdatesAreRerunnablePlsql()
    {
        foreach ( array_keys( self::UPDATES ) as $update )
        {
            $sql = self::sql( $update );
            // nothing changes the schema outside EXECUTE IMMEDIATE in a guarded block
            $this->assertDoesNotMatchRegularExpression( '/^\s*(CREATE|ALTER|DROP)\s/mi', $sql, "$update: no bare DDL" );
            // every block ends with a slash on its own line for SQL*Plus and SQLcl
            $this->assertSame( preg_match_all( '/^DECLARE\s*$/m', $sql ), preg_match_all( '/^END;\n\/\s*$/m', $sql ), "$update: one slash per block" );
        }
        // the order statuses are added only when their status_id is free
        $sql = self::sql( 'order-statuses' );
        $this->assertSame( 14, preg_match_all( '/INSERT INTO ezorder_status .*\n\s+SELECT 1, \'[^\']+\', (\d+) FROM dual WHERE NOT EXISTS \( SELECT 1 FROM ezorder_status WHERE status_id = \1 \);/', $sql ) );
    }

    public function testExpupgradeStepIsNeverCreated()
    {
        // the pre-release record table: no update creates it and the fresh-install settings do not name it
        $this->assertSame( '', trim( (string)self::sql( 'upgrade-steps' ) ), 'upgrade-steps is retired and runs nothing' );
        foreach ( glob( dirname( __DIR__ ) . '/update/database/ezpublish/6.0/dbupdate-6.0.0-6.0.15-*.sql' ) as $file )
            $this->assertStringNotContainsString( 'CREATE TABLE expupgrade_step', file_get_contents( $file ), basename( $file ) );
        $this->assertStringNotContainsString( 'expupgrade_step', file_get_contents( dirname( __DIR__ ) . '/settings/dbschema.ini.append.php' ) );
        $schema = self::kernelSchema();
        if ( $schema !== null )
            $this->assertArrayNotHasKey( 'expupgrade_step', $schema, 'the kernel schema has no expupgrade_step' );
    }

    public function testMigrationRecordCopiesExpupgradeStepOnceThenDropsIt()
    {
        $sql = self::sql( 'migration-tables' );
        $this->assertMatchesRegularExpression( "/table_name = 'EXPUPGRADE_STEP';\s+IF n = 1 THEN\s+EXECUTE IMMEDIATE '\s*INSERT INTO exp_migration/", $sql );
        // after the copy: every row checked, then dropped, all inside the IF n = 1 of the same block
        $copy = strpos( $sql, 'INSERT INTO exp_migration' );
        $check = strpos( $sql, 'SELECT COUNT(*) FROM expupgrade_step s' );
        $raise = strpos( $sql, 'RAISE_APPLICATION_ERROR( -20001' );
        $drop = strpos( $sql, "EXECUTE IMMEDIATE 'DROP TABLE expupgrade_step PURGE'" );
        $this->assertTrue( $copy !== false && $check > $copy && $raise > $check && $drop > $raise, 'copy, check, refuse, drop' );
        $this->assertSame( 1, substr_count( $sql, 'DROP TABLE expupgrade_step' ) );
        $this->assertMatchesRegularExpression( "/DROP TABLE expupgrade_step PURGE';\s+END IF;\s+END;\n\//", $sql );
        $this->assertStringContainsString( 'GREATEST( s.applied_at - ROUND( s.duration_ms / 1000 ), 0 )', $sql );
        $this->assertStringContainsString( 'SUBSTR( s.step_id, 9 )', $sql );
        $this->assertStringContainsString( "WHERE NOT EXISTS ( SELECT 1 FROM exp_migration m WHERE m.source = ''kernel'' AND m.migration_id = s.step_id )", $sql );
        $this->assertStringContainsString( 'CREATE INDEX exp_migration_run_id ON exp_migration ( run_id )', $sql );
        $this->assertStringContainsString( 'CREATE INDEX exp_migration_run_started ON exp_migration_run ( started_at )', $sql );
        $this->assertStringContainsString( 'PRIMARY KEY ( source, migration_id )', $sql );
        $this->assertStringContainsString( 'PRIMARY KEY ( run_id )', $sql );
    }

    public function testNoTextColumnIsNotNullInTheUpdates()
    {
        foreach ( array_keys( self::UPDATES ) as $update )
            $this->assertDoesNotMatchRegularExpression( '/\b(VARCHAR2|CHAR|CLOB)\b(\(\d+\))?[^,\n\']*NOT NULL/', self::sql( $update ), "$update: text columns are nullable ('' is NULL in Oracle)" );
    }

    public function testKernelTablesMatchTheUpdatesAndTheSettings()
    {
        $schema = self::kernelSchema();
        if ( $schema === null )
            $this->markTestSkipped( 'not inside an Exponential installation' );
        $ini = file_get_contents( dirname( __DIR__ ) . '/settings/dbschema.ini.append.php' );
        $handler = ( new ReflectionClass( 'eZOracleSchema' ) )->newInstanceWithoutConstructor();
        $checked = 0;
        foreach ( self::UPDATES as $update => $what )
        {
            $sql = self::sql( $update );
            $columns = array();
            foreach ( isset( $what['create'] ) ? $what['create'] : array() as $table )
                if ( isset( $schema[$table] ) )
                    $columns[$table] = array_keys( $schema[$table]['fields'] );
            foreach ( isset( $what['columns'] ) ? $what['columns'] : array() as $table => $cols )
                if ( isset( $schema[$table]['fields'][$cols[0]] ) )
                    $columns[$table] = $cols;
            foreach ( $columns as $table => $cols )
            {
                $pk = isset( $schema[$table]['indexes']['PRIMARY']['fields'] ) ? $schema[$table]['indexes']['PRIMARY']['fields'] : array();
                foreach ( $cols as $col )
                {
                    $field = $schema[$table]['fields'][$col];
                    ++$checked;
                    // the column is in the update, with the Oracle type the handler gives it
                    $type = $field['type'] == 'auto_increment' ? 'INTEGER' : $handler->getOracleType( $field['type'] );
                    $this->assertMatchesRegularExpression( '/[\s(]' . $col . ' ' . preg_quote( $type ) . '\b/', $sql, "$update: $table.$col $type" );
                    if ( in_array( $field['type'], self::TEXT_TYPES ) && !empty( $field['not_null'] ) && !in_array( $col, $pk ) )
                        $this->assertStringContainsString( "\nColumnOptionTranslations[$table.$col]=null\n", $ini, "$table.$col is nullable on a fresh install" );
                }
                foreach ( isset( $what['create'] ) && in_array( $table, $what['create'] ) ? $schema[$table]['indexes'] : array() as $name => $index )
                    if ( $index['type'] != 'primary' )
                        $this->assertMatchesRegularExpression( '/CREATE (UNIQUE )?INDEX ' . $name . ' ON ' . $table . ' /', $sql, "$update: index $name" );
            }
        }
        if ( $checked == 0 )
            $this->markTestSkipped( 'the kernel of this installation predates the tables of Exponential 6.0.15' );
    }
}
