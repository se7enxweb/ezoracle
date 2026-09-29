<?php
/**
 * @description Checks the Oracle auto-increment sequences against their tables (SequenceCheck), moves them with SequenceFix
 *
 * Cronjob part: ezoracle, ezoracle_sequences (settings/cronjob.ini.append.php).
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
$tools = eZOracleTools::forCronjob( 'SequenceCheck', $cli ?? null );
if ( $tools )
{
    $ini = eZINI::instance( 'ezoracle.ini' );
    $fix = $ini->hasVariable( 'CronjobSettings', 'SequenceFix' ) && $ini->variable( 'CronjobSettings', 'SequenceFix' ) === 'enabled';
    $tools->sequenceSync( $fix );
    $tools->finishCronjob( 'sequences' );
}
?>
