# ezoracle: Oracle database driver for Exponential

## What is the ezoracle extension?

This extension adds support for the Oracle database to Exponential by
plugging into its database framework: the driver `eZOracleDB`, the schema
handler `eZOracleSchema`, console commands for maintaining the database, and
cronjobs. After it is installed an Exponential site can run on Oracle, and the
setup wizard, the kickstarter and `exp:install --db=oracle` can install onto it.

## Version

The current version is 2.3.1. The changes of this version are in
[doc/changelogs/2.3.1.md](doc/changelogs/2.3.1.md), older ones in doc/changelogs/.

## License

GNU General Public License v2.0 (or any later version). The complete license
is in the LICENSE file.

## Requirements

- **Exponential** 6.0.15 or later. Earlier versions do not offer Oracle in the
  setup and installer.
- **PHP** 8.0 to 8.5 with the **oci8** extension (PECL oci8 3.4 for PHP 8.4/8.5,
  3.2 for 8.1-8.3), built against an Oracle client (the Instant Client is enough).
- **Oracle Database** 19c or later. Tested with Oracle AI Database 26ai Free
  (23.26). The driver uses features from 12.1 (STANDARD_HASH, OFFSET/FETCH);
  11g and older are not supported.
- A database character set of **AL32UTF8** (Unicode).
- An Oracle user that may create sessions, tables, views, triggers,
  sequences and procedures (bin/php/ora-grant.php prints the SQL). The health
  and report commands read V$ views with SELECT_CATALOG_ROLE when it is granted.

## Installation

See [INSTALL](INSTALL): installation, configuration, the settings reference of
every feature, the console commands and the cronjobs.

## Features

- Connection resilience: persistent connections, DRCP (pooled servers),
  Easy Connect / TNS alias / descriptor connect strings, failover and load
  balancing over several hosts, connect retries with back-off, reconnect and
  read retry after a lost connection, a call timeout, and a keep-alive for long
  running processes (Velocity workers, CLI scripts) that survives a database restart.
- Performance: server-side OFFSET/FETCH for limits, row and LOB prefetch,
  one dictionary query per schema read.
- Tracing: client identifier, module, action and client info per request in
  V$SESSION (siteaccess, module/view, user), for DBAs and AWR/ASH reports.
- Observability: a slow query log with masked literals and bind values,
  statement counts in the debug output.
- Options for Oracle's semantics: '' as NULL (`OracleEmptyStringForNull`),
  case-insensitive comparison and sorting (`OracleCaseInsensitive`) with a
  helper for linguistic indexes.
- Console commands (`./bin/php/console ext:ezoracle:<name>`): health,
  gather-stats, recompile, purge-recyclebin, sequence-sync, schema-diff,
  indexes, report, datapump, ci-indexes.
- Cronjobs (off by default): nightly statistics, a health monitor that logs and
  mails, recycle bin purge, sequence check.
- DFS clustering backend for Oracle (eZDFSFileHandlerOracleBackend).

## Planned

Not in this release, as they could not be tested yet:

- External authentication (Oracle wallet / OS authentication, `OCI_CRED_EXT`).
- Transparent Application Continuity and FAN events.
- Identity columns instead of sequences and triggers on 23ai and later.

## Upgrading

From 2.3.0: see "Upgrading" in [doc/changelogs/2.3.1.md](doc/changelogs/2.3.1.md).

## Troubleshooting

Read the [FAQ](FAQ) first. Problems and questions: https://github.com/se7enxweb/ezoracle/issues
