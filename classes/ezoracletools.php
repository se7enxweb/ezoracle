<?php
/**
 * File containing the eZOracleTools class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @copyright Copyright (C) 1999-2013 eZ Systems AS, 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */

/**
 * Maintenance and diagnostics for an Exponential installation on Oracle, shared
 * by the console commands (extension/ezoracle/bin/php, ext:ezoracle:*) and the
 * cronjobs (extension/ezoracle/cronjobs). Every check or action writes lines
 * "PASS ...", "WARN ...", "FAIL ...", "INFO ..." through line(); failed()
 * tells whether any FAIL was written.
 *
 * Views a plain schema owner can read (USER_*) are used where they suffice;
 * V$ and DBA_ views need SELECT_CATALOG_ROLE (or SELECT ANY DICTIONARY), and a
 * check that cannot read them says so instead of failing.
 */
class eZOracleTools
{
    /** @var eZOracleDB */
    public $db;
    /** @var array list of array( status, message ) */
    public $lines = array();
    /** @var callable|null receives each line as it is written */
    public $printer = null;
    /** @var bool no changes, only what would be done */
    public $dryRun = false;

    /**
     * @param eZDBInterface|null $db the installation's database (eZDB::instance())
     * @param callable|null $printer function( $status, $message )
     */
    public function __construct( $db = null, $printer = null )
    {
        $this->db = $db ? $db : eZDB::instance();
        $this->printer = $printer;
    }

    /**
     * The common part of the console commands in extension/ezoracle/bin/php:
     * eZScript with --help and the standard options (-s <siteaccess> picks the
     * database settings, --allow-root-user), [dry-run] for those that change
     * something, the tools on the installation's database, PASS/FAIL lines,
     * and exit status 1 when a check failed.
     *
     * @param string $description shown by --help
     * @param string $optionSpec eZScript option spec, e.g. "[fix][dry-run]"
     * @param array $optionHelp option => help text
     * @param callable $work function( eZOracleTools $tools, array $options, eZCLI $cli ): bool
     */
    public static function runScript( $description, $optionSpec, array $optionHelp, $work )
    {
        $cli = eZCLI::instance();
        $script = eZScript::instance( array( 'description' => $description,
                                             'use-session' => false,
                                             'use-modules' => false,
                                             'use-extensions' => true ) );
        $script->startup();
        $options = $script->getOptions( $optionSpec, '', $optionHelp );
        $script->initialize();
        $tools = new eZOracleTools( eZDB::instance(), function ( $status, $message ) use ( $cli )
        {
            $cli->output( $status . ' ' . $message );
        } );
        if ( !empty( $options['dry-run'] ) )
        {
            $tools->dryRun = true;
        }
        $ok = $tools->isOracle() ? (bool)call_user_func( $work, $tools, $options, $cli ) : $tools->requireOracle();
        $cli->output( ( $ok && !$tools->failed() ? 'PASS' : 'FAIL' ) . ' ' . basename( $_SERVER['argv'][0] ?? 'ezoracle' ) . ( $tools->warned() && !$tools->failed() ? ' (with warnings)' : '' ) );
        $script->shutdown( $ok && !$tools->failed() ? 0 : 1 );
    }

    /**
     * The tools for a cronjob script, or null when the job is not enabled in
     * ezoracle.ini [CronjobSettings] $setting or the database is not Oracle
     * (the cronjob part can then stay in a shared runcronjobs list).
     *
     * @param string $setting GatherStatistics | HealthMonitor | PurgeRecycleBin | SequenceCheck
     * @param eZCLI|null $cli
     * @return eZOracleTools|null
     */
    public static function forCronjob( $setting, $cli = null )
    {
        $cli = $cli ? $cli : eZCLI::instance();
        $ini = eZINI::instance( 'ezoracle.ini' );
        if ( !$ini->hasVariable( 'CronjobSettings', $setting ) || $ini->variable( 'CronjobSettings', $setting ) !== 'enabled' )
        {
            $cli->output( "ezoracle: $setting is disabled (ezoracle.ini [CronjobSettings]), nothing done" );
            return null;
        }
        $tools = new eZOracleTools( eZDB::instance(), function ( $status, $message ) use ( $cli )
        {
            $cli->output( $status . ' ' . $message );
        } );
        if ( !$tools->isOracle() )
        {
            $cli->output( 'ezoracle: the database is not Oracle, nothing done' );
            return null;
        }
        return $tools;
    }

    /**
     * Ends a cronjob: the report into var/log/ezoracle-<job>.log, and with
     * $mail, when a check warned or failed, to [CronjobSettings] HealthMailReceivers[].
     */
    public function finishCronjob( $job, $mail = false )
    {
        $status = $this->failed() ? 'FAIL' : ( $this->warned() ? 'WARN' : 'PASS' );
        $log = 'ezoracle-' . preg_replace( '/[^a-z0-9]+/', '-', strtolower( $job ) ) . '.log';
        eZLog::write( "$status\n" . rtrim( $this->text() ), $log );
        if ( !$mail || $status === 'PASS' )
        {
            return;
        }
        $receivers = array_filter( (array)$this->ini( 'CronjobSettings', 'HealthMailReceivers', array() ), 'strlen' );
        if ( !$receivers )
        {
            return;
        }
        $siteINI = eZINI::instance();
        $mailObj = new eZMail();
        $mailObj->setSender( $siteINI->variable( 'MailSettings', 'EmailSender' ) ?: $siteINI->variable( 'MailSettings', 'AdminEmail' ) );
        foreach ( $receivers as $receiver )
        {
            $mailObj->addReceiver( $receiver );
        }
        $mailObj->setSubject( "[$status] Oracle $job of " . $siteINI->variable( 'SiteSettings', 'SiteName' ) );
        $mailObj->setBody( $this->text() );
        $sent = eZMailTransport::send( $mailObj );
        eZLog::write( ( $sent ? 'mailed to ' : 'could not mail to ' ) . implode( ', ', $receivers ), $log );
    }

    /** True when the database is an Oracle one handled by eZOracleDB. */
    public function isOracle()
    {
        return is_object( $this->db ) && $this->db->databaseName() === 'oracle' && $this->db->isConnected();
    }

    public function line( $status, $message )
    {
        $this->lines[] = array( $status, $message );
        if ( $this->printer )
        {
            call_user_func( $this->printer, $status, $message );
        }
        return $status !== 'FAIL';
    }

    public function failed()
    {
        foreach ( $this->lines as $l )
            if ( $l[0] === 'FAIL' )
                return true;
        return false;
    }

    public function warned()
    {
        foreach ( $this->lines as $l )
            if ( $l[0] === 'WARN' || $l[0] === 'FAIL' )
                return true;
        return false;
    }

    /** The report as text, one line per check. */
    public function text()
    {
        $text = '';
        foreach ( $this->lines as $l )
            $text .= $l[0] . ' ' . $l[1] . "\n";
        return $text;
    }

    /**
     * Rows of $sql, or null when the query fails (missing privilege, missing
     * view): the error goes to the debug log, not to the caller's output.
     */
    public function rows( $sql, $params = array() )
    {
        $rows = @$this->db->arrayQuery( $sql, $params );
        return is_array( $rows ) ? $rows : null;
    }

    public function value( $sql )
    {
        $rows = $this->rows( $sql );
        if ( !$rows || !isset( $rows[0] ) )
            return null;
        return current( $rows[0] );
    }

    /** Runs a statement (DDL, PL/SQL), unless dryRun; returns true on success. */
    public function run( $sql, $what )
    {
        if ( $this->dryRun )
        {
            $this->line( 'INFO', "dry run, not done: $what" );
            return true;
        }
        $ok = @$this->db->query( $sql );
        if ( !$ok )
            $this->line( 'FAIL', "$what: " . $this->db->errorMessage() );
        return (bool)$ok;
    }

    /** PASS for an action that was carried out; nothing in a dry run (run() said what it would do). */
    public function done( $message )
    {
        return $this->dryRun ? true : $this->line( 'PASS', $message );
    }

    protected function ini( $section, $name, $default )
    {
        $ini = eZINI::instance( 'ezoracle.ini' );
        return $ini->hasVariable( $section, $name ) ? $ini->variable( $section, $name ) : $default;
    }

    public function requireOracle()
    {
        if ( !$this->isOracle() )
        {
            $this->line( 'FAIL', 'the database of this installation is not Oracle (' . ( is_object( $this->db ) ? $this->db->databaseName() : 'none' ) . ') or not connected' );
            return false;
        }
        return true;
    }

    // ------------------------------------------------------------------ health

    /**
     * Connectivity, versions, invalid objects, tablespace use, sessions,
     * blocking locks, recycle bin, the driver's oci8 settings.
     */
    public function health()
    {
        if ( !$this->requireOracle() )
            return false;
        $db = $this->db;
        $t0 = microtime( true );
        $one = $this->value( 'SELECT 1 FROM dual' );
        $this->line( $one == 1 ? 'PASS' : 'FAIL', sprintf( 'connected as %s to %s, round trip %.1f ms', $this->value( 'SELECT USER FROM dual' ), $db->connectString(), ( microtime( true ) - $t0 ) * 1000 ) );
        $v = $db->databaseServerVersion();
        $c = $db->databaseClientVersion();
        $this->line( $v ? 'PASS' : 'WARN', 'server ' . ( $v ? $v['string'] : '?' ) . ', client ' . ( $c ? $c['string'] : '?' ) . ', oci8 ' . phpversion( 'oci8' ) . ', PHP ' . PHP_VERSION );
        $cs = $db->databaseCharset();
        $this->line( in_array( $cs, array( 'AL32UTF8', 'UTF8' ) ) ? 'PASS' : 'WARN', "database character set $cs" . ( in_array( $cs, array( 'AL32UTF8', 'UTF8' ) ) ? '' : ' (not Unicode: text outside it is lost)' ) );

        // invalid objects
        $invalid = $this->rows( "SELECT object_type, object_name FROM user_objects WHERE status = 'INVALID' ORDER BY 1, 2" );
        $failAbove = (int)$this->ini( 'HealthSettings', 'InvalidObjectsFail', 0 );
        if ( $invalid === null )
            $this->line( 'WARN', 'invalid objects: user_objects not readable' );
        else if ( count( $invalid ) == 0 )
            $this->line( 'PASS', 'invalid objects: none' );
        else
            $this->line( $failAbove > 0 && count( $invalid ) > $failAbove ? 'FAIL' : 'WARN', 'invalid objects: ' . count( $invalid ) . ' (' . implode( ', ', array_slice( array_map( function( $r ) { return strtolower( $r['object_type'] . ' ' . $r['object_name'] ); }, $invalid ), 0, 10 ) ) . ') - ext:ezoracle:recompile compiles them' );

        // tablespaces: DBA view when readable, the user's quotas otherwise
        $warnPercent = (float)$this->ini( 'HealthSettings', 'TablespaceWarnPercent', 85 );
        $ts = $this->rows( 'SELECT m.tablespace_name AS name, ROUND( m.used_percent, 1 ) AS pct, ROUND( m.used_space * t.block_size / 1048576 ) AS used_mb, ROUND( m.tablespace_size * t.block_size / 1048576 ) AS max_mb ' .
                           'FROM dba_tablespace_usage_metrics m JOIN dba_tablespaces t ON t.tablespace_name = m.tablespace_name ' .
                           'WHERE m.tablespace_name IN ( SELECT default_tablespace FROM user_users UNION SELECT tablespace_name FROM user_segments ) ORDER BY 1' );
        if ( $ts !== null )
        {
            foreach ( $ts as $r )
                $this->line( (float)$r['pct'] >= $warnPercent ? 'WARN' : 'PASS', sprintf( 'tablespace %s %s%% used (%s of %s MB)', $r['name'], $r['pct'], $r['used_mb'], $r['max_mb'] ) );
        }
        else
        {
            $q = $this->rows( 'SELECT tablespace_name AS name, ROUND( bytes / 1048576 ) AS used_mb, DECODE( max_bytes, -1, NULL, ROUND( max_bytes / 1048576 ) ) AS max_mb FROM user_ts_quotas ORDER BY 1' );
            foreach ( (array)$q as $r )
            {
                $pct = $r['max_mb'] ? 100 * $r['used_mb'] / $r['max_mb'] : 0;
                $this->line( $pct >= $warnPercent ? 'WARN' : 'PASS', sprintf( 'tablespace %s: %s MB used by this schema, quota %s', $r['name'], $r['used_mb'], $r['max_mb'] ? $r['max_mb'] . ' MB' : 'unlimited' ) );
            }
            $this->line( 'INFO', 'tablespace fill level: dba_tablespace_usage_metrics not readable (SELECT_CATALOG_ROLE), quotas shown instead' );
        }
        $size = $this->value( 'SELECT ROUND( SUM( bytes ) / 1048576 ) FROM user_segments' );
        $this->line( 'INFO', "segments of this schema: $size MB" );

        // sessions and locks
        $sessions = $this->rows( "SELECT status, COUNT(*) AS n FROM v\$session WHERE username = USER GROUP BY status" );
        if ( $sessions === null )
            $this->line( 'INFO', 'sessions: v$session not readable (SELECT_CATALOG_ROLE)' );
        else
            $this->line( 'PASS', 'sessions of this user: ' . implode( ', ', array_map( function( $r ) { return strtolower( $r['status'] ) . ' ' . $r['n']; }, $sessions ) ) );
        $wait = (int)$this->ini( 'HealthSettings', 'LockWaitSeconds', 30 );
        $blocked = $this->rows( "SELECT sid, blocking_session, seconds_in_wait, event FROM v\$session WHERE blocking_session IS NOT NULL AND seconds_in_wait >= $wait" );
        if ( $blocked === null )
            $this->line( 'INFO', 'blocking locks: v$session not readable' );
        else if ( count( $blocked ) == 0 )
            $this->line( 'PASS', "blocked sessions (>= {$wait}s): none" );
        else
            $this->line( 'WARN', 'blocked sessions: ' . implode( '; ', array_map( function( $r ) { return "sid {$r['sid']} blocked by {$r['blocking_session']} for {$r['seconds_in_wait']}s ({$r['event']})"; }, $blocked ) ) );

        $bin = $this->value( 'SELECT COUNT(*) FROM user_recyclebin' );
        $this->line( $bin > 1000 ? 'WARN' : 'PASS', "recycle bin: $bin objects" . ( $bin > 0 ? ' (ext:ezoracle:purge-recyclebin empties it)' : '' ) );

        // sequences behind their tables
        $behind = 0;
        foreach ( $this->sequenceStatus() as $s )
            if ( $s['behind'] ) $behind++;
        $this->line( $behind ? 'WARN' : 'PASS', "auto-increment sequences behind their tables: $behind" . ( $behind ? ' (ext:ezoracle:sequence-sync --fix)' : '' ) );

        // statistics
        $stale = $this->value( "SELECT COUNT(*) FROM user_tab_statistics WHERE stale_stats = 'YES' OR last_analyzed IS NULL" );
        $this->line( $stale > 0 ? 'WARN' : 'PASS', "tables with stale or missing optimizer statistics: $stale" . ( $stale ? ' (ext:ezoracle:gather-stats)' : '' ) );

        // driver and oci8 settings
        $this->line( 'INFO', sprintf( 'driver: persistent %s, DRCP %s, call timeout %s ms, keep-alive %s s, prefetch %s, statement cache %s, reconnects so far %d',
            $db->Persistent ? 'yes' : 'no', $db->DRCP ? 'yes' : 'no', $db->CallTimeout, $db->KeepAliveInterval,
            $db->Prefetch ? $db->Prefetch : ini_get( 'oci8.default_prefetch' ), ini_get( 'oci8.statement_cache_size' ), $db->ReconnectCount ) );
        return !$this->failed();
    }

    // -------------------------------------------------------------- statistics

    /** DBMS_STATS.GATHER_SCHEMA_STATS with the [StatisticsSettings] options. */
    public function gatherStats()
    {
        if ( !$this->requireOracle() )
            return false;
        $estimate = strtoupper( (string)$this->ini( 'StatisticsSettings', 'EstimatePercent', 'AUTO' ) );
        $estimate = $estimate === 'AUTO' ? 'DBMS_STATS.AUTO_SAMPLE_SIZE' : (string)max( 0.000001, min( 100, (float)$estimate ) );
        $degree = (int)$this->ini( 'StatisticsSettings', 'Degree', 0 );
        $cascade = $this->ini( 'StatisticsSettings', 'Cascade', 'enabled' ) === 'enabled' ? 'TRUE' : 'FALSE';
        $options = strtoupper( (string)$this->ini( 'StatisticsSettings', 'Options', 'GATHER AUTO' ) );
        if ( !in_array( $options, array( 'GATHER', 'GATHER AUTO', 'GATHER STALE', 'GATHER EMPTY' ) ) )
            $options = 'GATHER AUTO';
        $t0 = microtime( true );
        $sql = "BEGIN DBMS_STATS.GATHER_SCHEMA_STATS( ownname => USER, estimate_percent => $estimate, cascade => $cascade, options => '$options'" .
               ( $degree > 0 ? ", degree => $degree" : '' ) . " ); END;";
        if ( !$this->run( $sql, 'gather schema statistics' ) )
            return false;
        $stale = $this->value( "SELECT COUNT(*) FROM user_tab_statistics WHERE stale_stats = 'YES' OR last_analyzed IS NULL" );
        return $this->done( sprintf( 'statistics gathered (%s, estimate %s) in %.1f s; stale or missing now: %s', $options, $estimate, microtime( true ) - $t0, $stale ) );
    }

    // ----------------------------------------------------------------- compile

    /** Compiles the invalid objects of the schema (DBMS_UTILITY.COMPILE_SCHEMA). */
    public function recompile()
    {
        if ( !$this->requireOracle() )
            return false;
        $before = (int)$this->value( "SELECT COUNT(*) FROM user_objects WHERE status = 'INVALID'" );
        if ( $before == 0 )
            return $this->line( 'PASS', 'no invalid objects' );
        if ( !$this->run( 'BEGIN DBMS_UTILITY.COMPILE_SCHEMA( schema => USER, compile_all => FALSE ); END;', 'compile the schema' ) )
            return false;
        $still = $this->rows( "SELECT object_type, object_name FROM user_objects WHERE status = 'INVALID'" );
        if ( $still )
            return $this->line( 'FAIL', "invalid before: $before, still invalid: " . implode( ', ', array_map( function( $r ) { return strtolower( $r['object_type'] . ' ' . $r['object_name'] ); }, $still ) ) );
        return $this->done( "compiled $before invalid object(s), none left" );
    }

    // ------------------------------------------------------------- recycle bin

    public function purgeRecycleBin()
    {
        if ( !$this->requireOracle() )
            return false;
        $n = (int)$this->value( 'SELECT COUNT(*) FROM user_recyclebin' );
        $mb = $this->value( "SELECT ROUND( NVL( SUM( r.space * t.block_size ), 0 ) / 1048576, 1 ) FROM user_recyclebin r LEFT JOIN user_tablespaces t ON t.tablespace_name = r.ts_name" );
        if ( $n == 0 )
            return $this->line( 'PASS', 'recycle bin is empty' );
        if ( !$this->run( 'PURGE RECYCLEBIN', 'purge the recycle bin' ) )
            return false;
        return $this->done( "purged $n object(s) from the recycle bin" . ( $mb !== null ? " ($mb MB)" : '' ) );
    }

    // --------------------------------------------------------------- sequences

    /**
     * The auto-increment sequences (from the BEFORE INSERT triggers) with the
     * highest value of their column and whether the sequence is behind it.
     *
     * @return array of array( sequence, table, column, max, last_number, cache, behind )
     */
    public function sequenceStatus()
    {
        $list = array();
        foreach ( (array)$this->rows( "SELECT table_name, trigger_name, trigger_body FROM user_triggers WHERE table_name NOT LIKE 'BIN$%'" ) as $row )
        {
            if ( !is_string( $row['trigger_body'] ) || !preg_match( '/SELECT\s+(\w+)\.nextval\s+INTO\s+:new\.(\w+)\s+FROM\s+dual/i', $row['trigger_body'], $m ) )
                continue;
            $seq = strtoupper( $m[1] );
            $col = strtolower( $m[2] );
            $table = strtolower( $row['table_name'] );
            $s = $this->rows( "SELECT last_number, cache_size, increment_by FROM user_sequences WHERE sequence_name = '" . $this->db->escapeString( $seq ) . "'" );
            if ( !$s )
            {
                $list[] = array( 'sequence' => strtolower( $seq ), 'table' => $table, 'column' => $col, 'max' => null, 'last_number' => null, 'cache' => 0, 'behind' => true, 'missing' => true );
                continue;
            }
            $max = (int)$this->value( "SELECT NVL( MAX( $col ), 0 ) FROM $table" );
            // LAST_NUMBER is the first value not yet handed to the session caches
            // (the next value itself, for NOCACHE sequences). A column at or above
            // it collides for certain; within the CACHE values below it, it may
            // (reading the current value would use up a value, so it is not done)
            $list[] = array( 'sequence' => strtolower( $seq ), 'table' => $table, 'column' => $col, 'max' => $max,
                             'last_number' => (int)$s[0]['last_number'], 'cache' => (int)$s[0]['cache_size'],
                             'behind' => $max >= (int)$s[0]['last_number'], 'missing' => false );
        }
        return $list;
    }

    /** Reports the sequences behind their tables and, with $fix, moves them past the data. */
    public function sequenceSync( $fix = false )
    {
        if ( !$this->requireOracle() )
            return false;
        $status = $this->sequenceStatus();
        $behind = array_filter( $status, function( $s ) { return $s['behind']; } );
        $this->line( 'INFO', count( $status ) . ' auto-increment sequence(s), ' . count( $behind ) . ' behind or possibly behind their table' );
        foreach ( $behind as $s )
            $this->line( $fix ? 'INFO' : 'WARN', $s['missing'] ? "{$s['sequence']} of {$s['table']}.{$s['column']} does not exist" :
                         "{$s['sequence']}: {$s['table']}.{$s['column']} max {$s['max']}, sequence at {$s['last_number']} (cache {$s['cache']})" );
        if ( !$fix || !$behind )
            return count( $behind ) == 0 ? $this->line( 'PASS', 'every sequence is ahead of its table' ) : !$this->failed();
        if ( $this->dryRun )
            return $this->line( 'INFO', 'dry run: the sequences were not changed' );
        $ok = $this->db->correctSequenceValues();
        $left = array_filter( $this->sequenceStatus(), function( $s ) { return $s['behind']; } );
        return $this->line( $ok && !$left ? 'PASS' : 'FAIL', $ok && !$left ? 'sequences moved past the data' : 'sequences could not all be corrected: ' . count( $left ) . ' left' );
    }

    // ------------------------------------------------------------- schema diff

    /**
     * Compares the schema in the database with a .dba file (the kernel's
     * share/db_schema.dba by default), both in the Oracle form.
     *
     * @param string $file
     * @param bool $onlyFileTables leave out tables the file does not define (other extensions)
     */
    public function schemaDiff( $file = 'share/db_schema.dba', $onlyFileTables = true )
    {
        if ( !$this->requireOracle() )
            return false;
        if ( !is_file( $file ) )
            return $this->line( 'FAIL', "$file not found" );
        $read = eZDbSchema::read( $file, true );
        if ( !is_array( $read ) || !isset( $read['schema'] ) )
            return $this->line( 'FAIL', "$file is not a schema file" );
        $fileSchema = $read['schema'];
        $tables = array_values( array_diff( array_keys( $fileSchema ), array( '_info' ) ) );
        $live = new eZOracleSchema( array( 'instance' => $this->db ) );
        $liveSchema = $live->schema( array( 'format' => 'local', 'table_include' => $onlyFileTables ? $tables : null ) );
        if ( !$onlyFileTables )
            $liveSchema = $live->schema( array( 'format' => 'local' ) );
        $handler = new eZOracleSchema( array( 'instance' => $this->db, 'schema' => $fileSchema ) );
        $local = $handler->schema( array( 'format' => 'local' ) );
        $diff = eZDbSchemaChecker::diff( $liveSchema, $local, 'oracle', 'oracle' );
        $n = 0;
        foreach ( array( 'new_tables' => 'missing in the database', 'removed_tables' => 'not in the file' ) as $key => $what )
            foreach ( isset( $diff[$key] ) ? array_keys( $diff[$key] ) : array() as $t )
            {
                $this->line( 'WARN', "table $t: $what" );
                $n++;
            }
        foreach ( isset( $diff['table_changes'] ) ? $diff['table_changes'] : array() as $t => $changes )
            foreach ( $changes as $kind => $items )
                foreach ( $items as $name => $d )
                {
                    $this->line( 'WARN', "table $t: " . str_replace( '_', ' ', $kind ) . " $name" . ( isset( $d['different-options'] ) ? ' (' . implode( ', ', $d['different-options'] ) . ')' : '' ) );
                    $n++;
                }
        if ( $n > 0 )
        {
            $sql = $handler->generateUpgradeFile( $diff );
            $this->line( 'INFO', "the SQL that would bring the database to the file:\n" . $sql );
        }
        return $this->line( $n ? 'FAIL' : 'PASS', sprintf( '%d difference(s) between the database and %s (%d tables compared)', $n, $file, count( $tables ) ) );
    }

    // ----------------------------------------------------------------- indexes

    /**
     * report: unusable indexes, size and statistics per index;
     * rebuild: rebuild the unusable ones (or $name);
     * monitor-on / monitor-off: index usage monitoring; usage: what was used.
     */
    public function indexes( $action = 'report', $name = '', $online = false )
    {
        if ( !$this->requireOracle() )
            return false;
        switch ( $action )
        {
            case 'report':
                $unusable = $this->rows( "SELECT index_name, table_name FROM user_indexes WHERE status = 'UNUSABLE' UNION ALL SELECT index_name, partition_name FROM user_ind_partitions WHERE status = 'UNUSABLE'" );
                $this->line( $unusable ? 'WARN' : 'PASS', 'unusable indexes: ' . ( $unusable ? implode( ', ', array_map( function( $r ) { return strtolower( $r['index_name'] ); }, $unusable ) ) . ' (ext:ezoracle:indexes --rebuild)' : 'none' ) );
                $big = $this->rows( "SELECT i.index_name, i.table_name, i.blevel, i.leaf_blocks, i.distinct_keys, i.clustering_factor, TO_CHAR( i.last_analyzed, 'YYYY-MM-DD' ) AS analyzed, ROUND( s.bytes / 1048576, 1 ) AS mb " .
                                    "FROM user_indexes i LEFT JOIN user_segments s ON s.segment_name = i.index_name WHERE i.index_type <> 'LOB' ORDER BY s.bytes DESC NULLS LAST", array( 'limit' => 20 ) );
                foreach ( (array)$big as $r )
                    $this->line( 'INFO', sprintf( '%-32s on %-28s %6s MB, blevel %s, leaf blocks %s, distinct keys %s, clustering %s, analyzed %s',
                        strtolower( $r['index_name'] ), strtolower( $r['table_name'] ), $r['mb'] === null ? '-' : $r['mb'], $r['blevel'], $r['leaf_blocks'], $r['distinct_keys'], $r['clustering_factor'], $r['analyzed'] ? $r['analyzed'] : 'never' ) );
                return !$this->failed();
            case 'rebuild':
                $names = $name !== '' ? array( strtoupper( $name ) ) : array_map( function( $r ) { return $r['index_name']; }, (array)$this->rows( "SELECT index_name FROM user_indexes WHERE status = 'UNUSABLE'" ) );
                if ( !$names )
                    return $this->line( 'PASS', 'nothing to rebuild' );
                foreach ( $names as $n )
                {
                    if ( !preg_match( '/^[A-Z0-9_$#]+$/', $n ) )
                        return $this->line( 'FAIL', "not an index name: $n" );
                    if ( $this->run( "ALTER INDEX $n REBUILD" . ( $online ? ' ONLINE' : '' ), 'rebuild ' . strtolower( $n ) ) )
                        $this->done( 'rebuilt ' . strtolower( $n ) );
                }
                return !$this->failed();
            case 'monitor-on':
            case 'monitor-off':
                $n = 0;
                foreach ( (array)$this->rows( "SELECT index_name FROM user_indexes WHERE index_type NOT IN ( 'LOB', 'IOT - TOP' ) AND index_name NOT LIKE 'SYS%'" ) as $r )
                    if ( $this->run( 'ALTER INDEX ' . $r['index_name'] . ( $action === 'monitor-on' ? ' MONITORING USAGE' : ' NOMONITORING USAGE' ), 'monitoring of ' . strtolower( $r['index_name'] ) ) )
                        $n++;
                return $this->done( ( $action === 'monitor-on' ? 'usage monitoring switched on for ' : 'usage monitoring switched off for ' ) . "$n index(es)" );
            case 'usage':
                // 12.2+: DBA_INDEX_USAGE (tracked by default); older: USER_OBJECT_USAGE with monitoring
                $used = $this->rows( 'SELECT u.name AS index_name, u.total_access_count AS accesses, TO_CHAR( u.last_used, \'YYYY-MM-DD\' ) AS last_used FROM dba_index_usage u WHERE u.owner = USER ORDER BY u.total_access_count DESC' );
                if ( $used === null )
                {
                    $used = $this->rows( "SELECT index_name, used AS accesses, end_monitoring AS last_used FROM user_object_usage ORDER BY used DESC" );
                    $this->line( 'INFO', 'dba_index_usage not readable: user_object_usage (after --monitor-on)' );
                }
                $all = array_map( function( $r ) { return $r['index_name']; }, (array)$this->rows( "SELECT index_name FROM user_indexes WHERE index_type <> 'LOB' AND uniqueness = 'NONUNIQUE'" ) );
                $seen = array_map( function( $r ) { return $r['index_name']; }, (array)$used );
                foreach ( (array)$used as $r )
                    $this->line( 'INFO', sprintf( '%-32s accesses %s, last used %s', strtolower( $r['index_name'] ), $r['accesses'], $r['last_used'] ) );
                $unused = array_diff( $all, $seen );
                return $this->line( 'INFO', count( $unused ) . ' non-unique index(es) without recorded use: ' . implode( ', ', array_slice( array_map( 'strtolower', $unused ), 0, 30 ) ) );
        }
        return $this->line( 'FAIL', "unknown index action $action (report, rebuild, monitor-on, monitor-off, usage)" );
    }

    // ----------------------------------------------------------------- reports

    /** Largest tables (with their indexes and LOBs) of the schema. */
    public function tableSizes( $top = 20 )
    {
        if ( !$this->requireOracle() )
            return false;
        // segments to their table in PHP: joining the dictionary views with OR
        // takes seconds on a schema of a few hundred tables
        $owner = array();
        foreach ( (array)$this->rows( 'SELECT index_name, table_name FROM user_indexes' ) as $r )
            $owner[$r['index_name']] = $r['table_name'];
        foreach ( (array)$this->rows( 'SELECT table_name, segment_name, index_name FROM user_lobs' ) as $r )
        {
            $owner[$r['segment_name']] = $r['table_name'];
            $owner[$r['index_name']] = $r['table_name'];
        }
        $sizes = array();
        foreach ( (array)$this->rows( 'SELECT segment_name, segment_type, bytes FROM user_segments' ) as $r )
        {
            $table = isset( $owner[$r['segment_name']] ) ? $owner[$r['segment_name']] : $r['segment_name'];
            $kind = strpos( $r['segment_type'], 'LOB' ) === 0 ? 'lob' : ( strpos( $r['segment_type'], 'INDEX' ) === 0 ? 'index' : 'data' );
            if ( !isset( $sizes[$table] ) )
                $sizes[$table] = array( 'all' => 0, 'data' => 0, 'index' => 0, 'lob' => 0 );
            $sizes[$table]['all'] += $r['bytes'];
            $sizes[$table][$kind] += $r['bytes'];
        }
        uasort( $sizes, function( $a, $b ) { return $b['all'] <=> $a['all']; } );
        $numRows = array();
        foreach ( (array)$this->rows( 'SELECT table_name, num_rows FROM user_tables' ) as $r )
            $numRows[$r['table_name']] = $r['num_rows'];
        $mb = function( $bytes ) { return round( $bytes / 1048576, 1 ); };
        foreach ( array_slice( $sizes, 0, (int)$top, true ) as $table => $s )
            $this->line( 'INFO', sprintf( '%-34s %8s MB (data %s, indexes %s, LOBs %s), rows %s', strtolower( $table ), $mb( $s['all'] ), $mb( $s['data'] ), $mb( $s['index'] ), $mb( $s['lob'] ),
                                          isset( $numRows[$table] ) && $numRows[$table] !== null && $numRows[$table] !== '' ? $numRows[$table] : '? (no statistics)' ) );
        return $this->line( 'PASS', 'schema size ' . $this->value( 'SELECT ROUND( SUM( bytes ) / 1048576 ) FROM user_segments' ) . ' MB' );
    }

    /** The statements of this schema with the most elapsed time, from V$SQL. */
    public function topSql( $top = 15 )
    {
        if ( !$this->requireOracle() )
            return false;
        $rows = $this->rows( "SELECT sql_id, executions, ROUND( elapsed_time / 1000 ) AS elapsed_ms, ROUND( elapsed_time / NULLIF( executions, 0 ) / 1000, 2 ) AS per_exec_ms, " .
                             "buffer_gets, rows_processed, module, action, SUBSTR( sql_text, 1, 160 ) AS sql_text FROM v\$sql WHERE parsing_schema_name = USER ORDER BY elapsed_time DESC", array( 'limit' => (int)$top ) );
        if ( $rows === null )
            return $this->line( 'WARN', 'v$sql not readable: grant SELECT_CATALOG_ROLE (or SELECT ON v_$sql) to this user for the top SQL report' );
        foreach ( $rows as $r )
            $this->line( 'INFO', sprintf( '%s %8s ms in %s runs (%s ms each), %s gets, %s rows [%s %s] %s', $r['sql_id'], $r['elapsed_ms'], $r['executions'], $r['per_exec_ms'], $r['buffer_gets'], $r['rows_processed'], $r['module'], $r['action'], preg_replace( '/\s+/', ' ', $r['sql_text'] ) ) );
        return $this->line( 'PASS', count( $rows ) . ' statement(s) listed' );
    }

    // --------------------------------------------------------------- data pump

    /**
     * Export or import of the schema through DBMS_DATAPUMP, which runs on the
     * database server and writes to/reads from a directory object there (no
     * expdp/impdp binary is needed on the web server: the Instant Client has none).
     *
     * @param string $mode export | import
     * @param string $dumpFile file name in the directory object
     * @param array $options table_exists_action (SKIP, APPEND, TRUNCATE, REPLACE), remap_schema (FROM:TO)
     */
    public function dataPump( $mode, $dumpFile, $options = array() )
    {
        if ( !$this->requireOracle() )
            return false;
        $dir = strtoupper( (string)$this->ini( 'DataPumpSettings', 'Directory', 'DATA_PUMP_DIR' ) );
        if ( !preg_match( '/^[A-Z][A-Z0-9_$#]*$/', $dir ) || !preg_match( '/^[A-Za-z0-9_.-]+$/', $dumpFile ) )
            return $this->line( 'FAIL', 'the directory object and the dump file name may hold letters, digits, _ . - only' );
        $path = $this->value( "SELECT directory_path FROM all_directories WHERE directory_name = '$dir'" );
        if ( $path === null )
            return $this->line( 'FAIL', "directory object $dir is not visible to this user (GRANT READ, WRITE ON DIRECTORY $dir TO <user>)" );
        $log = preg_replace( '/\.dmp$/i', '', $dumpFile ) . '.' . $mode . '.log';
        $job = 'EZORA_' . strtoupper( substr( $mode, 0, 3 ) ) . '_' . date( 'YmdHis' );
        if ( $mode === 'export' )
        {
            $plsql = "DECLARE h NUMBER; s VARCHAR2(30); BEGIN\n" .
                     "  h := DBMS_DATAPUMP.OPEN( operation => 'EXPORT', job_mode => 'SCHEMA', job_name => '$job' );\n" .
                     "  DBMS_DATAPUMP.ADD_FILE( h, '$dumpFile', '$dir', reusefile => 1 );\n" .
                     "  DBMS_DATAPUMP.ADD_FILE( h, '$log', '$dir', filetype => DBMS_DATAPUMP.KU\$_FILE_TYPE_LOG_FILE, reusefile => 1 );\n" .
                     "  DBMS_DATAPUMP.METADATA_FILTER( h, 'SCHEMA_EXPR', 'IN (''' || USER || ''')' );\n" .
                     "  DBMS_DATAPUMP.START_JOB( h );\n" .
                     "  DBMS_DATAPUMP.WAIT_FOR_JOB( h, s );\n" .
                     "  IF s <> 'COMPLETED' THEN RAISE_APPLICATION_ERROR( -20001, 'Data Pump job ended ' || s ); END IF;\n" .
                     "END;";
        }
        else if ( $mode === 'import' )
        {
            $action = strtoupper( isset( $options['table_exists_action'] ) ? $options['table_exists_action'] : 'SKIP' );
            if ( !in_array( $action, array( 'SKIP', 'APPEND', 'TRUNCATE', 'REPLACE' ) ) )
                return $this->line( 'FAIL', "table_exists_action must be SKIP, APPEND, TRUNCATE or REPLACE" );
            $remap = '';
            if ( !empty( $options['remap_schema'] ) )
            {
                list( $from, $to ) = array_pad( explode( ':', strtoupper( $options['remap_schema'] ), 2 ), 2, '' );
                if ( !preg_match( '/^[A-Z][A-Z0-9_$#]*$/', $from ) || !preg_match( '/^[A-Z][A-Z0-9_$#]*$/', $to ) )
                    return $this->line( 'FAIL', 'remap_schema is FROM:TO' );
                $remap = "  DBMS_DATAPUMP.METADATA_REMAP( h, 'REMAP_SCHEMA', '$from', '$to' );\n";
            }
            $plsql = "DECLARE h NUMBER; s VARCHAR2(30); BEGIN\n" .
                     "  h := DBMS_DATAPUMP.OPEN( operation => 'IMPORT', job_mode => 'SCHEMA', job_name => '$job' );\n" .
                     "  DBMS_DATAPUMP.ADD_FILE( h, '$dumpFile', '$dir' );\n" .
                     "  DBMS_DATAPUMP.ADD_FILE( h, '$log', '$dir', filetype => DBMS_DATAPUMP.KU\$_FILE_TYPE_LOG_FILE, reusefile => 1 );\n" .
                     $remap .
                     "  DBMS_DATAPUMP.SET_PARAMETER( h, 'TABLE_EXISTS_ACTION', '$action' );\n" .
                     "  DBMS_DATAPUMP.START_JOB( h );\n" .
                     "  DBMS_DATAPUMP.WAIT_FOR_JOB( h, s );\n" .
                     "  IF s <> 'COMPLETED' THEN RAISE_APPLICATION_ERROR( -20001, 'Data Pump job ended ' || s ); END IF;\n" .
                     "END;";
        }
        else
            return $this->line( 'FAIL', "mode must be export or import" );
        $t0 = microtime( true );
        // Data Pump runs for minutes: no call timeout for this round trip
        if ( $this->db->CallTimeout > 0 && function_exists( 'oci_set_call_timeout' ) )
            oci_set_call_timeout( $this->db->DBConnection, 0 );
        $ok = $this->run( $plsql, "Data Pump $mode" );
        if ( $this->db->CallTimeout > 0 && function_exists( 'oci_set_call_timeout' ) )
            oci_set_call_timeout( $this->db->DBConnection, $this->db->CallTimeout );
        if ( !$ok )
            return false;
        return $this->done( sprintf( 'Data Pump %s of schema %s: %s/%s (log %s), %.1f s', $mode, $this->value( 'SELECT USER FROM dual' ), $path, $dumpFile, $log, microtime( true ) - $t0 ) );
    }

    // ------------------------------------------------------ case-insensitivity

    /**
     * Linguistic indexes (NLSSORT with the sort of OracleCaseInsensitiveSort)
     * for the columns of ezoracle.ini [CaseInsensitiveSettings] Columns[], so
     * NLS_COMP=LINGUISTIC comparisons can still use an index.
     *
     * @param string $action list | create | drop
     */
    public function caseInsensitiveIndexes( $action = 'list' )
    {
        if ( !$this->requireOracle() )
            return false;
        $sort = $this->db->CaseInsensitiveSort;
        $columns = (array)$this->ini( 'CaseInsensitiveSettings', 'Columns', array() );
        $existingTables = array_flip( (array)$this->db->relationList() );
        foreach ( $columns as $entry )
        {
            if ( !preg_match( '/^([a-z0-9_]+)\.([a-z0-9_]+)$/i', trim( $entry ), $m ) )
            {
                $this->line( 'WARN', "not table.column: $entry" );
                continue;
            }
            list( , $table, $column ) = $m;
            $table = strtolower( $table );
            $column = strtolower( $column );
            $index = eZOracleSchema::shorten( $table . '_' . $column . '_' . strtolower( $sort ), 30 );
            if ( !isset( $existingTables[$table] ) )
            {
                $this->line( 'INFO', "$table.$column: no such table here, skipped" );
                continue;
            }
            $colType = $this->value( "SELECT data_type FROM user_tab_columns WHERE table_name = '" . strtoupper( $table ) . "' AND column_name = '" . strtoupper( $column ) . "'" );
            if ( $colType === null )
            {
                $this->line( 'INFO', "$table.$column: no such column, skipped" );
                continue;
            }
            if ( $colType === 'CLOB' )
            {
                $this->line( 'WARN', "$table.$column is a CLOB: it cannot be indexed" );
                continue;
            }
            $exists = (int)$this->value( "SELECT COUNT(*) FROM user_indexes WHERE index_name = '" . strtoupper( $index ) . "'" ) > 0;
            if ( $action === 'list' )
            {
                $this->line( $exists ? 'PASS' : 'INFO', "$index on $table( NLSSORT( $column, 'NLS_SORT=$sort' ) ): " . ( $exists ? 'exists' : 'missing' ) );
            }
            else if ( $action === 'create' )
            {
                if ( $exists )
                    $this->line( 'PASS', "$index exists" );
                else if ( $this->run( "CREATE INDEX $index ON $table ( NLSSORT( $column, 'NLS_SORT=$sort' ) )", "create $index" ) )
                    $this->done( "created $index on $table( NLSSORT( $column, 'NLS_SORT=$sort' ) )" );
            }
            else if ( $action === 'drop' )
            {
                if ( !$exists )
                    $this->line( 'PASS', "$index does not exist" );
                else if ( $this->run( "DROP INDEX $index", "drop $index" ) )
                    $this->done( "dropped $index" );
            }
            else
                return $this->line( 'FAIL', "unknown action $action (list, create, drop)" );
        }
        if ( !$this->db->CaseInsensitive )
            $this->line( 'INFO', 'site.ini [DatabaseSettings] OracleCaseInsensitive is disabled: these indexes only help once it is enabled' );
        return !$this->failed();
    }
}
?>
