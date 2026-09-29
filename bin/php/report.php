#!/usr/bin/env php
<?php
/**
 * @description Oracle table size report and top SQL of the schema (from V$SQL)
 *
 * ./bin/php/console ext:ezoracle:report [--sizes] [--top-sql] [--top=<n>] [-s <siteaccess>] [--allow-root-user]
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
require_once 'autoload.php';
eZOracleTools::runScript( "--sizes: the largest tables of the schema with their data, index and LOB space\n" .
                          "and row counts (from the statistics). --top-sql: the statements of the schema\n" .
                          "with the most elapsed time in the shared pool, with the module and action the\n" .
                          "driver's [TraceSettings] set (needs SELECT_CATALOG_ROLE). Both without an option.",
                          '[sizes][top-sql][top:]', array( 'sizes' => 'table sizes', 'top-sql' => 'top statements from V$SQL', 'top' => 'number of lines (default 20 / 15)' ),
                          function ( eZOracleTools $tools, $options )
                          {
                              $both = empty( $options['sizes'] ) && empty( $options['top-sql'] );
                              $ok = true;
                              if ( $both || !empty( $options['sizes'] ) )
                                  $ok = $tools->tableSizes( !empty( $options['top'] ) ? (int)$options['top'] : 20 ) && $ok;
                              if ( $both || !empty( $options['top-sql'] ) )
                                  $ok = $tools->topSql( !empty( $options['top'] ) ? (int)$options['top'] : 15 ) && $ok;
                              return $ok;
                          } );
?>
