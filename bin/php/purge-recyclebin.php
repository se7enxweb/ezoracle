#!/usr/bin/env php
<?php
/**
 * @description Empties the Oracle recycle bin of the schema (PURGE RECYCLEBIN)
 *
 * ./bin/php/console ext:ezoracle:purge-recyclebin [--dry-run] [-s <siteaccess>] [--allow-root-user]
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
require_once 'autoload.php';
eZOracleTools::runScript( "Empties the recycle bin of the installation's schema: dropped tables, their\n" .
                          "indexes and LOBs, which keep their space until purged. Dropped objects cannot be\n" .
                          "brought back with FLASHBACK TABLE afterwards.",
                          '[dry-run]', array( 'dry-run' => 'show what would be done' ),
                          function ( eZOracleTools $tools ) { return $tools->purgeRecycleBin(); } );
?>
