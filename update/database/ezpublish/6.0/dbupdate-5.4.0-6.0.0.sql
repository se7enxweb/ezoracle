--
-- Oracle: from 5.4.0 to Exponential 6.0.0, the same changes as the kernel's
-- update/database/mysql/6.0/dbupdate-5.4.0-6.0.0.sql and
-- update/database/postgresql/6.0/dbupdate-5.4-to-6.0.sql. The renaming of the PostgreSQL
-- sequences there has no Oracle counterpart: the sequences keep their s_<table> names.
--
-- Run with SQL*Plus or SQLcl as the schema owner. Every statement can run again.
--

UPDATE ezsite_data SET value='6.0.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';
COMMIT;

--
-- ezuser.password_hash takes the hashes Exponential writes.
--
-- A 5.x schema has password_hash VARCHAR2(50). Exponential stores php_default (bcrypt,
-- 60 characters) hashes and, with [UserSettings] UpdateHash=true, rewrites a user's hash
-- at the first sign-in, which does not fit (ORA-12899): the sign-in fails. 255 is the
-- width of the kernel schema. Widened only when the column is narrower, so a column
-- that is already 255 (or wider) is left as it is.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZUSER' AND column_name = 'PASSWORD_HASH' AND char_length < 255;
    IF n > 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezuser MODIFY password_hash VARCHAR2(255)';
    END IF;
END;
/

--
-- ezcontentobject_trash.trashed: the time an object was moved to the trash.
--
-- The 6.0 kernel writes it when content goes to the trash and sorts and filters the
-- trash by it, so without the column moving content to the trash fails. A 5.4 database
-- does not have it: upstream added it in its 7.3 update file (EZP-28881), which is not
-- on this path. Same definition as the kernel schema (INTEGER DEFAULT 0 NOT NULL), no
-- backfill. Added only when it is missing.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZCONTENTOBJECT_TRASH' AND column_name = 'TRASHED';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezcontentobject_trash ADD trashed INTEGER DEFAULT 0 NOT NULL';
    END IF;
END;
/
