<?php
/**
 * @description Empties the Oracle recycle bin of the schema, when ezoracle.ini [CronjobSettings] PurgeRecycleBin=enabled
 *
 * Cronjob part: ezoracle, ezoracle_recyclebin (settings/cronjob.ini.append.php).
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
$tools = eZOracleTools::forCronjob( 'PurgeRecycleBin', $cli ?? null );
if ( $tools )
{
    $tools->purgeRecycleBin();
    $tools->finishCronjob( 'recycle bin' );
}
?>
