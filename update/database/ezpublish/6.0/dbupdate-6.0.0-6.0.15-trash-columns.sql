--
-- Exponential 6.0.15, Oracle: ezcontentobject_trash.trashed_by and trashed_via.
--
-- Who moved an object to the trash (the user's content object id, 0 when not
-- known) and from where ("web <siteaccess>" or "cli <script>"), written by the
-- kernel in the trash row since Exponential 6.0.15. Until the columns exist the
-- kernel stores the row without them and keeps writing <VarDir>/trash/trashed.json;
-- afterwards update/common/scripts/6.0/movetrashrecords.php (in the kernel) copies
-- that file into the columns.
--
-- trashed_via is nullable: Oracle stores an empty string as NULL, so NOT NULL
-- DEFAULT '' would refuse a row without it. Fresh installs get the same from
-- settings/dbschema.ini.append.php (ColumnOptionTranslations).
--
-- Each column and the index are added only when missing, so the file can run again.
-- Run with SQL*Plus or SQLcl as the schema owner.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZCONTENTOBJECT_TRASH' AND column_name = 'TRASHED_BY';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezcontentobject_trash ADD trashed_by INTEGER DEFAULT 0 NOT NULL';
    END IF;
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZCONTENTOBJECT_TRASH' AND column_name = 'TRASHED_VIA';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezcontentobject_trash ADD trashed_via VARCHAR2(100)';
    END IF;
END;
/

--
-- The index ezcobj_trash_trashed_by of Exponential 6.0.15 (share/db_schema.dba of the
-- kernel): the trash view counts the items per user and filters by user. Created only
-- when no index of ezcontentobject_trash starts with trashed_by yet (a second one on
-- the same column would fail with ORA-01408), so a database that already has one, under
-- this or another name, is left as it is. Needs the columns above.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_ind_columns
     WHERE table_name = 'EZCONTENTOBJECT_TRASH' AND column_name = 'TRASHED_BY' AND column_position = 1;
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE INDEX ezcobj_trash_trashed_by ON ezcontentobject_trash ( trashed_by )';
    END IF;
END;
/
