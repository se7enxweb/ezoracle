#!/usr/bin/env php
<?php
/**
 * @description Linguistic (case-insensitive) indexes for OracleCaseInsensitive=enabled: list, create, drop
 *
 * ./bin/php/console ext:ezoracle:ci-indexes [--create|--drop] [--dry-run] [-s <siteaccess>] [--allow-root-user]
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
require_once 'autoload.php';
eZOracleTools::runScript( "With site.ini [DatabaseSettings] OracleCaseInsensitive=enabled Oracle compares\n" .
                          "linguistically (NLS_COMP=LINGUISTIC), and a plain index on a compared column is\n" .
                          "no longer used for =, LIKE or ORDER BY. This creates, for the columns of\n" .
                          "ezoracle.ini [CaseInsensitiveSettings] Columns[], indexes on\n" .
                          "NLSSORT( column, 'NLS_SORT=<OracleCaseInsensitiveSort>' ) that are. Without an\n" .
                          "option it lists which exist.",
                          '[create][drop][dry-run]', array( 'create' => 'create the missing indexes', 'drop' => 'drop them', 'dry-run' => 'show what would be done' ),
                          function ( eZOracleTools $tools, $options )
                          {
                              return $tools->caseInsensitiveIndexes( !empty( $options['create'] ) ? 'create' : ( !empty( $options['drop'] ) ? 'drop' : 'list' ) );
                          } );
?>
