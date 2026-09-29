#!/usr/bin/env php
<?php
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ 0racle
// SOFTWARE RELEASE: 2.1.x
// COPYRIGHT NOTICE: Copyright (C) 1999-2013 eZ Systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

error_reporting( E_ALL );

$argc = count( $argv );

if ( $argc < 4 )
{
    print( "Usage: $argv[0] <username> <password> <tablespace>\n" .
           "Prints the SQL that creates the user and grants what Exponential needs.\n" .
           "Give - as the password to take it from the ORACLE_NEW_PASSWORD environment\n" .
           "variable instead of the command line (which other users can read in ps).\n" );
    exit( 1 );
}

$user = $argv[1];
$password = $argv[2];
$tablespace = $argv[3];
if ( $password === '-' )
{
    $password = getenv( 'ORACLE_NEW_PASSWORD' );
    if ( $password === false || $password === '' )
    {
        print( "ORACLE_NEW_PASSWORD is not set\n" );
        exit( 1 );
    }
}
// quoted: a password with other characters than letters, digits and _ $ # needs it
if ( strpos( $password, '"' ) !== false )
{
    print( "An Oracle password cannot contain a double quote\n" );
    exit( 1 );
}
$password = '"' . $password . '"';

$sql = "CREATE USER $user IDENTIFIED BY $password DEFAULT TABLESPACE $tablespace QUOTA UNLIMITED ON $tablespace;
GRANT CREATE    SESSION   TO $user;
GRANT CREATE    TABLE     TO $user;
GRANT CREATE    VIEW      TO $user;
GRANT CREATE    TRIGGER   TO $user;
GRANT CREATE    SEQUENCE  TO $user;
GRANT CREATE    PROCEDURE TO $user;";

print( $sql . "\n" );

?>
