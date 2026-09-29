<?php
/**
 * @description Nightly Oracle optimizer statistics of the schema, when ezoracle.ini [CronjobSettings] GatherStatistics=enabled
 *
 * Cronjob part: ezoracle, ezoracle_statistics (settings/cronjob.ini.append.php).
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
$tools = eZOracleTools::forCronjob( 'GatherStatistics', $cli ?? null );
if ( $tools )
{
    $tools->gatherStats();
    $tools->finishCronjob( 'statistics' );
}
?>
