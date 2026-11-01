--
-- Oracle: from 5.3.0 to 5.4.0 with a DFS cluster (eZDFSFileHandlerOracleBackend), the same
-- change as the kernel's update/database/mysql/5.4/dbupdate-cluster-5.3.0-to-5.4.0.sql
-- (EZP-23408): image files are stored with the scope 'image', older rows say 'images'.
--
-- Run with SQL*Plus or SQLcl as the owner of the cluster table, which can be another schema
-- or database than the content. Nothing happens when ezdfsfile does not exist; the file can
-- run again.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EZDFSFILE';
    IF n > 0 THEN
        EXECUTE IMMEDIATE 'UPDATE ezdfsfile SET scope = ''image'' WHERE scope = ''images''';
        COMMIT;
    END IF;
END;
/
