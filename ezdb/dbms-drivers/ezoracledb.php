<?php
//
// Definition of eZOracleDB class
//
// Created on: <25-Feb-2002 14:50:11 ce>
//
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

        //$server = $this->Server;
        $user = $this->User;
        $password = $this->Password;
        $db = $this->DB;

        $this->ErrorMessage = false;
        $this->ErrorNumber = false;

        $ini = eZINI::instance();

        if ( function_exists( "oci_connect" ) )
        {
            $this->Mode = OCI_COMMIT_ON_SUCCESS;

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

            $maxAttempts = $this->connectRetryCount();
            $waitTime = $this->connectRetryWaitTime();
            $numAttempts = 1;
            if ( $ini->variable( "DatabaseSettings", "UsePersistentConnection" ) == "enabled" )
            {
                eZDebugSetting::writeDebug( 'kernel-db-oracle', $ini->variable( "DatabaseSettings", "UsePersistentConnection" ), "using persistent connection" );
                eZDebug::accumulatorStart( 'oracle_connection', 'oracle_total', 'Database connection' );
                $oldHandling = eZDebug::setHandleType( eZDebug::HANDLE_EXCEPTION );
                try {
                    $this->DBConnection = oci_pconnect( $user, $password, $db, $oraCharset );
                } catch( ErrorException $e ) {}
                eZDebug::accumulatorStop( 'oracle_connection' );
                eZDebug::setHandleType( $oldHandling );
                while ( $this->DBConnection == false and $numAttempts <= $maxAttempts )
                {
                    sleep( $waitTime );
                    eZDebug::accumulatorStart( 'oracle_connection', 'oracle_total', 'Database connection' );
                    $oldHandling = eZDebug::setHandleType( eZDebug::HANDLE_EXCEPTION );
                    try {
                        $this->DBConnection = oci_pconnect( $user, $password, $db, $oraCharset );
                    } catch( ErrorException $e ) {}
                    eZDebug::accumulatorStop( 'oracle_connection' );
                    eZDebug::setHandleType( $oldHandling );
                    $numAttempts++;
                }
            }
            else
            {
                eZDebugSetting::writeDebug( 'kernel-db-oracle', "using real connection",  "using real connection" );
                $oldHandling = eZDebug::setHandleType( eZDebug::HANDLE_EXCEPTION );
                eZDebug::accumulatorStart( 'oracle_connection', 'oracle_total', 'Database connection' );
                try {
                    $this->DBConnection = oci_connect( $user, $password, $db, $oraCharset );
                } catch( ErrorException $e ) {}
                eZDebug::accumulatorStop( 'oracle_connection' );
                eZDebug::setHandleType( $oldHandling );
                while ( $this->DBConnection == false and $numAttempts <= $maxAttempts )
                {
                    sleep( $waitTime );
                    $oldHandling = eZDebug::setHandleType( eZDebug::HANDLE_EXCEPTION );
                    eZDebug::accumulatorStart( 'oracle_connection', 'oracle_total', 'Database connection' );
                    try {
                        $this->DBConnection = @oci_connect( $user, $password, $db, $oraCharset );
                    } catch( ErrorException $e ) {}
                    eZDebug::accumulatorStop( 'oracle_connection' );
                    eZDebug::setHandleType( $oldHandling );
                    $numAttempts++;
                }
            }

//            OCIInternalDebug(1);

            if ( $this->DBConnection === false )
            {
                $this->IsConnected = false;
            }
            else
            {
                $this->IsConnected = true;
                // make sure the decimal separator is the dot, and that the lengths
                // of the VARCHAR2 columns the schema handler creates count
                // characters, not bytes, as the lengths in the .dba files do
                // (VARCHAR2(255) would hold ~85 CJK characters otherwise)
                $this->query( "ALTER SESSION SET NLS_NUMERIC_CHARACTERS='. ' NLS_LENGTH_SEMANTICS=CHAR" );
            }

            if ( $this->DBConnection === false )
            {
                $error = oci_error();

                // oci_error() gives false when the client could not even be
                // initialised (no client libraries, bad NLS settings, ...)
                if ( !is_array( $error ) )
                {
                    $error = array( 'code' => -1, 'message' => 'oci_connect() failed without an Oracle error (are the Oracle client libraries installed and found?)' );
                }

                if ( $error['code'] != 0 )
                {
                    if ( $error['code'] == 12541 )
                    {
                        $error['message'] = 'No listener (probably the server is down).';
                    }
                    $this->ErrorMessage = $error['message'];
                    $this->ErrorNumber = $error['code'];
                    eZDebug::writeError( "Connection error(" . $error["code"] . "):\n". $error["message"] .  " ", "eZOracleDB" );
                }

                throw new eZDBNoConnectionException( $db, $this->ErrorMessage, $this->ErrorNumber );
            }
        }
        else
        {
            $this->ErrorMessage = "Oracle support not compiled in PHP";
            $this->ErrorNumber = -1;
            eZDebug::writeError( $this->ErrorMessage, "eZOracleDB" );
            $this->IsConnected = false;
        }

        eZDebug::createAccumulatorGroup( 'oracle_total', 'Oracle Total' );
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

        $statement = oci_parse( $this->DBConnection, $sql );

        if ( $statement )
        {
            foreach ( $this->BindVariableArray as $bindVar )
            {
                oci_bind_by_name( $statement, $bindVar['dbname'], $bindVar['value'], -1 );
            }

            // was: we do not use $this->Mode here because we might have nested transactions
            // change was introduced in 2.0: we leave to parent class the handling
            // of nested transactions, and always use $this->Mode to commit
            // if needed
            $exec = @oci_execute( $statement, $this->Mode );
            if ( !$exec )
            {
                if ( $this->setError( $statement, 'query()', $sql ) )
                {
                    $result = false;
                }
            }
            /*else
            {
                // small api change: we do not commit if exec fails and oci_error says no error.
                // previously we did commit anyway...

                // Commit when we are not in a transaction and we use an 'autocommit' mode.
                // This is done because we execute queries in non-autocomiit mode, while
                // by default the db driver works in autocommit
                if ( $this->Mode != OCI_DEFAULT && $this->TransactionCounter == 0)
                {
                    oci_commit( $this->DBConnection );
                }
            }*/

            oci_free_statement( $statement );

        }
        else
        {
            if ( $this->setError( $this->DBConnection, 'query()', $sql ) )
            {
                $result = false;
            }
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

        $limit = -1;
        $offset = 0;
        $column = false;
        // check for array parameters
        if ( is_array( $params ) )
        {
            if ( isset( $params["limit"] ) and is_numeric( $params["limit"] ) )
            {
                $limit = $params["limit"];
            }
            if ( isset( $params["offset"] ) and is_numeric( $params["offset"] ) )
            {
                $offset = $params["offset"];
            }
            if ( isset( $params["column"] ) and ( is_numeric( $params["column"] ) or is_string( $params["column"]) ) )
            {
                $column = strtoupper( $params["column"] );
            }
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
        $statement = oci_parse( $this->DBConnection, $sql );
        if ( !$statement )
        {
            eZDebug::accumulatorStop( 'oracle_query' );
            $this->setError( $this->DBConnection, 'arrayQuery()', $sql );
            if ( $this->errorHandling == eZDB::ERROR_HANDLING_EXCEPTIONS )
            {
                throw new eZDBException( $this->ErrorMessage, $this->ErrorNumber );
            }
            return false;
        }
        if ( !@oci_execute( $statement, $this->Mode ) )
        {
            if ( $this->setError( $statement, 'arrayQuery()', $sql ) )
            {
                oci_free_statement( $statement );
                eZDebug::accumulatorStop( 'oracle_query' );
                if ( $this->errorHandling == eZDB::ERROR_HANDLING_EXCEPTIONS )
                {
                    throw new eZDBException( $this->ErrorMessage, $this->ErrorNumber );
                }
                return false;
            }
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

        //$numCols = oci_num_fields( $statement );
        $results = array();

        eZDebug::accumulatorStart( 'oracle_loop', 'oracle_total', 'Oracle looping results' );

        if ( $column !== false )
        {
            if ( is_numeric( $column ) )
            {
               $rowCount = oci_fetch_all( $statement, $results, $offset, $limit, OCI_FETCHSTATEMENT_BY_COLUMN + OCI_NUM );
            }
            else
            {
                $rowCount = oci_fetch_all( $statement, $results, $offset, $limit, OCI_FETCHSTATEMENT_BY_COLUMN + OCI_ASSOC );
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
            $rowCount = oci_fetch_all( $statement, $results, $offset, $limit, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC );
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
                $resultArray = $offset == 0 ? $results : array_combine( range( $offset, $offset + $rowCount - 1 ), $results );
            }
        }

        eZDebug::accumulatorStop( 'oracle_loop' );
        oci_free_statement( $statement );

        return $resultArray;
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
