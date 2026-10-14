CREATE OR REPLACE FUNCTION md5_digest (vin_string IN VARCHAR2)
RETURN VARCHAR2 IS
--
-- Return an MD5 hash of the input string, as lower-case hex.
--
-- For Oracle before 12.1, which has no STANDARD_HASH (sql/md5_digest.sql
-- needs it). dbms_obfuscation_toolkit, used here, was removed in Oracle 21c.
-- The driver only calls md5_digest() before 12.1 for SQL that is not a
-- string literal; the kernel passes literals, which the driver hashes in PHP.
--
BEGIN
    IF vin_string IS NULL THEN
        RETURN 'd41d8cd98f00b204e9800998ecf8427e';
    ELSE
        RETURN lower(dbms_obfuscation_toolkit.md5(input =>utl_raw.cast_to_raw(vin_string)));
    END IF;
END md5_digest;
/
