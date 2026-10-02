<?php
//
// Definition of eZOracleDB class
//
// Created on: <25-Feb-2002 14:50:11 ce>
//
// Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
// Copyright (C) 1999-2013 eZ Systems as. All rights reserved.
//
// This source file is part of the eZ Publish (tm) Open Source Content
// Management System.
//
// This file may be distributed and/or modified under the terms of the
// "GNU General Public License" version 2 as published by the Free
// Software Foundation and appearing in the file LICENSE.GPL included in
// the packaging of this file.
//
// Licencees holding valid "eZ Publish professional licences" may use this
// file in accordance with the "eZ Publish professional licence" Agreement
// provided with the Software.
//
// This file is provided AS IS with NO WARRANTY OF ANY KIND, INCLUDING
// THE WARRANTY OF DESIGN, MERCHANTABILITY AND FITNESS FOR A PARTICULAR
// PURPOSE.
//
// The "eZ Publish professional licence" is available at
// http://ez.no/products/licences/professional/. For pricing of this licence
// please contact us via e-mail to licence@ez.no. Further contact
// information is available at http://ez.no/home/contact/.
//
// The "GNU General Public License" (GPL) is available at
// http://www.gnu.org/copyleft/gpl.html.
//
// Contact licence@ez.no if any conditions of this licencing isn't clear to
// you.
//

//!
/**
  \class eZOracleDB ezoracledb.php
  \ingroup eZDB
  \brief Provides Oracle database functions for eZDB subsystem

  eZOracleDB implements OracleDB spesific database code.
*/

//require_once( "lib/ezutils/classes/ezdebug.php" );
//include_once( "lib/ezdb/classes/ezdbinterface.php" );

class eZOracleDB extends eZDBInterface
{
    /**
     * Creates a new eZOracleDB object and connects to the database.
     */
    public function __construct( $parameters )
    {
        parent::__construct( $parameters );

        if ( !extension_loaded( 'oci8' ) )
        {
            if ( function_exists( 'eZAppendWarningItem' ) )
            {
                eZAppendWarningItem( array( 'error' => array( 'type' => 'ezdb',
                                                              'number' => eZDBInterface::ERROR_MISSING_EXTENSION ),
                                            'text' => 'Oracle extension was not found, the DB handler will not be initialized.' ) );
                $this->IsConnected = false;
            }
            eZDebug::writeWarning( 'Oracle extension was not found, the DB handler will not be initialized.', 'eZOracleDB' );
            return;
        }

        $this->ErrorMessage = false;
        $this->ErrorNumber = false;
        $this->Mode = OCI_COMMIT_ON_SUCCESS;

        $this->loadSettings();

        if ( !$this->connect() )
        {
            $this->IsConnected = false;
            throw new eZDBNoConnectionException( $this->connectString(), $this->ErrorMessage, $this->ErrorNumber );
        }

        eZDebug::createAccumulatorGroup( 'oracle_total', 'Oracle Total' );
    }

    /**
     * Reads the settings of the driver: site.ini [DatabaseSettings] (connection,
     * UsePersistentConnection, OracleEmptyStringForNull, OracleCaseInsensitive*)
     * and extension/ezoracle/settings/ezoracle.ini (connection resilience,
     * performance, tracing, logging; see INSTALL). Missing settings keep the
     * defaults of the properties, so the driver also works when ezoracle.ini is
     * not found (the extension not active, only its driver class used).
     */
    function loadSettings()
    {
        $ini = eZINI::instance();

        if ( $ini->hasVariable( 'DatabaseSettings', 'OracleEmptyStringForNull' ) )
        {
            $this->EmptyStringForNullText = $ini->variable( 'DatabaseSettings', 'OracleEmptyStringForNull' ) === 'enabled';
        }
        if ( $ini->hasVariable( 'DatabaseSettings', 'OracleCaseInsensitive' ) )
        {
            $this->CaseInsensitive = $ini->variable( 'DatabaseSettings', 'OracleCaseInsensitive' ) === 'enabled';
        }
        if ( $ini->hasVariable( 'DatabaseSettings', 'OracleCaseInsensitiveSort' ) )
        {
            $sort = trim( $ini->variable( 'DatabaseSettings', 'OracleCaseInsensitiveSort' ) );
            // it goes into ALTER SESSION as it is: a linguistic sort name only
            if ( preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', $sort ) )
            {
                $this->CaseInsensitiveSort = strtoupper( $sort );
            }
            else
            {
                eZDebug::writeWarning( "OracleCaseInsensitiveSort '$sort' is not a sort name, using BINARY_CI", __METHOD__ );
            }
        }
        $this->Persistent = $ini->hasVariable( 'DatabaseSettings', 'UsePersistentConnection' ) &&
                            $ini->variable( 'DatabaseSettings', 'UsePersistentConnection' ) == 'enabled';
        $this->RetryCount = (int)$this->connectRetryCount();
        $this->RetryDelay = (float)$this->connectRetryWaitTime();

        $oraIni = eZINI::instance( 'ezoracle.ini' );
        $get = function ( $section, $name, $default ) use ( $oraIni )
        {
            return $oraIni->hasVariable( $section, $name ) ? $oraIni->variable( $section, $name ) : $default;
        };
        $enabled = function ( $value ) { return $value === 'enabled' || $value === 'true' || $value === '1'; };

        // [ConnectionSettings]
        $persistent = $get( 'ConnectionSettings', 'Persistent', 'inherit' );
        if ( $persistent !== 'inherit' )
            $this->Persistent = $enabled( $persistent );
        $this->DRCP = $enabled( $get( 'ConnectionSettings', 'DRCP', 'disabled' ) );
        $this->ConnectionClass = trim( (string)$get( 'ConnectionSettings', 'ConnectionClass', '' ) );
        $this->TnsAdmin = trim( (string)$get( 'ConnectionSettings', 'TnsAdmin', '' ) );
        $this->ConnectStringSetting = trim( (string)$get( 'ConnectionSettings', 'ConnectString', '' ) );
        $this->Hosts = array_values( array_filter( array_map( 'trim', (array)$get( 'ConnectionSettings', 'Hosts', array() ) ), 'strlen' ) );
        $this->ServiceName = trim( (string)$get( 'ConnectionSettings', 'ServiceName', '' ) );
        $this->Failover = $enabled( $get( 'ConnectionSettings', 'Failover', 'enabled' ) );
        $this->LoadBalance = $enabled( $get( 'ConnectionSettings', 'LoadBalance', 'disabled' ) );
        $this->ConnectTimeout = (int)$get( 'ConnectionSettings', 'ConnectTimeout', 0 );
        $this->Edition = trim( (string)$get( 'ConnectionSettings', 'Edition', '' ) );
        if ( $oraIni->hasVariable( 'ConnectionSettings', 'RetryCount' ) )
            $this->RetryCount = max( 0, (int)$get( 'ConnectionSettings', 'RetryCount', 0 ) );
        if ( $oraIni->hasVariable( 'ConnectionSettings', 'RetryDelay' ) )
            $this->RetryDelay = max( 0, (float)$get( 'ConnectionSettings', 'RetryDelay', 1 ) );
        $this->RetryBackoff = max( 1, (float)$get( 'ConnectionSettings', 'RetryBackoff', 2 ) );
        $errors = (array)$get( 'ConnectionSettings', 'ReconnectErrors', array() );
        if ( count( $errors ) > 0 )
            $this->ReconnectErrors = array_map( 'intval', $errors );
        $this->RetryReads = $enabled( $get( 'ConnectionSettings', 'RetryReads', 'enabled' ) );
        $this->CallTimeout = max( 0, (int)$get( 'ConnectionSettings', 'CallTimeout', 0 ) );
        $this->KeepAliveInterval = max( 0, (int)$get( 'ConnectionSettings', 'KeepAliveInterval', 0 ) );

        // [PerformanceSettings]
        $this->Prefetch = max( 0, (int)$get( 'PerformanceSettings', 'Prefetch', 0 ) );
        $this->LobPrefetch = max( 0, (int)$get( 'PerformanceSettings', 'LobPrefetch', 0 ) );
        // CURSOR_SHARING of the session: FORCE lets Oracle treat the literals of the kernel's SQL as bind variables,
        // so a statement that differs only in its values is parsed once instead of hard-parsed every time. EXACT is
        // Oracle's default. Only these two values are accepted.
        $cursorSharing = strtoupper( trim( (string)$get( 'PerformanceSettings', 'CursorSharing', 'EXACT' ) ) );
        $this->CursorSharing = in_array( $cursorSharing, array( 'EXACT', 'FORCE' ), true ) ? $cursorSharing : 'EXACT';

        // [TraceSettings]
        $this->TraceClientIdentifier = (string)$get( 'TraceSettings', 'ClientIdentifier', '' );
        $this->TraceModule = (string)$get( 'TraceSettings', 'ModuleName', '' );
        $this->TraceAction = (string)$get( 'TraceSettings', 'Action', '' );
        $this->TraceClientInfo = (string)$get( 'TraceSettings', 'ClientInfo', '' );

        // [LogSettings]
        $this->SlowQueryThreshold = max( 0, (float)$get( 'LogSettings', 'SlowQueryThreshold', 0 ) );
        $this->SlowQueryLog = basename( (string)$get( 'LogSettings', 'SlowQueryLog', 'oracle-slow.log' ) );
        $this->MaskLiterals = $enabled( $get( 'LogSettings', 'MaskLiterals', 'enabled' ) );
        $this->StatementCounts = $enabled( $get( 'LogSettings', 'StatementCounts', 'disabled' ) );
    }

    /**
     * The LOB prefetch size every query gets: ezoracle.ini [PerformanceSettings]
     * LobPrefetch, or php.ini oci8.prefetch_lob_size when that is 0, capped at
     * LOB_PREFETCH_MAX. A larger setting is reported once per process.
     *
     * With a prefetch size of 2015 or more, oci8 returns every CLOB longer than 2015
     * characters cut short, without an error: measured with oci8 3.4.1 and the Oracle
     * 23.26 client against Oracle 26ai, stored CLOBs of 2016 to 4001 characters came
     * back with 2015 for any size from 2015 up, one of 8000 with 7999 (2016), 6015
     * (4000) or 2015 (65536), through oci_fetch_all(), oci_fetch_array( OCI_RETURN_LOBS )
     * and OCILob::load() alike. Sizes up to 2014 read every length whole. The kernel
     * then read cut XML: an image attribute whose XML did not parse was taken for one
     * without an image, and the empty image was stored over it.
     */
    function lobPrefetchSize()
    {
        static $reported = false;
        $phpIni = (int)ini_get( 'oci8.prefetch_lob_size' );
        $wanted = $this->LobPrefetch > 0 ? $this->LobPrefetch : max( 0, $phpIni );
        if ( $wanted > self::LOB_PREFETCH_MAX && !$reported )
        {
            $reported = true;
            eZDebug::writeNotice( 'LOB prefetch size ' . $wanted . ' (ezoracle.ini [PerformanceSettings] LobPrefetch=' . $this->LobPrefetch .
                                  ', php.ini oci8.prefetch_lob_size=' . $phpIni . ') is capped at ' . self::LOB_PREFETCH_MAX .
                                  ': with more, oci8 returns CLOBs longer than 2015 characters cut short', __METHOD__ );
        }
        return min( $wanted, self::LOB_PREFETCH_MAX );
    }

    /**
     * The connect string the driver uses: ezoracle.ini [ConnectionSettings]
     * ConnectString, or a descriptor built from Hosts[] and ServiceName (with
     * failover, load balancing, connect timeout and, for DRCP, SERVER=POOLED),
     * or site.ini [DatabaseSettings] Database (Easy Connect host:port/service,
     * a TNS alias, or a full descriptor). With DRCP an Easy Connect string gets
     * ':POOLED' appended; a TNS alias must carry (SERVER=POOLED) in tnsnames.ora.
     *
     * @return string
     */
    function connectString()
    {
        if ( $this->ConnectStringSetting !== '' )
        {
            $cs = $this->ConnectStringSetting;
        }
        else if ( count( $this->Hosts ) > 0 && $this->ServiceName !== '' )
        {
            $addresses = '';
            foreach ( $this->Hosts as $host )
            {
                $parts = explode( ':', $host, 2 );
                $addresses .= '(ADDRESS=(PROTOCOL=TCP)(HOST=' . $parts[0] . ')(PORT=' . ( isset( $parts[1] ) ? (int)$parts[1] : 1521 ) . '))';
            }
            $cs = '(DESCRIPTION=' .
                  ( $this->ConnectTimeout > 0 ? '(CONNECT_TIMEOUT=' . $this->ConnectTimeout . ')(TRANSPORT_CONNECT_TIMEOUT=' . $this->ConnectTimeout . ')' : '' ) .
                  '(ADDRESS_LIST=(FAILOVER=' . ( $this->Failover ? 'on' : 'off' ) . ')(LOAD_BALANCE=' . ( $this->LoadBalance ? 'on' : 'off' ) . ')' . $addresses . ')' .
                  '(CONNECT_DATA=(SERVICE_NAME=' . $this->ServiceName . ')' . ( $this->DRCP ? '(SERVER=POOLED)' : '' ) . '))';
            return $cs;
        }
        else
        {
            $cs = (string)$this->DB;
        }
        // DRCP on an Easy Connect string: host:port/service:POOLED
        if ( $this->DRCP && strpos( $cs, '(' ) === false && strpos( $cs, '/' ) !== false && stripos( $cs, ':pooled' ) === false )
        {
            $cs .= ':POOLED';
        }
        return $cs;
    }

    /**
     * Opens the connection, with the retries and back-off of ezoracle.ini
     * [ConnectionSettings] (or site.ini ConnectRetries), then sets the call
     * timeout and the session settings.
     *
     * @param bool $fresh a new connection even where oci_connect() would hand
     *                    back the cached one of this request (used to reconnect)
     * @return bool
     */
    function connect( $fresh = false )
    {
        if ( $this->TnsAdmin !== '' )
        {
            putenv( 'TNS_ADMIN=' . $this->TnsAdmin );
        }
        if ( $this->ConnectionClass !== '' )
        {
            ini_set( 'oci8.connection_class', $this->ConnectionClass );
        }
        if ( $this->Edition !== '' && function_exists( 'oci_set_edition' ) )
        {
            oci_set_edition( $this->Edition );
        }

        // translate chosen charset to its Oracle analogue; without one oci8
        // would take NLS_LANG from the environment, which is US7ASCII when it
        // is not set (every non-ASCII character becomes '?'), so the client
        // then talks UTF-8, the internal charset of Exponential
        $oraCharset = $this->oracleCharset( $this->Charset );
        if ( $oraCharset === null )
        {
            if ( $this->Charset !== null && $this->Charset !== '' && $this->Charset !== false )
            {
                eZDebug::writeWarning( "Charset '{$this->Charset}' has no Oracle equivalent, using AL32UTF8", __METHOD__ );
            }
            $oraCharset = 'AL32UTF8';
        }

        $user = $this->User;
        $password = $this->Password;
        $connectString = $this->connectString();
        $function = $this->Persistent ? 'oci_pconnect' : ( $fresh ? 'oci_new_connect' : 'oci_connect' );

        $this->DBConnection = false;
        $delay = $this->RetryDelay;
        $error = false;
        for ( $attempt = 0; $attempt <= $this->RetryCount; ++$attempt )
        {
            if ( $attempt > 0 )
            {
                eZDebug::writeWarning( "Connection attempt $attempt of {$this->RetryCount} failed" . ( is_array( $error ) ? ' (ORA-' . $error['code'] . ')' : '' ) . ", next in {$delay}s", __METHOD__ );
                usleep( (int)( $delay * 1000000 ) );
                $delay *= $this->RetryBackoff;
            }
            eZDebug::accumulatorStart( 'oracle_connection', 'oracle_total', 'Database connection' );
            $oldHandling = eZDebug::setHandleType( eZDebug::HANDLE_EXCEPTION );
            try
            {
                $connection = @$function( $user, $password, $connectString, $oraCharset );
            }
            catch ( ErrorException $e )
            {
                $connection = false;
            }
            eZDebug::setHandleType( $oldHandling );
            eZDebug::accumulatorStop( 'oracle_connection' );
            if ( $connection )
            {
                $this->DBConnection = $connection;
                break;
            }
            $error = oci_error();
        }

        if ( !$this->DBConnection )
        {
            $this->IsConnected = false;
            // oci_error() gives false when the client could not even be
            // initialised (no client libraries, bad NLS settings, ...)
            if ( !is_array( $error ) )
            {
                $error = array( 'code' => -1, 'message' => 'oci_connect() failed without an Oracle error (are the Oracle client libraries installed and found?)' );
            }
            if ( $error['code'] == 12541 )
            {
                $error['message'] = 'No listener (probably the server is down).';
            }
            $this->ErrorMessage = $error['message'];
            $this->ErrorNumber = $error['code'];
            eZDebug::writeError( "Connection error(" . $error["code"] . "):\n". $error["message"] .  " ", "eZOracleDB" );
            return false;
        }

        $this->IsConnected = true;
        if ( $this->CallTimeout > 0 && function_exists( 'oci_set_call_timeout' ) )
        {
            oci_set_call_timeout( $this->DBConnection, $this->CallTimeout );
        }
        // the tags are sent again on the new session
        $this->TraceTags = array();
        $this->LastActivity = microtime( true );
        $this->initializeSession();
        return true;
    }

    /**
     * Drops a broken connection and opens a new one. Not inside a transaction:
     * its work is lost with the session, and the caller has to know.
     *
     * @return bool
     */
    function reconnect()
    {
        if ( $this->TransactionCounter > 0 )
        {
            return false;
        }
        if ( $this->DBConnection )
        {
            @oci_close( $this->DBConnection );
        }
        $this->DBConnection = false;
        $this->IsConnected = false;
        ++$this->ReconnectCount;
        $ok = $this->connect( true );
        eZDebug::writeNotice( 'Reconnected to Oracle after a lost connection: ' . ( $ok ? 'ok' : 'failed' ), __METHOD__ );
        return $ok;
    }

    /**
     * True for the Oracle errors that mean the connection is gone (or the
     * service is not reachable) rather than the statement is wrong
     * (ezoracle.ini [ConnectionSettings] ReconnectErrors[]).
     *
     * @param int $code
     * @return bool
     */
    function isReconnectError( $code )
    {
        return in_array( (int)$code, $this->ReconnectErrors, true );
    }

    /**
     * Keep-alive for long running processes (CLI scripts, Velocity workers):
     * when the connection was idle for longer than ezoracle.ini
     * [ConnectionSettings] KeepAliveInterval seconds, a round trip checks it
     * and a dead one is replaced, before the next statement fails on it.
     */
    function keepAlive()
    {
        if ( $this->KeepAliveInterval <= 0 || $this->TransactionCounter > 0 || !$this->DBConnection )
        {
            return;
        }
        if ( microtime( true ) - $this->LastActivity < $this->KeepAliveInterval )
        {
            return;
        }
        $statement = @oci_parse( $this->DBConnection, 'BEGIN NULL; END;' );
        $alive = $statement && @oci_execute( $statement, OCI_COMMIT_ON_SUCCESS );
        if ( $statement )
        {
            @oci_free_statement( $statement );
        }
        if ( $alive )
        {
            $this->LastActivity = microtime( true );
        }
        else
        {
            eZDebug::writeWarning( 'The connection was idle for ' . round( microtime( true ) - $this->LastActivity ) . 's and did not answer: reconnecting', __METHOD__ );
            $this->reconnect();
        }
    }

    /**
     * Sets the client identifier, module, action and client info of the session
     * from ezoracle.ini [TraceSettings] (patterns with %siteaccess%, %module%,
     * %view%, %user_id%, %script%, %pid%), so a DBA sees in V$SESSION which part
     * of Exponential a session works for. The values travel with the next
     * round trip, and are only set again when they change.
     */
    function applyTraceTags()
    {
        if ( $this->TraceClientIdentifier === '' && $this->TraceModule === '' && $this->TraceAction === '' && $this->TraceClientInfo === '' )
        {
            return;
        }
        $siteaccess = isset( $GLOBALS['eZCurrentAccess']['name'] ) ? $GLOBALS['eZCurrentAccess']['name'] : 'none';
        $module = $view = '';
        if ( isset( $GLOBALS['eZURIRequestInstance'] ) && is_object( $GLOBALS['eZURIRequestInstance'] ) )
        {
            $module = (string)$GLOBALS['eZURIRequestInstance']->element( 0 );
            $view = (string)$GLOBALS['eZURIRequestInstance']->element( 1 );
        }
        $script = PHP_SAPI === 'cli' && isset( $_SERVER['argv'][0] ) ? basename( $_SERVER['argv'][0] ) : PHP_SAPI;
        if ( $module === '' )
        {
            $module = $script;
        }
        $userID = isset( $_SESSION['eZUserLoggedInID'] ) ? (int)$_SESSION['eZUserLoggedInID'] : 0;
        $replace = array( '%siteaccess%' => $siteaccess, '%module%' => $module, '%view%' => $view,
                          '%user_id%' => $userID, '%script%' => $script, '%pid%' => getmypid() );
        // the lengths V$SESSION keeps: client identifier 64, module 48, action 32, client info 64
        $tags = array( 'client_identifier' => substr( strtr( $this->TraceClientIdentifier, $replace ), 0, 64 ),
                       'module'            => substr( strtr( $this->TraceModule, $replace ), 0, 48 ),
                       'action'            => substr( strtr( $this->TraceAction, $replace ), 0, 32 ),
                       'client_info'       => substr( strtr( $this->TraceClientInfo, $replace ), 0, 64 ) );
        foreach ( $tags as $tag => $value )
        {
            if ( $value === '' || ( isset( $this->TraceTags[$tag] ) && $this->TraceTags[$tag] === $value ) )
            {
                continue;
            }
            switch ( $tag )
            {
                case 'client_identifier': @oci_set_client_identifier( $this->DBConnection, $value ); break;
                case 'module':            @oci_set_module_name( $this->DBConnection, $value ); break;
                case 'action':            @oci_set_action( $this->DBConnection, $value ); break;
                case 'client_info':       @oci_set_client_info( $this->DBConnection, $value ); break;
            }
            $this->TraceTags[$tag] = $value;
        }
    }

    /**
     * Parses and runs $sql with the bind variables collected by bindVariable():
     * keep-alive, trace tags, prefetch, statement counts, the slow query log, and
     * a reconnect when the connection is lost (ReconnectErrors). A read (SELECT,
     * WITH) that met a lost connection outside a transaction is run again on the
     * new connection (RetryReads=enabled, the default); a write is not, as it may
     * have been carried out before the connection broke: it is reported.
     *
     * @param string $sql
     * @param string $caller 'query()' or 'arrayQuery()', for the error report
     * @param bool $useBinds bind the variables of bindVariable() (query() does)
     * @return resource|bool the executed statement, false on an error (reported)
     */
    function executeStatement( $sql, $caller, $useBinds = true )
    {
        $this->keepAlive();
        if ( !$this->DBConnection )
        {
            $this->ErrorMessage = 'Not connected';
            $this->ErrorNumber = -1;
            return false;
        }
        $this->applyTraceTags();
        $isRead = (bool)preg_match( '/^[\s(]*(SELECT|WITH)\b/i', $sql );
        $type = strtoupper( (string)strtok( ltrim( $sql, " \t\r\n(" ), " \t\r\n(" ) );

        for ( $attempt = 0; ; ++$attempt )
        {
            $statement = @oci_parse( $this->DBConnection, $sql );
            if ( !$statement )
            {
                $this->setError( $this->DBConnection, $caller, $sql );
                return false;
            }
            foreach ( $useBinds ? $this->BindVariableArray : array() as $key => $bindVar )
            {
                oci_bind_by_name( $statement, $bindVar['dbname'], $this->BindVariableArray[$key]['value'], -1 );
            }
            if ( $isRead && $this->Prefetch > 0 )
            {
                oci_set_prefetch( $statement, $this->Prefetch );
            }
            if ( $isRead && function_exists( 'oci_set_prefetch_lob' ) )
            {
                // always set, so php.ini oci8.prefetch_lob_size is capped too: above
                // LOB_PREFETCH_MAX oci8 hands back CLOBs cut short without an error
                @oci_set_prefetch_lob( $statement, $this->lobPrefetchSize() );
            }

            if ( $this->StatementCounts )
            {
                eZDebug::accumulatorStart( 'oracle_stmt_' . $type, 'oracle_total', "Oracle $type statements" );
            }
            $start = microtime( true );
            $exec = @oci_execute( $statement, $this->Mode );
            $elapsed = ( microtime( true ) - $start ) * 1000;
            if ( $this->StatementCounts )
            {
                eZDebug::accumulatorStop( 'oracle_stmt_' . $type );
            }
            ++$this->StatementCount;
            $this->LastActivity = microtime( true );

            if ( $exec )
            {
                if ( $this->SlowQueryThreshold > 0 && $elapsed >= $this->SlowQueryThreshold )
                {
                    $this->logSlowQuery( $sql, $elapsed );
                }
                return $statement;
            }

            $error = oci_error( $statement );
            if ( is_array( $error ) && $this->isReconnectError( $error['code'] ) && $this->TransactionCounter == 0 && $attempt < max( 1, $this->RetryCount ) )
            {
                @oci_free_statement( $statement );
                if ( $this->reconnect() && $isRead && $this->RetryReads )
                {
                    continue;
                }
                // a write may have been carried out before the connection broke:
                // it is reported, not run a second time
                $statement = false;
                $this->ErrorMessage = $error['message'];
                $this->ErrorNumber = $error['code'];
                eZDebug::writeError( "Error (" . $error['code'] . "): " . $error['message'] . "\nThe connection was lost; the statement was not run again:\n" . $sql, "eZOracleDB::$caller" );
                return false;
            }
            $hasError = $this->setError( $statement, $caller, $sql );
            if ( !$hasError )
            {
                // oci_execute() failed without an Oracle error: nothing to report
                return $statement;
            }
            @oci_free_statement( $statement );
            return false;
        }
    }

    /**
     * Writes a statement that took $elapsed milliseconds or more (ezoracle.ini
     * [LogSettings] SlowQueryThreshold) to var/log/<SlowQueryLog>, with the bound
     * values replaced by their length and, with MaskLiterals, the string literals
     * of the SQL by '?', so no personal data or password lands in the log.
     *
     * @param string $sql
     * @param float $elapsed milliseconds
     */
    function logSlowQuery( $sql, $elapsed )
    {
        $text = preg_replace( '/\s+/', ' ', trim( $sql ) );
        if ( $this->MaskLiterals )
        {
            $text = preg_replace( "/'(?:[^']|'')*'/", "'?'", $text );
        }
        if ( strlen( $text ) > 4000 )
        {
            $text = substr( $text, 0, 4000 ) . ' ...';
        }
        $binds = array();
        foreach ( $this->BindVariableArray as $bindVar )
        {
            $binds[] = $bindVar['dbname'] . '=<' . strlen( (string)$bindVar['value'] ) . ' bytes>';
        }
        $who = isset( $this->TraceTags['module'] ) ? ' [' . $this->TraceTags['module'] . ( isset( $this->TraceTags['action'] ) ? ' ' . $this->TraceTags['action'] : '' ) . ']' : '';
        eZLog::write( sprintf( '%.1f ms%s %s%s', $elapsed, $who, $text, $binds ? ' binds ' . implode( ', ', $binds ) : '' ), $this->SlowQueryLog );
    }

    /**
     * The session settings every connection gets, in one statement:
     * - the dot as decimal separator;
     * - character length semantics, so the VARCHAR2 columns the schema handler
     *   creates count characters as the lengths in the .dba files do
     *   (VARCHAR2(255) would hold ~85 CJK characters otherwise);
     * - with site.ini [DatabaseSettings] OracleCaseInsensitive=enabled, linguistic
     *   comparison and sorting (NLS_COMP=LINGUISTIC, NLS_SORT=OracleCaseInsensitiveSort);
     * - CURSOR_SHARING from ezoracle.ini [PerformanceSettings] CursorSharing (EXACT or FORCE).
     *
     * @return bool
     */
    function initializeSession()
    {
        $settings = array( "NLS_NUMERIC_CHARACTERS='. '", 'NLS_LENGTH_SEMANTICS=CHAR', 'CURSOR_SHARING=' . $this->CursorSharing );
        if ( $this->CaseInsensitive )
        {
            $settings[] = 'NLS_COMP=LINGUISTIC';
            $settings[] = 'NLS_SORT=' . $this->CaseInsensitiveSort;
        }
        return $this->query( 'ALTER SESSION SET ' . implode( ' ', $settings ) );
    }

    function databaseName()
    {
        return 'oracle';
    }

    function bindingType( )
    {
        return eZDBInterface::BINDING_NAME;
    }

    function bindVariable( $value, $fieldDef = false )
    {
        if ( $this->InputTextCodec )
        {
            $value = $this->InputTextCodec->convertString( $value );
        }
        // a caller that gives no field name still gets a unique placeholder
        $name = ( is_array( $fieldDef ) && isset( $fieldDef['name'] ) && $fieldDef['name'] !== '' )
              ? $fieldDef['name']
              : 'ezbind' . count( $this->BindVariableArray );
        $this->BindVariableArray[] = array( 'name' => $name,
                                            'dbname' => ':' . $name,
                                            'value' => $value );
        return ':' . $name;
    }

    function analyseQuery( $sql, $server = false )
    {
        $analysisText = false;
        // If query analysis is enable we need to run the query
        // with an EXPLAIN in front of it
        // Then we build a human-readable table out of the result
        if ( $this->QueryAnalysisOutput && $this->isConnected() )
        {
            $stmtid = substr( md5( $sql ), 0, 30);
            $analysisStmt = oci_parse( $this->DBConnection, 'EXPLAIN PLAN SET STATEMENT_ID = \'' . $stmtid . '\' FOR ' . $sql );
            if ( !$analysisStmt )
            {
                return false;
            }
            $analysisResult = @oci_execute( $analysisStmt, $this->Mode );
            if ( $analysisResult )
            {
                // note: we might make the name of the explain plan table an ini variable...
                // note 2: since oracle 9, a package is provided that we could use to get nicely formatted explain plan output: DBMS_XPLAN.DISPLAY
                //         but we should check if it is installe or not
                //         "SELECT * FROM table (DBMS_XPLAN.DISPLAY('plan_table', '$stmtid'))";
                oci_free_statement( $analysisStmt );
                $analysisStmt = oci_parse( $this->DBConnection, "SELECT LPAD(' ',2*(LEVEL-1))||operation operation, options,
                                                                         object_name, position, cost, cardinality, bytes
                                                                  FROM plan_table
                                                                  START WITH id = 0 AND statement_id = '$stmtid'
                                                                  CONNECT BY PRIOR id = parent_id AND statement_id = '$stmtid'" );
                $analysisResult = oci_execute( $analysisStmt, $this->Mode );
                if ( $analysisResult )
                {
                    $rows = array();
                    $numRows = oci_fetch_all( $analysisStmt, $rows, 0, -1, OCI_ASSOC + OCI_FETCHSTATEMENT_BY_ROW );
                    if ( $this->OutputTextCodec )
                    {
                        for ( $i = 0; $i < $numRows; ++$i )
                        {
                            foreach( $rows[$i] as $key => $data )
                            {
                                $rows[$i][$key] = $this->OutputTextCodec->convertString( $data );
                            }
                        }
                    }

                    // Figure out all columns and their maximum display size
                    $columns = array();
                    foreach ( $rows as $row )
                    {
                        foreach ( $row as $col => $data )
                        {
                            if ( !isset( $columns[$col] ) )
                            {
                                $columns[$col] = array( 'name' => $col,
                                                        'size' => strlen( $col ) );
                            }
                            $columns[$col]['size'] = max( $columns[$col]['size'], strlen( (string)$data ) );
                        }
                    }

                    $analysisText = '';
                    $delimiterLine = array();
                    $colLine = array();
                    // Generate the column line and the vertical delimiter
                    // The look of the table is taken from the MySQL CLI client
                    // It looks like this:
                    // +-------+-------+
                    // | col_a | col_b |
                    // +-------+-------+
                    // | txt   |    42 |
                    // +-------+-------+
                    foreach ( $columns as $col )
                    {
                        $delimiterLine[] = str_repeat( '-', $col['size'] + 2 );
                        $colLine[] = ' ' . str_pad( $col['name'], $col['size'], ' ', STR_PAD_RIGHT ) . ' ';
                    }
                    $delimiterLine = '+' . join( '+', $delimiterLine ) . "+\n";
                    $analysisText = $delimiterLine;
                    $analysisText .= '|' . join( '|', $colLine ) . "|\n";
                    $analysisText .= $delimiterLine;

                    // Go trough all data and pad them to create the table correctly
                    foreach ( $rows as $row )
                    {
                        $rowLine = array();
                        foreach ( $columns as $col )
                        {
                            $name = $col['name'];
                            $size = $col['size'];
                            $data = isset( $row[$name] ) ? (string)$row[$name] : '';
                            // Align numerical values to the right (ie. pad left)
                            $rowLine[] = ' ' . str_pad( $data, $size, ' ',
                                                        is_numeric( $data ) ? STR_PAD_LEFT : STR_PAD_RIGHT ) . ' ';
                        }
                        $analysisText .= '|' . join( '|', $rowLine ) . "|\n";
                        $analysisText .= $delimiterLine;
                    }

                    // Reduce memory usage
                    unset( $rows, $delimiterLine, $colLine, $columns );
                }
            }
            oci_free_statement( $analysisStmt );
        }
        return $analysisText;
    }

    function query( $sql, $server = false )
    {
        // note: the other database drivers do not reset the error message here...
        $this->ErrorMessage = false;
        $this->ErrorNumber = false;

        if ( !$this->isConnected() )
        {
            eZDebug::writeError( "Trying to do a query without being connected to a database!", "eZOracleDB"  );
            // like the PostgreSQL and MySQLi drivers
            return false;
        }
        $result = true;

        eZDebug::accumulatorStart( 'oracle_query', 'oracle_total', 'Oracle_queries' );
        // The converted sql should not be output
        if ( $this->InputTextCodec )
        {
             eZDebug::accumulatorStart( 'oracle_conversion', 'oracle_total', 'String conversion in oracle' );
             $sql = $this->InputTextCodec->convertString( $sql );
             eZDebug::accumulatorStop( 'oracle_conversion' );
        }

        if ( $this->OutputSQL )
        {
            $this->startTimer();
        }

        $analysisText = $this->analyseQuery( $sql, $server );

        // $this->Mode commits every statement outside a transaction; the parent
        // class counts nested transactions (see beginQuery())
        $statement = $this->executeStatement( $sql, 'query()' );
        if ( $statement )
        {
            oci_free_statement( $statement );
            // A write makes the query cache's results for its tables stale
            // (an anonymous PL/SQL block: all of them).
            if ( class_exists( 'eZDBQueryCache' ) )
            {
                eZDBQueryCache::noteWrite( $this, $sql );
            }
        }
        else
        {
            $result = false;
        }

        if ( $this->OutputSQL )
        {
            $this->endTimer();
            if ( $this->timeTaken() > $this->SlowSQLTimeout )
            {
                // If we have some analysis text we append this to the SQL output
                if ( $analysisText !== false )
                {
                    $sql = "EXPLAIN\n" . $sql . "\n\nANALYSIS:\n" . $analysisText;
                }

                $this->reportQuery( 'eZOracleDB', $sql, false, $this->timeTaken() );
            }
        }

        eZDebug::accumulatorStop( 'oracle_query' );

        $this->BindVariableArray = array();

        // let std error handling happen here (eg: transaction error reporting)
        if ( !$result )
        {
            $this->reportError();

            if ( $this->errorHandling == eZDB::ERROR_HANDLING_EXCEPTIONS )
            {
                throw new eZDBException( $this->ErrorMessage, $this->ErrorNumber );
            }
        }

        return $result;
    }

    function arrayQuery( $sql, $params = false, $server = false )
    {
        $resultArray = array();

        if ( !$this->isConnected() )
        {
            return $resultArray;
        }

        // The query cache (settings/querycache.ini): the rows, while current.
        // Keyed by the statement as the caller wrote it and its parameters,
        // before the row limit is put into the text below.
        $cacheTicket = null;
        if ( class_exists( 'eZDBQueryCache' ) )
        {
            $cacheTicket = eZDBQueryCache::lookup( $this, $sql, $params, $cached );
            if ( $cached !== null )
            {
                return $cached;
            }
        }

        $limit = -1;
        $offset = 0;
        $column = false;
        // check for array parameters
        if ( is_array( $params ) )
        {
            if ( isset( $params["limit"] ) and is_numeric( $params["limit"] ) )
            {
                $limit = (int)$params["limit"];
            }
            if ( isset( $params["offset"] ) and is_numeric( $params["offset"] ) )
            {
                $offset = max( 0, (int)$params["offset"] );
            }
            if ( isset( $params["column"] ) and ( is_numeric( $params["column"] ) or is_string( $params["column"]) ) )
            {
                $column = strtoupper( $params["column"] );
            }
        }

        // Let the database skip and cut the rows (Oracle 12.1 and later) instead
        // of fetching every row up to the offset and dropping them in oci8.
        // The keys of the result still start at the offset, as in the other drivers.
        $fetchOffset = $offset;
        $fetchLimit = $limit;
        if ( ( $offset > 0 || $limit >= 0 ) && $this->canAppendRowLimit( $sql ) )
        {
            $sql = rtrim( $sql );
            if ( $offset > 0 )
            {
                $sql .= "\nOFFSET $offset ROWS";
            }
            if ( $limit >= 0 )
            {
                $sql .= "\nFETCH NEXT $limit ROWS ONLY";
            }
            $fetchOffset = 0;
            $fetchLimit = -1;
        }
        eZDebug::accumulatorStart( 'oracle_query', 'oracle_total', 'Oracle_queries' );
//        if ( $this->OutputSQL )
//            $this->startTimer();
        // The converted sql should not be output
        if ( $this->InputTextCodec )
        {
            eZDebug::accumulatorStart( 'oracle_conversion', 'oracle_total', 'String conversion in oracle' );
            $sql = $this->InputTextCodec->convertString( $sql );
            eZDebug::accumulatorStop( 'oracle_conversion' );
        }

        $analysisText = $this->analyseQuery( $sql, $server );

        if ( $this->OutputSQL )
        {
            $this->startTimer();
        }
        $this->ErrorMessage = false;
        $this->ErrorNumber = false;
        // bind variables are query()'s: a pending one is left for it
        $statement = $this->executeStatement( $sql, 'arrayQuery()', false );
        if ( !$statement )
        {
            eZDebug::accumulatorStop( 'oracle_query' );
            if ( $this->errorHandling == eZDB::ERROR_HANDLING_EXCEPTIONS )
            {
                throw new eZDBException( $this->ErrorMessage, $this->ErrorNumber );
            }
            return false;
        }
        eZDebug::accumulatorStop( 'oracle_query' );

        if ( $this->OutputSQL )
        {
            $this->endTimer();
            if ( $this->timeTaken() > $this->SlowSQLTimeout )
            {
                // If we have some analysis text we append this to the SQL output
                if ( $analysisText !== false )
                {
                    $sql = "EXPLAIN\n" . $sql . "\n\nANALYSIS:\n" . $analysisText;
                }
                $this->reportQuery( 'eZOracleDB', $sql, false, $this->timeTaken() );
            }
        }

        $results = array();

        eZDebug::accumulatorStart( 'oracle_loop', 'oracle_total', 'Oracle looping results' );

        // Oracle stores '' as NULL; the text columns give '' back, as the other
        // drivers do for the empty strings the application wrote
        $textColumns = $this->EmptyStringForNullText ? $this->textColumnNames( $statement ) : array();

        if ( $column !== false )
        {
            if ( is_numeric( $column ) )
            {
               $rowCount = oci_fetch_all( $statement, $results, $fetchOffset, $fetchLimit, OCI_FETCHSTATEMENT_BY_COLUMN + OCI_NUM );
            }
            else
            {
                $rowCount = oci_fetch_all( $statement, $results, $fetchOffset, $fetchLimit, OCI_FETCHSTATEMENT_BY_COLUMN + OCI_ASSOC );
            }

            if ( $rowCount > 0 && !isset( $results[$column] ) )
            {
                eZDebug::writeError( "Column '$column' is not in the result of the query", __METHOD__ );
                $rowCount = 0;
            }
            else if ( $rowCount > 0 && isset( $textColumns[$column] ) )
            {
                foreach ( $results[$column] as $i => $value )
                {
                    if ( $value === null )
                        $results[$column][$i] = '';
                }
            }

            // optimize to our best the special case: 1 row
            if ( $rowCount == 1 )
            {
                $resultArray[$offset] = $this->OutputTextCodec ? $this->OutputTextCodec->convertString( $results[$column][0] ) : $results[$column][0];
            }
            else if ( $rowCount > 0 )
            {
                $results = $results[$column];
                if ( $this->OutputTextCodec )
                {
                    array_walk( $results, array( 'eZOracleDB', 'arrayConvertStrings' ), $this->OutputTextCodec );
                }
                $resultArray = $offset == 0 ? $results : array_combine( range( $offset, $offset + $rowCount -1 ), $results );
            }
        }
        else
        {
            $rowCount = oci_fetch_all( $statement, $results, $fetchOffset, $fetchLimit, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC );
            if ( $rowCount > 0 && count( $textColumns ) > 0 )
            {
                foreach ( $results as $i => $row )
                {
                    foreach ( $textColumns as $name => $true )
                    {
                        if ( array_key_exists( $name, $row ) && $row[$name] === null )
                            $results[$i][$name] = '';
                    }
                }
            }
            // optimize to our best the special case: 1 row
            if ( $rowCount == 1 )
            {
                if ( $this->OutputTextCodec )
                {
                    array_walk( $results[0], array( 'eZOracleDB', 'arrayConvertStrings' ), $this->OutputTextCodec );
                }
                $resultArray[$offset] = array_change_key_case( $results[0] );
            }
            else if ( $rowCount > 0 )
            {
                $keys = array_keys( array_change_key_case( $results[0] ) );
                // this would be slightly faster, but we have to work around a php bug
                // with recursive array_walk present in 5.1 (eg. on red hat 5.2)
                //array_walk( $results, array( 'eZOracleDB', 'arrayChangeKeys' ), array( $this->OutputTextCodec, $keys ) );
                $arr = array( $this->OutputTextCodec, $keys );
                foreach( $results as  $key => &$val )
                {
                    self::arrayChangeKeys( $val, $key, $arr );
                }
                unset( $val );
                $resultArray = $offset == 0 ? $results : array_combine( range( $offset, $offset + $rowCount - 1 ), $results );
            }
        }

        eZDebug::accumulatorStop( 'oracle_loop' );
        oci_free_statement( $statement );

        if ( $cacheTicket !== null )
        {
            eZDBQueryCache::store( $cacheTicket, $resultArray );
        }

        return $resultArray;
    }

    /**
     * True when OFFSET ... ROWS / FETCH NEXT ... ROWS ONLY can be put at the end
     * of $sql: a plain query (SELECT or WITH), on Oracle 12.1 or later, that has
     * no row limiting clause and no FOR UPDATE of its own.
     *
     * @param string $sql
     * @return bool
     */
    function canAppendRowLimit( $sql )
    {
        if ( $this->ServerMajorVersion === null )
        {
            $version = $this->databaseServerVersion();
            $this->ServerMajorVersion = $version ? (int)$version['values'][0] : 0;
        }
        if ( $this->ServerMajorVersion < 12 )
        {
            return false;
        }
        if ( !preg_match( '/^[\s(]*(SELECT|WITH)\b/i', $sql ) )
        {
            return false;
        }
        if ( preg_match( '/\bFOR\s+UPDATE\b|\bFETCH\s+(FIRST|NEXT)\b|\bOFFSET\s+\S+\s+ROWS?\b|;\s*$/i', $sql ) )
        {
            return false;
        }
        return true;
    }

    /**
     * The columns of an executed statement that hold text (CHAR, VARCHAR2,
     * CLOB and their national variants), by upper-case name and by position.
     *
     * @param resource $statement
     * @return array name|position => true
     */
    function textColumnNames( $statement )
    {
        $textColumns = array();
        $count = oci_num_fields( $statement );
        for ( $i = 1; $i <= $count; ++$i )
        {
            $type = oci_field_type( $statement, $i );
            if ( in_array( $type, array( 'CHAR', 'VARCHAR2', 'VARCHAR', 'NCHAR', 'NVARCHAR2', 'CLOB', 'NCLOB', 'LONG' ), true ) )
            {
                $textColumns[oci_field_name( $statement, $i )] = true;
                $textColumns[$i - 1] = true;
            }
        }
        return $textColumns;
    }

    /**
     * @access private
     */
    function subString( $string, $from, $len = null )
    {
        if ( $len == null )
        {
            return " substr( $string, $from ) ";
        }
        else
        {
            return " substr( $string, $from, $len ) ";
        }
    }

    /**
     * Note: since we autocommit every statement that is not within a transaction,
     * when we rollback we allways rollback everything that has not yet been
     * committed. This means there is little use in setting a SAVEPOINT here
     * (also because oracle does not support committing up to a savepoint...)
     */
    function beginQuery()
    {
        $this->Mode = OCI_DEFAULT;
        if ( $this->OutputSQL )
        {
            $this->reportQuery( 'eZOracleDB', 'begin transaction (disable autocommit)', false, 0 );
        }
        return true;
    }

    function useShortNames()
    {
        return true;
    }

    /**
     * We trust the eZDBInterface to count nested transactions and only call
     * this method when trans counter reaches 0
     */
    function commitQuery()
    {
        $result = oci_commit( $this->DBConnection );
        $this->Mode = OCI_COMMIT_ON_SUCCESS;
        if ( $this->OutputSQL )
        {
            $this->reportQuery( 'eZOracleDB', 'commit transaction', false, 0 );
        }
        return $result;
    }

    function rollbackQuery()
    {
        $result = oci_rollback( $this->DBConnection );
        $this->Mode = OCI_COMMIT_ON_SUCCESS;
        if ( $this->OutputSQL )
        {
            $this->reportQuery( 'eZOracleDB', 'rollback transaction', false, 0 );
        }
        return $result;
    }

    /**
     * Returns the value the sequence of $table gave last in this session
     * (the sequence the auto_increment trigger of eZOracleSchema uses).
     *
     * @return int|bool false when there is no table, no connection or no value
     */
    function lastSerialID( $table = false, $column = false )
    {
        $id = false;
        if ( !is_string( $table ) || $table === '' )
        {
            eZDebug::writeError( 'No table given, the sequence cannot be found', __METHOD__ );
            return false;
        }
        if ( $this->isConnected() )
        {
            $sequence = preg_replace( '/^ez/i', 's_', $table );
            if ( $sequence == $table )
            {
                // table name does not start with 'ez': an extension, most likely
                $sequence = substr( 'se_' . $sequence, 0, 30 );
            }
            $sql = "SELECT $sequence.currval from DUAL";
            $res = $this->arrayQuery( $sql );
            if ( $res == false )
            {
                // retrieving the triggers that operate on the given table
                // SELECT trigger_name, trigger_body, status FROM user_triggers WHERE table_name = $table
                // retrieving the incriminated sequence
                // SELECT * FROM user_sequences where sequence_name = $sequence;
                eZDebug::writeError( "Cannot retrieve last serial ID on table $table. Please make sure that sequence $sequence exists and its 'before insert' trigger is valid" );
            }
            else if ( isset( $res[0]["currval"] ) )
            {
                $id = (int)$res[0]["currval"];
            }
        }

        return $id;
    }

    function escapeString( $str )
    {
        // like the MySQLi driver: null is the empty string (and no PHP 8.1 deprecation)
        if ( $str === null )
        {
            return '';
        }
        $str = str_replace ( "'", "''", (string)$str );
//        $str = str_replace ("\"", "\\\"", $str );
        return $str;
    }

    function concatString( $strings = array() )
    {
        $str = implode( " || " , $strings );
        return "  $str   ";
    }

    /**
     * MD5 in SQL, as a lower-case hex string like MySQL's MD5().
     *
     * STANDARD_HASH() exists since Oracle 12.1 and needs no function in the
     * schema (the old md5_digest() used dbms_obfuscation_toolkit, removed in
     * 21c). Oracle stores '' as NULL, and STANDARD_HASH( NULL ) is NULL, so
     * NULL gives the hash of the empty string, as md5_digest() did.
     */
    function md5( $str )
    {
        return " NVL( LOWER( RAWTOHEX( STANDARD_HASH( $str, 'MD5' ) ) ), 'd41d8cd98f00b204e9800998ecf8427e' ) ";
    }

    function bitAnd( $arg1, $arg2 )
    {
        return " bitand( $arg1, $arg2 ) ";
    }

    /**
     * Bitwise OR from BITAND(), which every Oracle release has: a | b = a + b - ( a & b ).
     * Needs neither the bitor() function of sql/bitor.sql nor the BITOR() of 23ai.
     */
    function bitOr( $arg1, $arg2 )
    {
        return " ( ( $arg1 ) + ( $arg2 ) - bitand( $arg1, $arg2 ) ) ";
    }

    function supportedRelationTypeMask()
    {
        return ( eZDBInterface::RELATION_TABLE_BIT |
                 eZDBInterface::RELATION_SEQUENCE_BIT |
                 eZDBInterface::RELATION_TRIGGER_BIT |
                 eZDBInterface::RELATION_VIEW_BIT |
                 eZDBInterface::RELATION_INDEX_BIT );
    }

    function supportedRelationTypes()
    {
        return array( eZDBInterface::RELATION_TABLE,
                      eZDBInterface::RELATION_SEQUENCE,
                      eZDBInterface::RELATION_TRIGGER,
                      eZDBInterface::RELATION_VIEW,
                      eZDBInterface::RELATION_INDEX );
    }

    /**
     * @ access private
     * @return array Detailed information regarding a relation type.
     *         It will return an associative array containing:
     *         - table - The table which contains information on the relation type
     *         - field - The field that contains the name of the relation types
     *         - ignore_name - If the field starts with this (case-insensitive) string
     *                         the relation must be ignored. (optional)
     *         false is returned if it is an unknown relation type.
     * @param string $relationType One of the relation types defined in eZDBInterface
     */
    function relationInfo( $relationType )
    {
        $kind = array( eZDBInterface::RELATION_TABLE => array( 'table' => 'user_tables',
                                                      'field' => 'table_name' ),
                       eZDBInterface::RELATION_SEQUENCE => array( 'table' => 'user_sequences',
                                                         'field' => 'sequence_name' ),
                       eZDBInterface::RELATION_TRIGGER => array( 'table' => 'user_triggers',
                                                        'field' => 'trigger_name' ),
                       eZDBInterface::RELATION_VIEW => array( 'table' => 'user_views',
                                                     'field' => 'view_name' ),
                       eZDBInterface::RELATION_INDEX => array( 'table' => 'user_indexes',
                                                      'field' => 'index_name',
                                                      'ignore_name' => 'sys' ) );
        if ( !isset( $kind[$relationType] ) )
        {
            return false;
        }
        return $kind[$relationType];
    }

    function relationCounts( $relationMask )
    {
        $relationTypes = $this->supportedRelationTypes();
        $relationInfoList = array();
        foreach ( $relationTypes as $relationType )
        {
            $relationBit = (1 << $relationType );
            if ( $relationMask & $relationBit )
            {
                $relationInfo = $this->relationInfo( $relationType );
                if ( $relationInfo )
                {
                    $relationInfoList[] = $relationInfo;
                }
            }
        }
        if ( count( $relationInfoList ) == 0 )
        {
            return 0;
        }
        $count = false;
        if ( $this->isConnected() )
        {
            $count = 0;
            foreach ( $relationInfoList as $relationInfo )
            {
                $field = $relationInfo['field'];
                $table = $relationInfo['table'];
                $ignoreName = false;
                if ( isset( $relationInfo['ignore_name'] ) )
                {
                    $ignoreName = $relationInfo['ignore_name'];
                }

                $matchText = '';
                if ( $ignoreName )
                {
                    $matchText = "WHERE LOWER( SUBSTR( $field, 0, " . strlen( $ignoreName ) . " ) ) != '$ignoreName'";
                }
                $sql = "SELECT COUNT( $field ) as count FROM $table $matchText";
                $array = $this->arrayQuery( $sql, array( 'column' => '0' ) );
                $count += is_array( $array ) && isset( $array[0] ) ? (int)$array[0] : 0;
            }
        }
        return $count;
    }

    function relationCount( $relationType = eZDBInterface::RELATION_TABLE )
    {
        $count = false;
        $relationInfo = $this->relationInfo( $relationType );
        if ( !$relationInfo )
        {
            eZDebug::writeError( "Unsupported relation type '$relationType'", 'eZOracleDB::relationCount' );
            return false;
        }

        if ( $this->isConnected() )
        {
            $field = $relationInfo['field'];
            $table = $relationInfo['table'];
            $ignoreName = false;
            if ( isset( $relationInfo['ignore_name'] ) )
                $ignoreName = $relationInfo['ignore_name'];

            $matchText = '';
            if ( $ignoreName )
            {
                $matchText = "WHERE LOWER( SUBSTR( $field, 0, " . strlen( $ignoreName) . " ) ) != '$ignoreName'";
            }
            $sql = "SELECT COUNT( $field ) as count FROM $table $matchText";
            $array = $this->arrayQuery( $sql, array( 'column' => '0' ) );
            $count = is_array( $array ) && isset( $array[0] ) ? (int)$array[0] : false;
        }
        return $count;
    }

    function relationList( $relationType = eZDBInterface::RELATION_TABLE )
    {
        $count = false;
        $relationInfo = $this->relationInfo( $relationType );
        if ( !$relationInfo )
        {
            eZDebug::writeError( "Unsupported relation type '$relationType'", 'eZOracleDB::relationList' );
            return false;
        }

        $array = array();
        if ( $this->isConnected() )
        {
            $field = $relationInfo['field'];
            $table = $relationInfo['table'];
            $ignoreName = false;
            if ( isset( $relationInfo['ignore_name'] ) )
                $ignoreName = $relationInfo['ignore_name'];

            $matchText = '';
            if ( $ignoreName )
            {
                $matchText = "WHERE LOWER( SUBSTR( $field, 0, " . strlen( $ignoreName ) . " ) ) != '$ignoreName'";
            }
            $sql = "SELECT LOWER( $field ) AS $field FROM $table $matchText";
            $array = $this->arrayQuery( $sql, array( 'column' => '0' ) );
        }
        return $array;
    }

    function eZTableList( $server = self::SERVER_MASTER )
    {
        $array = array();
        if ( $this->isConnected() )
        {
            foreach ( array( eZDBInterface::RELATION_TABLE, eZDBInterface::RELATION_SEQUENCE ) as $relationType )
            {
                $relationInfo = $this->relationInfo( $relationType );
                $field = $relationInfo['field'];
                $table = $relationInfo['table'];
                $ignoreName = false;
                if ( isset( $relationInfo['ignore_name'] ) )
                    $ignoreName = $relationInfo['ignore_name'];

                $matchText = '';
                if ( $ignoreName )
                {
                    $matchText = "WHERE LOWER( SUBSTR( $field, 0, " . strlen( $ignoreName ) . " ) ) != '$ignoreName'";
                }
                $sql = "SELECT LOWER( $field ) AS $field FROM $table $matchText";
                $names = $this->arrayQuery( $sql, array( 'column' => '0' ), $server );
                foreach ( is_array( $names ) ? $names : array() as $result )
                {
                    $array[$result] = $relationType;
                }
            }
        }
        return $array;
    }

    function relationMatchRegexp( $relationType )
    {
        if ( $relationType == eZDBInterface::RELATION_SEQUENCE )
            return "#^(ez|s_)#";
        else
            return "#^ez#";
    }

    function removeRelation( $relationName, $relationType )
    {
        $relationTypeName = $this->relationName( $relationType );
        if ( !$relationTypeName )
        {
            eZDebug::writeError( "Unsupported relation type '$relationType'", 'eZOracleDB::removeRelation' );
            return false;
        }

        if ( $this->isConnected() )
        {
            $sql = "DROP $relationTypeName $relationName";
            if ( $relationType == eZDBInterface::RELATION_TABLE )
            {
                // not into the recycle bin: cleaning a schema (setup's
                // DatabaseAction=remove) would keep all the space otherwise
                $sql .= " CASCADE CONSTRAINTS PURGE";
            }
            return $this->query( $sql );
        }
        return false;
    }

    function createTempTable( $createTableQuery = '', $server = self::SERVER_SLAVE )
    {
        $createTableQuery = preg_replace( '#CREATE\s+TEMPORARY\s+TABLE#', 'CREATE GLOBAL TEMPORARY TABLE', $createTableQuery );
        $createTableQuery .= " ON COMMIT PRESERVE ROWS";
        $this->query( $createTableQuery, $server );
    }

    /**
     * NB: this code should at least log a warning if the regexp does not match
     */
    function dropTempTable( $dropTableQuery = '', $server = self::SERVER_SLAVE )
    {
        if( preg_match( '#DROP\s+TABLE\s+(\S+)#', $dropTableQuery, $matches ) )
        {
            $this->query( 'TRUNCATE TABLE ' . $matches[1], $server );
        }

        $this->query( $dropTableQuery, $server );
    }

    /**
     * Sets Oracle sequence values to the maximum values used in the corresponding columns.
     */
    function correctSequenceValues()
    {
        if ( $this->isConnected() )
        {
            $triggers = array();
            $rows = $this->arrayQuery( "SELECT trigger_name, table_name, trigger_body FROM user_triggers WHERE table_name NOT LIKE 'BIN$%'" );
            foreach ( is_array( $rows ) ? $rows : array() as $row )
            {
                $triggers[] = array( 'trigger_name' => $row['trigger_name'],
                                     'table_name'   => $row['table_name'],
                                     'trigger_body' => $row['trigger_body'] );
            }

            $seqs = array();
            foreach ( $triggers as $triggerParams )
            {
                //$tableName   = $triggerParams['table_name'];
                $triggerBody = $triggerParams['trigger_body'];

                if ( preg_match( "/BEGIN\n" .
                                 " *IF :new.\S+ is null THEN\n" .
                                 " *SELECT (\S+).nextval INTO :new.(\S+) FROM dual;\n" .
                                 " *END IF;\n" .
                                 "END;/" , $triggerBody, $matches ) or
                     preg_match( "/BEGIN\n" .
                                 " *SELECT (\S+).nextval INTO :new.(\S+) FROM dual;\n" .
                                 "END;/", $triggerBody, $matches ) )
                {
                    $sequenceName = $matches[1];
                    $tableCol     = $matches[2];
                }
                else
                {
                    continue;
                }
                $seqs[$sequenceName] = array( $triggerParams['table_name'], $tableCol, $triggerParams['trigger_name'] );
            }

            foreach ( $seqs as $seq => $tableData )
            {
                list( $table, $col, $trig ) = $tableData;

                $rows = $this->arrayQuery( "SELECT MAX($col) AS max FROM $table" );
                $curColVal = isset( $rows[0]['max'] ) ? (int)$rows[0]['max'] : 0;
                $rows = $this->arrayQuery( "SELECT $seq.nextval AS nextval FROM DUAL" );
                if ( !isset( $rows[0]['nextval'] ) )
                {
                    eZDebug::writeError( "Could not read sequence $seq, its value was not corrected", __METHOD__ );
                    return false;
                }
                $curSeqVal = (int)$rows[0]['nextval'];
                $inc = $curColVal - $curSeqVal;

                if ( $inc == 0 ) // no need to increment
                {
                    continue;
                }

                if ( !$this->query( "DROP SEQUENCE $seq" ) )
                {
                    eZDebug::writeError( "Failed dropping sequence $seq for update, final sequence value '$curSeqVal' is different than max value '$curColVal'" );
                    return false;
                }
                if ( !$this->query( "CREATE SEQUENCE $seq MINVALUE ".($curColVal+1) ) )
                {
                    eZDebug::writeError( "Failed recreating sequence $seq for update. Trigger $trig left in invalid state" );
                    return false;
                }
                if ( !$this->query( "ALTER TRIGGER $trig COMPILE" ) )
                {
                    eZDebug::writeError( "Failed compiling trigger $trig after update of sequence $seq" );
                    return false;
                }
            }

            return true;
        }
        return false;
    }

    /**
     * This reimplementation differs a bit from the base version:
     *  a - it ignores the randomizeindex
     *  b - it has a finite number of retries
     *  A is most likely done to make sure that every temp table is used by only one php session, never many ones (advantages in dropping)
     *  B could be possibly removed. Especially considering that in such a case the returned temp table name is duplicate...
     */
    function generateUniqueTempTableName( $pattern, $randomizeIndex = false, $server = self::SERVER_SLAVE )
    {
        $maxTries = 10;
        do
        {
            $num = rand( 10000000, 99999999 );
            $tableName = strtoupper( str_replace( '%', $num, $pattern ) );
            $cntResult = $this->arrayQuery( "SELECT count(*) AS cnt FROM user_tables WHERE table_name='$tableName'", array(), $server );
            $maxTries--;
        } while( $cntResult && $cntResult[0]['cnt'] > 0 && $maxTries > 0 );

        if ( $maxTries == 0 )
        {
            eZDebug::writeError( "Tried to generate an unique temp table name for $maxTries time with no luck" );
        }

        return $tableName;
    }

    /**
     * Checks if the requested character set matches the one used in the database.
     * @return bool true if it matches or false if it differs.
     * @param string $currentCharset [out] The charset that the database uses.
     *                               will only be set if the match fails.
     *                               Note: This will be specific to the database.
     */
    function checkCharset( $charset, &$currentCharset )
    {
        // If we don't have a database yet we shouldn't check it
        if ( !$this->isConnected() )
        {
            return true;
        }

        //include_once( 'lib/ezi18n/classes/ezcharsetinfo.php' );

        if ( is_array( $charset ) )
        {
            foreach ( $charset as $charsetItem )
            {
                $realCharset[] = eZCharsetInfo::realCharsetCode( $charsetItem );
            }
        }
        else
        {
            $realCharset = eZCharsetInfo::realCharsetCode( $charset );
        }

        return $this->checkCharsetPriv( $realCharset, $currentCharset );
    }

    /**
     * @access private
     */
    function checkCharsetPriv( $charset, &$currentCharset )
    {
        $query = "SELECT VALUE FROM NLS_DATABASE_PARAMETERS WHERE PARAMETER = 'NLS_CHARACTERSET'";
        $rows = $this->arrayQuery( $query );
        if ( !isset( $rows[0]['value'] ) )
        {
            $currentCharset = false;
            return false;
        }
        $currentCharset = $rows[0]['value'];

//        include_once( 'lib/ezi18n/classes/ezcharsetinfo.php' );
//        $currentCharset = eZCharsetInfo::realCharsetCode( $currentCharset );

        $key = array_search( $currentCharset, $this->CharsetsMap );
        $unmappedCurrentCharset = ( $key === false ) ? $currentCharset : $key;

        if ( is_array( $charset ) )
        {
            if ( in_array( $unmappedCurrentCharset, $charset ) )
            {
                return $unmappedCurrentCharset;
            }
        }
        else if ( $unmappedCurrentCharset == $charset )
        {
            return true;
        }
        return false;
    }

    /**
     * The Oracle name of an Exponential charset (utf-8 -> AL32UTF8), or null
     * when there is none. The lookup does not depend on the case of the name.
     *
     * @param string $charset
     * @return string|null
     */
    function oracleCharset( $charset )
    {
        if ( !is_string( $charset ) || $charset === '' )
        {
            return null;
        }
        $charset = strtolower( eZCharsetInfo::realCharsetCode( $charset ) );
        foreach ( $this->CharsetsMap as $ezCharset => $oraCharset )
        {
            if ( strtolower( $ezCharset ) === $charset )
            {
                return $oraCharset;
            }
        }
        return null;
    }

    /**
     * The character set the database stores text in (NLS_CHARACTERSET), or false.
     *
     * @return string|bool
     */
    function databaseCharset()
    {
        if ( $this->DatabaseCharset === null )
        {
            $rows = $this->isConnected() ? $this->arrayQuery( "SELECT value FROM nls_database_parameters WHERE parameter = 'NLS_CHARACTERSET'" ) : false;
            if ( !isset( $rows[0]['value'] ) )
            {
                return false;
            }
            $this->DatabaseCharset = strtoupper( $rows[0]['value'] );
        }
        return $this->DatabaseCharset;
    }

    /**
     * True when text in $charset can be stored without loss: the charset has
     * an Oracle equivalent (the client converts to and from it) and the
     * database stores either that charset or Unicode (AL32UTF8, UTF8).
     * The setup wizard asks this for 'utf-8' to decide on a Unicode site.
     */
    function isCharsetSupported( $charset )
    {
        $oraCharset = $this->oracleCharset( $charset );
        if ( $oraCharset === null )
        {
            return false;
        }
        $dbCharset = $this->databaseCharset();
        if ( $dbCharset === false )
        {
            // not connected (yet): the client can at least convert it
            return true;
        }
        return $dbCharset === strtoupper( $oraCharset ) || in_array( $dbCharset, array( 'AL32UTF8', 'UTF8' ) );
    }

    function databaseClientVersion()
    {
        if ( !function_exists( 'oci_client_version' ) )
        {
            return false;
        }
        $versionInfo = oci_client_version();
        return array( 'string' => $versionInfo,
                      'values' => explode( '.', $versionInfo ) );
    }

    /**
     * Locks the tables until unlock(), like the PostgreSQL driver: a transaction
     * is started, and LOCK TABLE holds until it is committed.
     *
     * @param string|array $table a table name, or a list of array( 'table' => name )
     */
    function lock( $table )
    {
        if ( !$this->isConnected() )
        {
            return;
        }
        $this->begin();
        $tables = array();
        if ( is_array( $table ) )
        {
            foreach ( $table as $tableItem )
            {
                $tables[] = is_array( $tableItem ) ? $tableItem['table'] : $tableItem;
            }
        }
        else
        {
            $tables[] = $table;
        }
        if ( count( $tables ) > 0 )
        {
            $this->query( "LOCK TABLE " . implode( ', ', $tables ) . " IN EXCLUSIVE MODE" );
        }
    }

    function unlock()
    {
        if ( !$this->isConnected() )
        {
            return;
        }
        $this->commit();
    }

    function close()
    {
        if ( $this->DBConnection !== false )
        {
            oci_close( $this->DBConnection );
            $this->DBConnection = false;
        }
        $this->IsConnected  = false;
    }

    /**
     @static

     This function can be used to create a SQL IN statement to be used in a WHERE clause
     according to the description that can be found in the \c eZDBInterface class for the
     same function.

     According to the restriction of the Oracle database, which only allows a total amount
     of 1000 elements in an IN statement, this function will create multiple IN statements
     that are connected using \c OR or \c AND, depending on the \c $not parameter. Example:

     IN ( 1, ..., 1500 )

     will be

     IN ( 1, ...., 1000 ) OR IN ( 1001, ... , 1500 )

     and

     NOT IN ( 1, ..., 1500 )

     will be

     NOT IN ( 1, ...., 1000 ) AND NOT IN ( 1001, ... , 1500 )

     \return A string with the correct IN statement like for example
             "columnName IN ( element1, element2 )"
     */
    function generateSQLINStatement( $elements, $columnName = '', $not = false, $unique = true, $type = false )
    {
        $connector = ' OR ';
        $result    = '';
        $statement = ' IN';
        if ( $not === true )
        {
            $connector = ' AND ';
            $statement = ' NOT IN';
        }
        if ( !is_array( $elements ) )
        {
            $elements = array( $elements );
        }
        else
        {
            if ( $unique )
            {
                $elements = array_unique( $elements );
            }
        }
        $amountElements = count( $elements );
        $length = 1000;
        if ( $amountElements > $length )
        {
            $parts  = array();
            $offset = 0;
            while ( $offset < $amountElements )
            {
                if ( $type !== false )
                {
                    // implodeWithTypeCast() takes the array by reference
                    $slice = array_slice( $elements, $offset, $length );
                    $parts[] = $statement . ' ( ' . $this->implodeWithTypeCast( ', ', $slice, $type ) . ' )';
                }
                else
                {
                    $parts[] = $statement . ' ( ' . implode( ', ', array_slice( $elements, $offset, $length ) ) . ' )';
                }
                $offset += $length;
            }
            $result = ' ( ' . $columnName . implode( $connector . ' ' . $columnName, $parts ) . ' ) ';
        }
        else
        {
            if ( $type !== false )
            {
                $result = $columnName . $statement . ' ( ' . $this->implodeWithTypeCast( ', ', $elements, $type ) . ' )';
            }
            else
            {
                $result = $columnName . $statement . ' ( ' . implode( ', ', $elements ) . ' )';
            }
        }
        return $result;
    }

    /**
     * Works slightly differently from other databases, both beacuse of the way
     * oci-error calls work and because we retain backward compatibility (ie.
     * the code that calls this expects it to print ezdebugs too)
     */
    function setError( $statement = null, $functionName = '', $sql = '' )
    {
        if ( $statement !== null && $statement !== false )
        {
            $error = oci_error( $statement );
        }
        else
        {
            $error = oci_error();
        }

        // oci_error() returns false when there is no error to report
        if ( !is_array( $error ) || !$error['code'] )
        {
            return false;
        }

        $hasError = true;
        if ( $hasError )
        {
            $this->ErrorMessage = $error['message'];
            $this->ErrorNumber = $error['code'];
            if ( $functionName !== '' )
            {
                if ( isset( $error['sqltext'] ) && $error['sqltext'] !== '' )
                {
                    $sql = $error['sqltext'];
                }
                $sql = (string)$sql;
                if ( isset( $error['offset'] ) )
                {
                    $offset = $error['offset'];
                }
                else
                {
                    $offset = false;
                }
                if ( $offset !== false )
                {
                    $offsetText = ' at offset ' . $offset;
                    $sqlOffsetText = "\n\nStart of error:\n" . substr( $sql, $offset );
                }
                else
                {
                    $offsetText = '';
                    $sqlOffsetText = '';
                }
                eZDebug::writeError( "Error (" . $error['code'] . "): " . $error['message'] . "\n" .
                                     "Failed query$offsetText:\n" . $sql . $sqlOffsetText,
                                     "eZOracleDB::$functionName" );
            }
        }
        return $hasError;
    }

    function databaseServerVersion()
    {
        // available since oci8 1.1
        if ( !function_exists( 'oci_server_version') ||  !$this->isConnected() )
        {
            return false;
        }
        $banner = oci_server_version( $this->DBConnection );
        // since 18c the banner reads "... Release 19.0.0.0.0 - Production Version 19.3.0.0.0":
        // the full number is after "Version", "Release" only carries the major one
        if ( !is_string( $banner ) ||
             ( !preg_match( '#\bVersion ([0-9][0-9.]*)#', $banner, $matches ) &&
               !preg_match( '#\bRelease ([0-9][0-9.]*)#', $banner, $matches ) ) )
        {
            return false;
        }
        $versionInfo = rtrim( $matches[1], '.' );
        $versionArray = explode( '.', $versionInfo );
        return array( 'string' => $versionInfo,
                      'values' => $versionArray );
    }

    function supportsDefaultValuesInsertion()
    {
        return false;
    }

    /**
     * Used with array_walk to change charset encoding in mono dimensional arrays
     */
    static function arrayConvertStrings(&$value, $key, $codec )
    {
        $value = $codec->convertString( $value );
    }

    /**
     * Used with array_walk to change array keys to lower case in bi-dimensional arrays.
     * Optionally does charset conversion.
     */
    static function arrayChangeKeys(&$value, $key, $params )
    {
        if ( $params[0] )
        {
            array_walk( $value, array( 'eZOracleDB', 'arrayConvertStrings' ), $params[0] );
        }
        $value = array_combine( $params[1], $value );
    }

    /**
     * {@inheritdoc}
     *
     * Oracle's strings are truncated counting bytes instead of char
     */
    public function truncateString( $string, $maxLength, $fieldName, $truncationSuffix = '' )
    {
        if ( strlen( (string)$string ) <= $maxLength )
        {
            return $string;
        }

        eZDebug::writeDebug( $string, "truncation of $fieldName to max_length=". $maxLength );

        return mb_strcut( (string)$string, 0, $maxLength - strlen( (string)$truncationSuffix ), "utf-8" );
    }

    /**
     * {@inheritdoc}
     *
     * Oracle's string's size are bytes instead of char
     */
    public function countStringSize( $string )
    {
        if ( !is_string( $string ) )
        {
            return is_scalar( $string ) ? strlen( (string)$string ) : 0;
        }
        return strlen( $string );
    }

    /// \privatesection
    /// Database connection
    var $DBConnection;
    var $Mode;
    var $BindVariableArray = array();
    /// NLS_CHARACTERSET of the database, read once (see databaseCharset())
    public $DatabaseCharset = null;
    /// major version of the server, read once (see canAppendRowLimit())
    public $ServerMajorVersion = null;
    /// NULL in text columns is returned as '' (site.ini [DatabaseSettings] OracleEmptyStringForNull)
    public $EmptyStringForNullText = false;
    /// linguistic comparison and sorting (site.ini [DatabaseSettings] OracleCaseInsensitive)
    public $CaseInsensitive = false;
    /// the NLS_SORT used then (site.ini [DatabaseSettings] OracleCaseInsensitiveSort)
    public $CaseInsensitiveSort = 'BINARY_CI';

    /// ezoracle.ini [ConnectionSettings], see loadSettings() and INSTALL
    public $Persistent = false;
    public $DRCP = false;
    public $ConnectionClass = '';
    public $TnsAdmin = '';
    public $ConnectStringSetting = '';
    public $Hosts = array();
    public $ServiceName = '';
    public $Failover = true;
    public $LoadBalance = false;
    public $ConnectTimeout = 0;
    public $Edition = '';
    public $RetryCount = 0;
    public $RetryDelay = 1.0;
    public $RetryBackoff = 2.0;
    /// ORA- numbers that mean a lost or unreachable connection
    public $ReconnectErrors = array( 28, 1012, 1033, 1034, 1089, 3113, 3114, 3135, 12153, 12170, 12514, 12528, 12537, 12541, 12543, 12545, 12547, 12570, 25408 );
    public $RetryReads = true;
    public $CallTimeout = 0;
    public $KeepAliveInterval = 0;
    /// ezoracle.ini [PerformanceSettings]
    public $Prefetch = 0;
    public $LobPrefetch = 0;
    /// largest LOB prefetch size that reads CLOBs whole (oci8 3.4.1, Oracle client 23.26)
    const LOB_PREFETCH_MAX = 2000;
    public $CursorSharing = 'EXACT';
    /// ezoracle.ini [TraceSettings] and what was sent last
    public $TraceClientIdentifier = '';
    public $TraceModule = '';
    public $TraceAction = '';
    public $TraceClientInfo = '';
    public $TraceTags = array();
    /// ezoracle.ini [LogSettings]
    public $SlowQueryThreshold = 0;
    public $SlowQueryLog = 'oracle-slow.log';
    public $MaskLiterals = true;
    public $StatementCounts = false;
    /// counters of this connection object
    public $StatementCount = 0;
    public $ReconnectCount = 0;
    public $LastActivity = 0.0;

    // @todo move this to a static var, and we should shave off a little ram...
    var $CharsetsMap = array(
        'big5' => 'ZHT16BIG5',
        'euc-jp' => 'JA16EUC',
        'EUC-TW1' => 'ZHT32EUC',
        'gb2312' => 'ZHS16CGB231280',
        'ibm850' => 'WE38PC850',
        'ibm852' => 'EE8PC852',
        'ibm866' => 'RU8PC866',
        'iso-2022-cn2' => 'ISO2022-CN',
        'iso-2022-jp' => 'ISO2022-JP',
        'iso-2022-kr' => 'ISO2022-KR',
        'iso-8859-1' => 'WE8ISO8859P1',
        'iso-8859-2' => 'EE8ISO8859P2',
        'iso-8859-3' => 'SE8ISO8859P3',
        'iso-8859-4' => 'NEE8ISO8859P4',
        'iso-8859-5' => 'CL8ISO8859P5',
        'iso-8859-6' => 'AR8ISO8859P6',
        'iso-8859-7' => 'EL8ISO8859P7',
        'iso-8859-8' => 'IW8ISO8859P8',
        'iso-8859-9' => 'WE8ISO8859P9',
        'koi8-r' => 'CL8KOI8R',
        'ks_c_5601-1987' => 'KO16KSC5601',
        'shift_jis' => 'JA16SJIS',
        'TIS-620' => 'TH8TISASCII',
        'utf-8' => 'AL32UTF8',
        'windows-1250' => 'EE8MSWIN1250',
        'windows-1251' => 'CL8MSWIN1251',
        'windows-1252' => 'WE8MSWIN1252',
        'windows-1253' => 'EL8MSWIN1253',
        'windows-1254' => 'TR8MSWIN1254',
        'windows-1255' => 'IW8MSWIN1255',
        'windows-1256' => 'AR8MSWIN1256',
        'windows-1257' => 'BLT8MSWIN1257',
        'windows-1258' => 'VN8MSWIN1258',
        'windows-9361' => 'ZHS16GBK',
        'windows-949' => 'KO16MSWIN949',
        'windows-950' => 'ZHT16MSWIN950',
        );
}

?>
