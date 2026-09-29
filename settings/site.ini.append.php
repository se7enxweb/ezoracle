<?php /*
[DatabaseSettings]
ImplementationAlias[oracle]=eZOracleDB
ImplementationAlias[ezoracle]=eZOracleDB

# Oracle stores the empty string as NULL. With enabled (the default) the
# driver returns '' for NULL in text columns (CHAR, VARCHAR2, CLOB), as the
# other databases return the empty strings the application wrote.
# disabled returns NULL as Oracle has it.
OracleEmptyStringForNull=enabled

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
