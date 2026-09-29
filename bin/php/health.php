#!/usr/bin/env php
<?php
/**
 * @description Oracle health check: connection, versions, invalid objects, tablespaces, sessions, locks, sequences, statistics
 *
 * ./bin/php/console ext:ezoracle:health [-s <siteaccess>] [--allow-root-user]
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
require_once 'autoload.php';
eZOracleTools::runScript( "Checks the Oracle database of this installation: connectivity and round trip,\n" .
                          "server and client versions, character set, invalid objects, tablespace use,\n" .
                          "sessions and blocking locks, the recycle bin, auto-increment sequences, stale\n" .
                          "optimizer statistics and the driver's settings. One PASS/WARN/FAIL/INFO line per\n" .
                          "check; exit status 1 when one failed. V\$ views need SELECT_CATALOG_ROLE.",
                          '', array(),
                          function ( eZOracleTools $tools ) { return $tools->health(); } );
?>
