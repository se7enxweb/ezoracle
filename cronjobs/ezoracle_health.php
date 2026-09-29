<?php
/**
 * @description Oracle health monitor: logs the health check and mails it on a warning or failure, when HealthMonitor=enabled
 *
 * Cronjob part: ezoracle, ezoraclehealth (settings/cronjob.ini.append.php). The
 * report goes to var/log/ezoracle-health.log; with ezoracle.ini [CronjobSettings]
 * HealthMailReceivers[] set it is also mailed when a check warns or fails.
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
$tools = eZOracleTools::forCronjob( 'HealthMonitor', $cli ?? null );
if ( $tools )
{
    $tools->health();
    $tools->finishCronjob( 'health', true );
}
?>
