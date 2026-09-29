#!/usr/bin/env php
<?php
/**
 * @description Checks (and with --fix moves) the auto-increment sequences behind their tables' ids
 *
 * ./bin/php/console ext:ezoracle:sequence-sync [--fix] [--dry-run] [-s <siteaccess>] [--allow-root-user]
 * Without the Exponential settings, bin/php/ora-update-seqs.php does the same with a login string.
 *
 * @copyright Copyright (C) 2026 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezoracle
 */
require_once 'autoload.php';
eZOracleTools::runScript( "Lists the auto-increment sequences (found through their BEFORE INSERT triggers)\n" .
                          "whose next values could collide with ids already in their table, e.g. after rows\n" .
                          "were imported with explicit ids. --fix moves them past the data\n" .
                          "(eZOracleDB::correctSequenceValues()). bin/php/ora-update-seqs.php does the same\n" .
                          "outside an installation, from a login string.",
                          '[fix][dry-run]', array( 'fix' => 'move the sequences that are behind', 'dry-run' => 'with --fix: show what would be done' ),
                          function ( eZOracleTools $tools, $options ) { return $tools->sequenceSync( !empty( $options['fix'] ) ); } );
?>
