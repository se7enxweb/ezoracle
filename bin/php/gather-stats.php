#!/usr/bin/env php
<?php
/**
 * @description Gathers Oracle optimizer statistics of the schema (DBMS_STATS.GATHER_SCHEMA_STATS)
 *
 * ./bin/php/console ext:ezoracle:gather-stats [--dry-run] [-s <siteaccess>] [--allow-root-user]
 * Options come from ezoracle.ini [StatisticsSettings].
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
require_once 'autoload.php';
eZOracleTools::runScript( "Gathers the optimizer statistics of the installation's schema with\n" .
                          "DBMS_STATS.GATHER_SCHEMA_STATS; estimate, degree, cascade and GATHER option from\n" .
                          "ezoracle.ini [StatisticsSettings]. Run it after large imports; the cronjob part\n" .
                          "ezoracle does it nightly when [CronjobSettings] GatherStatistics=enabled.",
                          '[dry-run]', array( 'dry-run' => 'show what would be done' ),
                          function ( eZOracleTools $tools ) { return $tools->gatherStats(); } );
?>
