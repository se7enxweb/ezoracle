#!/usr/bin/env php
<?php
/**
 * @description Compares the Oracle schema with a .dba file (default share/db_schema.dba) and prints the upgrade SQL
 *
 * ./bin/php/console ext:ezoracle:schema-diff [--file=<dba>] [--all-tables] [-s <siteaccess>] [--allow-root-user]
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
require_once 'autoload.php';
eZOracleTools::runScript( "Reads the schema of the installation's database and compares it with a .dba\n" .
                          "schema file (the kernel's share/db_schema.dba, or an extension's), both in their\n" .
                          "Oracle form, as the schema checker does; lists every difference and the SQL that\n" .
                          "would bring the database to the file. Only the file's tables are compared unless\n" .
                          "--all-tables is given.",
                          '[file:][all-tables]', array( 'file' => 'the .dba file (default share/db_schema.dba)', 'all-tables' => 'also list tables the file does not define' ),
                          function ( eZOracleTools $tools, $options )
                          {
                              return $tools->schemaDiff( !empty( $options['file'] ) ? $options['file'] : 'share/db_schema.dba', empty( $options['all-tables'] ) );
                          } );
?>
