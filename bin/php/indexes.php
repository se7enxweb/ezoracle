#!/usr/bin/env php
<?php
/**
 * @description Oracle index report, rebuild of unusable indexes, index usage monitoring
 *
 * ./bin/php/console ext:ezoracle:indexes [--rebuild [--index=<name>] [--online]] [--monitor-on|--monitor-off|--usage] [--dry-run] [-s <siteaccess>] [--allow-root-user]
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
require_once 'autoload.php';
eZOracleTools::runScript( "Without an option: unusable indexes and the 20 largest indexes with their\n" .
                          "height, leaf blocks, distinct keys, clustering factor and last analysis.\n" .
                          "--rebuild rebuilds the unusable indexes (or --index=<name>), --online without\n" .
                          "blocking writes (Enterprise Edition). --monitor-on / --monitor-off switch usage\n" .
                          "monitoring on the schema's indexes; --usage lists what was used (DBA_INDEX_USAGE\n" .
                          "on 12.2+, else USER_OBJECT_USAGE) and the non-unique indexes never used.",
                          '[rebuild][index:][online][monitor-on][monitor-off][usage][dry-run]',
                          array( 'rebuild' => 'rebuild unusable indexes', 'index' => 'the index to rebuild', 'online' => 'REBUILD ONLINE',
                                 'monitor-on' => 'start usage monitoring', 'monitor-off' => 'stop usage monitoring', 'usage' => 'report index usage',
                                 'dry-run' => 'show what would be done' ),
                          function ( eZOracleTools $tools, $options )
                          {
                              foreach ( array( 'rebuild', 'monitor-on', 'monitor-off', 'usage' ) as $action )
                                  if ( !empty( $options[$action] ) )
                                      return $tools->indexes( $action, (string)$options['index'], !empty( $options['online'] ) );
                              return $tools->indexes( 'report' );
                          } );
?>
