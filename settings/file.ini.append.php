<?php /*

[ClusteringSettings]
ExtensionDirectories[]=ezoracle

# Example configuration for DFS cluster mode with Oracle (settings/override/file.ini.append.php).
# The metadata table ezdfsfile is created from the SQL at the top of
# extension/ezoracle/clusterfilehandlers/dfsbackends/oracle.php.
#
#[ClusteringSettings]
#FileHandler=eZDFSFileHandler
#
#[eZDFSClusteringSettings]
#MountPointPath=/path/to/the/nfs/mount
#DBBackend=eZDFSFileHandlerOracleBackend
## the Oracle connect string (Easy Connect host:port/service_name, or a TNS alias)
#DBName=127.0.0.1:1521/FREEPDB1
#DBUser=scott
#DBPassword=tiger
#DBConnectRetries=3
#DBExecuteRetries=20
#DBPersistentConnection=disabled
#
# The eZDBFileHandler cluster mode (clusterfilehandlers/dbbackends/oracle.php)
# needs a file handler this kernel no longer has; it is not supported.

*/ ?>
