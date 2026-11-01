--
-- Oracle: from 4.7.0 to 5.0.0, the same changes as the kernel's
-- update/database/mysql/5.0/dbupdate-4.7.0-to-5.0.0.sql and
-- update/database/postgresql/5.0/dbupdate-4.7.0-to-5.0.0.sql.
--
-- Run with SQL*Plus or SQLcl as the schema owner. Every statement can run again.
--

UPDATE ezsite_data SET value='5.0.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';
COMMIT;

--
-- See http://issues.ez.no/19169
--
-- dropping the primary key (contentobject_state_group_id, language_id) and creating
-- the new one (contentobject_state_group_id, real_language_id) are done in the
-- PHP script after removing the duplicated entries
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZCOBJ_STATE_GROUP_LANGUAGE' AND column_name = 'REAL_LANGUAGE_ID';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezcobj_state_group_language ADD real_language_id INTEGER DEFAULT 0 NOT NULL';
    END IF;
    EXECUTE IMMEDIATE 'UPDATE ezcobj_state_group_language SET real_language_id = language_id - bitand(language_id, 1)';
    COMMIT;
END;
/

--
-- See http://issues.ez.no/19397
-- Normalize database so that updates to email address automatically affects digest settings
--
-- The digest settings of an address belong to the user with that e-mail address. When
-- several users share the address, the lowest user id is taken (the kernel's files take
-- one of them with LIMIT 1); a plain subquery returned several rows there and the update
-- failed with ORA-01427. A setting whose address belongs to no user is removed first;
-- NOT EXISTS, since NOT IN removes nothing as soon as one user has no e-mail address
-- (Oracle stores the empty string as NULL), and the update then failed with ORA-01407.
-- Done only while the address column is there.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZGENERAL_DIGEST_USER_SETTINGS' AND column_name = 'USER_ID';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezgeneral_digest_user_settings ADD user_id INTEGER DEFAULT 0 NOT NULL';
    END IF;
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZGENERAL_DIGEST_USER_SETTINGS' AND column_name = 'ADDRESS';
    IF n > 0 THEN
        EXECUTE IMMEDIATE 'DELETE FROM ezgeneral_digest_user_settings s
            WHERE NOT EXISTS ( SELECT 1 FROM ezuser u WHERE u.email = s.address )';
        EXECUTE IMMEDIATE 'UPDATE ezgeneral_digest_user_settings s
            SET user_id = ( SELECT MIN( u.contentobject_id ) FROM ezuser u WHERE u.email = s.address )';
        COMMIT;
    END IF;
    SELECT COUNT(*) INTO n FROM user_indexes WHERE index_name = 'EZGENERAL_DIGEST_USER_ID';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX ezgeneral_digest_user_id ON ezgeneral_digest_user_settings (user_id)';
    END IF;
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZGENERAL_DIGEST_USER_SETTINGS' AND column_name = 'ADDRESS';
    IF n > 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezgeneral_digest_user_settings DROP COLUMN address';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_indexes WHERE index_name = 'EZUSER_LOGIN';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE INDEX ezuser_login ON ezuser ( login )';
    END IF;
END;
/

--
-- eznode_assignment.remote_id becomes VARCHAR2(100), as in the kernel's files. Oracle
-- cannot change the type of a column that holds values, so a new column takes the
-- values and replaces the old one, as dbupdate-5.0.0-to-5.1.0.sql does. Done only while
-- the column is a number.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZNODE_ASSIGNMENT' AND column_name = 'REMOTE_ID' AND data_type = 'NUMBER';
    IF n > 0 THEN
        SELECT COUNT(*) INTO n FROM user_tab_columns
         WHERE table_name = 'EZNODE_ASSIGNMENT' AND column_name = 'REMOTE_ID2';
        IF n = 0 THEN
            EXECUTE IMMEDIATE 'ALTER TABLE eznode_assignment ADD ( remote_id2 VARCHAR2(100) DEFAULT ''0'' NOT NULL )';
        END IF;
        EXECUTE IMMEDIATE 'UPDATE eznode_assignment SET remote_id2 = NVL( TO_CHAR( remote_id ), ''0'' )';
        COMMIT;
        EXECUTE IMMEDIATE 'ALTER TABLE eznode_assignment DROP COLUMN remote_id';
        EXECUTE IMMEDIATE 'ALTER TABLE eznode_assignment RENAME COLUMN remote_id2 TO remote_id';
    END IF;
END;
/

--
-- Dropping a column leaves the id triggers of the table invalid until their next use;
-- compiled here so the schema has no invalid object.
--

BEGIN
    FOR t IN ( SELECT o.object_name FROM user_objects o JOIN user_triggers r ON r.trigger_name = o.object_name
                WHERE o.object_type = 'TRIGGER' AND o.status != 'VALID'
                  AND r.table_name IN ( 'EZGENERAL_DIGEST_USER_SETTINGS', 'EZNODE_ASSIGNMENT' ) ) LOOP
        EXECUTE IMMEDIATE 'ALTER TRIGGER "' || t.object_name || '" COMPILE';
    END LOOP;
END;
/
