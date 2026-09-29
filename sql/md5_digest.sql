CREATE OR REPLACE FUNCTION md5_digest (vin_string IN VARCHAR2)
RETURN VARCHAR2 DETERMINISTIC IS
--
-- Return an MD5 hash of the input string, as lower-case hex.
--
-- The driver does not need this function any more (eZOracleDB::md5() uses
-- STANDARD_HASH directly); it is kept for SQL written against older
-- versions of the extension. STANDARD_HASH exists since Oracle 12.1;
-- dbms_obfuscation_toolkit, used here before, was removed in Oracle 21c.
--
    r VARCHAR2(32);
BEGIN
    IF vin_string IS NULL THEN
        RETURN 'd41d8cd98f00b204e9800998ecf8427e';
    END IF;
    SELECT LOWER( RAWTOHEX( STANDARD_HASH( vin_string, 'MD5' ) ) ) INTO r FROM dual;
    RETURN r;
END md5_digest;
/
