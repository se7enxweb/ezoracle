#!/usr/bin/env php
<?php
/**
 * @description Oracle Data Pump export or import of the schema through DBMS_DATAPUMP (on the database server)
 *
 * ./bin/php/console ext:ezoracle:datapump --export=<file.dmp> | --import=<file.dmp> [--table-exists-action=SKIP|APPEND|TRUNCATE|REPLACE] [--remap-schema=FROM:TO] [-s <siteaccess>] [--allow-root-user]
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
require_once 'autoload.php';
eZOracleTools::runScript( "Exports the installation's schema to, or imports it from, a Data Pump dump file\n" .
                          "in the directory object of ezoracle.ini [DataPumpSettings] Directory (default\n" .
                          "DATA_PUMP_DIR) on the database server, with DBMS_DATAPUMP: the Instant Client on\n" .
                          "the web server has no expdp/impdp. The user needs READ, WRITE on the directory.\n" .
                          "The same with the server tools: expdp user@db schemas=USER directory=DATA_PUMP_DIR\n" .
                          "dumpfile=<file.dmp>; impdp ... table_exists_action=<action>.",
                          '[export:][import:][table-exists-action:][remap-schema:]',
                          array( 'export' => 'dump file to write', 'import' => 'dump file to read',
                                 'table-exists-action' => 'import: SKIP (default), APPEND, TRUNCATE or REPLACE', 'remap-schema' => 'import: FROM:TO' ),
                          function ( eZOracleTools $tools, $options )
                          {
                              if ( !empty( $options['export'] ) )
                                  return $tools->dataPump( 'export', $options['export'] );
                              if ( !empty( $options['import'] ) )
                                  return $tools->dataPump( 'import', $options['import'], array(
                                      'table_exists_action' => !empty( $options['table-exists-action'] ) ? $options['table-exists-action'] : 'SKIP',
                                      'remap_schema' => !empty( $options['remap-schema'] ) ? $options['remap-schema'] : '' ) );
                              return $tools->line( 'FAIL', 'give --export=<file.dmp> or --import=<file.dmp> (see --help)' );
                          } );
?>
