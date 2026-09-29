<?php /*
[DatabaseSettings]
ImplementationAlias[oracle]=eZOracleDB
ImplementationAlias[ezoracle]=eZOracleDB

# Oracle stores the empty string as NULL. disabled (the default) returns NULL
# as Oracle has it. enabled returns '' for NULL in text columns (CHAR,
# VARCHAR2, CLOB and their N variants), as the other databases return the
# empty strings the application wrote; real NULLs in text columns then read
# as '' too. Numeric and other columns always keep NULL.
OracleEmptyStringForNull=disabled

# String comparison and sorting. Oracle compares strings binary and case
# sensitive; MySQL's *_ci collations do not. enabled sets, after connecting,
#   ALTER SESSION SET NLS_COMP=LINGUISTIC NLS_SORT=<OracleCaseInsensitiveSort>
# so =, LIKE, IN and ORDER BY ignore case (BINARY_CI) or case and accents
# (BINARY_AI). Plain indexes on the compared columns are then not used for
# those comparisons: create linguistic indexes for them with
#   php bin/php/console ext:ezoracle:ci-indexes --create
# (see INSTALL, "Case-insensitive comparisons").
OracleCaseInsensitive=disabled
OracleCaseInsensitiveSort=BINARY_CI

# Example configuration for connecting to an oracle db
#DatabaseImplementation=ezoracle
#User=scott
#Password=tiger
# The Oracle connect string: Easy Connect host:port/service_name, or a TNS alias
# (Server and Port are not used by the driver)
#Database=127.0.0.1:1521/FREEPDB1
# utf-8 (AL32UTF8) is the charset to use; empty means utf-8 too
#Charset=utf-8

*/ ?>
