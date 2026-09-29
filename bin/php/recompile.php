#!/usr/bin/env php
<?php
/**
 * @description Compiles the invalid objects of the Oracle schema (triggers, functions, views)
 *
 * ./bin/php/console ext:ezoracle:recompile [--dry-run] [-s <siteaccess>] [--allow-root-user]
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
require_once 'autoload.php';
eZOracleTools::runScript( "Compiles the invalid objects of the installation's schema with\n" .
                          "DBMS_UTILITY.COMPILE_SCHEMA (compile_all => FALSE) and lists any that stay\n" .
                          "invalid. A trigger that is invalid stops its table's inserts.",
                          '[dry-run]', array( 'dry-run' => 'show what would be done' ),
                          function ( eZOracleTools $tools ) { return $tools->recompile(); } );
?>
