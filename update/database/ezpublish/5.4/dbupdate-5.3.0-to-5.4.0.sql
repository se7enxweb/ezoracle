--
-- Oracle: from 5.3.0 to 5.4.0, the same changes as the kernel's update/database/mysql/5.4/
-- and update/database/postgresql/5.4/dbupdate-5.3.0-to-5.4.0.sql.
--
-- Run with SQL*Plus or SQLcl as the schema owner. Every statement can run again.
--

UPDATE ezsite_data SET value='5.4.0alpha1' WHERE name='ezpublish-version';

--
-- ezsearch_return_count is no longer written or read (EZP-23082) and the kernel schema
-- has no such table. Its index and its trigger go with the table; the sequence of its
-- auto_increment column (s_search_return_count) is dropped as well. Each only when it
-- exists.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EZSEARCH_RETURN_COUNT';
    IF n > 0 THEN
        EXECUTE IMMEDIATE 'DROP TABLE ezsearch_return_count';
    END IF;
    SELECT COUNT(*) INTO n FROM user_sequences WHERE sequence_name = 'S_SEARCH_RETURN_COUNT';
    IF n > 0 THEN
        EXECUTE IMMEDIATE 'DROP SEQUENCE s_search_return_count';
    END IF;
END;
/

--
-- An unset date or date and time attribute is stored as NULL since 5.4 (EZP-23019), no
-- longer as 0. As in the kernel's files: skip these two statements when updating from
-- 5.3.3 or higher, where 0 already means 1 January 1970 and must stay.
--

UPDATE ezcontentobject_attribute
    SET data_int = NULL
WHERE
    data_int = 0
    AND data_type_string IN ( 'ezdate', 'ezdatetime' );

UPDATE ezinfocollection_attribute
    SET data_int = NULL
WHERE
    data_int = 0
    AND contentclass_attribute_id IN ( SELECT id FROM ezcontentclass_attribute
                                       WHERE data_type_string IN ( 'ezdate', 'ezdatetime' ) );

--
-- EZP-23595: settings rows of users that no longer exist.
--

DELETE FROM ezuser_setting WHERE user_id NOT IN ( SELECT contentobject_id FROM ezuser );

COMMIT;
