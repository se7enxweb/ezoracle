<?php
/**
 * A stand-in for the oci8 functions, so the driver and the cluster backends can
 * be run without Oracle: every statement gives the rows of a scenario, and a
 * scenario can fail the execute, or the fetch after a number of rows, the way
 * oci8 does (oci_fetch_all() returns the rows fetched up to the error, or false;
 * oci_fetch_array() returns false; oci_error() then has the error, and after the
 * last row it has none).
 *
 * The functions are defined only where oci8 is not loaded; with oci8 the tests
 * that need them are skipped (run them with a PHP without oci8, e.g. `php -n`
 * plus the extensions PHPUnit needs).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

class eZOracleOci8StandIn
{
    /**
     * The scenarios, one per oci_execute() in turn; the last one stays for every
     * further execute. Keys: rows (list of rows, upper-case column names),
     * failAfter (rows handed out before the fetch error, null for none),
     * error (the error array of oci_error()), fetchAllFalse (oci_fetch_all()
     * returns false), executeError (oci_execute() fails with this error array).
     */
    public static $scenarios = array();
    public static $executes = 0;
    /** @var eZOracleOci8StandInStatement[] */
    public static $statements = array();

    public static function available()
    {
        return !extension_loaded( 'oci8' ) && function_exists( 'oci_fetch_all' ) &&
               ( new ReflectionFunction( 'oci_fetch_all' ) )->getFileName() === __FILE__;
    }

    public static function reset( array $scenarios = array() )
    {
        self::$scenarios = $scenarios ? array_values( $scenarios ) : array( array() );
        self::$executes = 0;
        self::$statements = array();
    }

    public static function scenario( array $scenario )
    {
        return $scenario + array( 'rows' => array(), 'failAfter' => null, 'fetchAllFalse' => false,
                                  'error' => array( 'code' => 1722, 'message' => 'ORA-01722: invalid number', 'offset' => 0, 'sqltext' => '' ),
                                  'executeError' => null );
    }

    public static function nextScenario()
    {
        $scenario = count( self::$scenarios ) > 1 ? array_shift( self::$scenarios ) : self::$scenarios[0];
        return self::scenario( $scenario );
    }

    /** rows with the column N: 1 .. $count */
    public static function numberRows( $count )
    {
        $rows = array();
        for ( $i = 1; $i <= $count; ++$i )
        {
            $rows[] = array( 'N' => $i );
        }
        return $rows;
    }

    /** true when every statement handed out was freed */
    public static function allFreed()
    {
        foreach ( self::$statements as $statement )
        {
            if ( !$statement->freed )
            {
                return false;
            }
        }
        return true;
    }
}

class eZOracleOci8StandInStatement
{
    public $sql;
    public $freed = false;
    public $executed = false;
    public $scenario;
    public $position = 0;
    public $error = false;

    public function check( $function )
    {
        if ( $this->freed )
        {
            // what oci8 does with a freed statement since PHP 8
            throw new TypeError( "$function(): supplied resource is not a valid oci8 statement resource" );
        }
    }

    /** the rows the next fetch can hand out, and whether the error comes after them */
    public function available()
    {
        $rows = $this->scenario['rows'];
        if ( $this->scenario['failAfter'] !== null )
        {
            return array( array_slice( $rows, 0, $this->scenario['failAfter'] ), true );
        }
        return array( $rows, false );
    }
}

if ( !extension_loaded( 'oci8' ) && !function_exists( 'oci_parse' ) )
{
    foreach ( array( 'OCI_DEFAULT' => 0, 'OCI_COMMIT_ON_SUCCESS' => 32, 'OCI_ASSOC' => 1, 'OCI_NUM' => 2, 'OCI_BOTH' => 3,
                     'OCI_RETURN_NULLS' => 4, 'OCI_RETURN_LOBS' => 8, 'OCI_FETCHSTATEMENT_BY_COLUMN' => 16,
                     'OCI_FETCHSTATEMENT_BY_ROW' => 32 ) as $name => $value )
    {
        if ( !defined( $name ) )
        {
            define( $name, $value );
        }
    }

    function oci_parse( $connection, $sql )
    {
        $statement = new eZOracleOci8StandInStatement();
        $statement->sql = $sql;
        eZOracleOci8StandIn::$statements[] = $statement;
        return $statement;
    }

    function oci_bind_by_name( $statement, $name, &$value, $maxLength = -1, $type = 0 )
    {
        $statement->check( __FUNCTION__ );
        return true;
    }

    function oci_set_prefetch( $statement, $rows )
    {
        return true;
    }

    function oci_set_prefetch_lob( $statement, $size )
    {
        return true;
    }

    function oci_execute( $statement, $mode = 32 )
    {
        $statement->check( __FUNCTION__ );
        ++eZOracleOci8StandIn::$executes;
        $statement->scenario = eZOracleOci8StandIn::nextScenario();
        $statement->executed = true;
        if ( $statement->scenario['executeError'] !== null )
        {
            $statement->error = $statement->scenario['executeError'];
            return false;
        }
        $statement->error = false;
        return true;
    }

    function oci_error( $resource = null )
    {
        if ( $resource instanceof eZOracleOci8StandInStatement )
        {
            $resource->check( __FUNCTION__ );
            return $resource->error;
        }
        return false;
    }

    function oci_fetch_all( $statement, &$output, $offset = 0, $limit = -1, $flags = 16 )
    {
        $statement->check( __FUNCTION__ );
        list( $rows, $fails ) = $statement->available();
        $output = array();
        if ( $statement->scenario['fetchAllFalse'] )
        {
            $statement->error = $fails ? $statement->scenario['error'] : false;
            return false;
        }
        // a limit of 0 or below is every row (oci8 counts the rows before it compares)
        $rows = array_slice( $rows, $offset, $limit <= 0 ? null : $limit );
        // the error only when the rows asked for reach it
        $statement->error = $fails && ( $limit <= 0 || $offset + $limit > $statement->scenario['failAfter'] )
            ? $statement->scenario['error'] : false;
        if ( $flags & OCI_FETCHSTATEMENT_BY_ROW )
        {
            foreach ( $rows as $row )
            {
                $output[] = ( $flags & OCI_NUM ) ? array_values( $row ) : $row;
            }
        }
        else
        {
            foreach ( $rows as $row )
            {
                $i = 0;
                foreach ( $row as $name => $value )
                {
                    $output[( $flags & OCI_NUM ) ? $i : $name][] = $value;
                    ++$i;
                }
            }
        }
        return count( $rows );
    }

    function oci_fetch_array( $statement, $mode = 3 )
    {
        $statement->check( __FUNCTION__ );
        list( $rows, $fails ) = $statement->available();
        if ( $statement->position >= count( $rows ) )
        {
            // after the last row: no error (OCI_NO_DATA is none for oci8)
            $statement->error = $fails ? $statement->scenario['error'] : false;
            return false;
        }
        $row = $rows[$statement->position++];
        if ( ( $mode & OCI_BOTH ) == OCI_NUM )
        {
            return array_values( $row );
        }
        if ( ( $mode & OCI_BOTH ) == OCI_BOTH )
        {
            return $row + array_values( $row );
        }
        return $row;
    }

    function oci_num_rows( $statement )
    {
        $statement->check( __FUNCTION__ );
        return count( $statement->scenario['rows'] );
    }

    function oci_num_fields( $statement )
    {
        $statement->check( __FUNCTION__ );
        return isset( $statement->scenario['rows'][0] ) ? count( $statement->scenario['rows'][0] ) : 0;
    }

    function oci_field_name( $statement, $column )
    {
        return array_keys( $statement->scenario['rows'][0] )[$column - 1];
    }

    function oci_field_type( $statement, $column )
    {
        return 'NUMBER';
    }

    function oci_free_statement( $statement )
    {
        $statement->check( __FUNCTION__ );
        $statement->freed = true;
        return true;
    }

    function oci_commit( $connection )
    {
        return true;
    }

    function oci_rollback( $connection )
    {
        return true;
    }

    function oci_close( $connection )
    {
        return true;
    }
}

?>
