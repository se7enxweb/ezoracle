--
-- Exponential 6.0.15, Oracle: bookmark folders and bookmarked views.
--
-- A user can organise bookmarks in a tree of folders: ezcontentbrowsebookmark.folder_id is
-- the folder of a bookmark (0 is the top level, so every existing bookmark stays where it
-- was) and priority the order within the folder; expbookmark_folder holds the folders.
-- expbookmark_view keeps a bookmarked page that is not a node: its address and, parsed,
-- module_name, view_name and view_parameters. update/common/scripts/6.0/createbookmarkviewtable.php
-- (in the kernel) fills those from the address.
--
-- Text columns are nullable: Oracle stores an empty string as NULL, so NOT NULL DEFAULT ''
-- would refuse the rows the kernel writes. Fresh installs get the same from the schema handler
-- and settings/dbschema.ini.append.php (ColumnOptionTranslations).
--
-- Each table, column and index is added only when missing, so the file can run again.
--
-- Run with SQL*Plus or SQLcl as the schema owner.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZCONTENTBROWSEBOOKMARK' AND column_name = 'FOLDER_ID';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezcontentbrowsebookmark ADD folder_id INTEGER DEFAULT 0 NOT NULL';
    END IF;
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZCONTENTBROWSEBOOKMARK' AND column_name = 'PRIORITY';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezcontentbrowsebookmark ADD priority INTEGER DEFAULT 0 NOT NULL';
    END IF;
END;
/

--
-- The index ezcontentbrowsebookmark_folder, created only when no index of the table starts with
-- user_id, folder_id yet (the same columns twice would fail with ORA-01408).
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n
      FROM user_ind_columns c1
      JOIN user_ind_columns c2 ON c2.index_name = c1.index_name AND c2.column_name = 'FOLDER_ID' AND c2.column_position = 2
     WHERE c1.table_name = 'EZCONTENTBROWSEBOOKMARK' AND c1.column_name = 'USER_ID' AND c1.column_position = 1;
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE INDEX ezcontentbrowsebookmark_folder ON ezcontentbrowsebookmark ( user_id, folder_id )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPBOOKMARK_FOLDER';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expbookmark_folder';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE expbookmark_folder (
  created INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  name VARCHAR2(255),
  parent_id INTEGER DEFAULT 0 NOT NULL,
  priority INTEGER DEFAULT 0 NOT NULL,
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER expbookmark_folder_id_tr
BEFORE INSERT ON expbookmark_folder FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expbookmark_folder.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE INDEX expbookmark_folder_user ON expbookmark_folder ( user_id, parent_id )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPBOOKMARK_VIEW';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE TABLE expbookmark_view (
  bookmark_id INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  module_name VARCHAR2(100),
  module_uri CLOB,
  user_id INTEGER DEFAULT 0 NOT NULL,
  view_name VARCHAR2(100),
  view_parameters CLOB,
  PRIMARY KEY ( bookmark_id )
)';
        EXECUTE IMMEDIATE 'CREATE INDEX expbookmark_view_module ON expbookmark_view ( module_name, view_name )';
        EXECUTE IMMEDIATE 'CREATE INDEX expbookmark_view_user ON expbookmark_view ( user_id )';
    END IF;
END;
/

--
-- A table made by an early 6.0.15 build has only the address: its parsed columns and
-- their index are added when missing.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EXPBOOKMARK_VIEW' AND column_name = 'MODULE_NAME';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE expbookmark_view ADD module_name VARCHAR2(100)';
    END IF;
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EXPBOOKMARK_VIEW' AND column_name = 'VIEW_NAME';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE expbookmark_view ADD view_name VARCHAR2(100)';
    END IF;
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EXPBOOKMARK_VIEW' AND column_name = 'VIEW_PARAMETERS';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE expbookmark_view ADD view_parameters CLOB';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n
      FROM user_ind_columns c1
      JOIN user_ind_columns c2 ON c2.index_name = c1.index_name AND c2.column_name = 'VIEW_NAME' AND c2.column_position = 2
     WHERE c1.table_name = 'EXPBOOKMARK_VIEW' AND c1.column_name = 'MODULE_NAME' AND c1.column_position = 1;
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE INDEX expbookmark_view_module ON expbookmark_view ( module_name, view_name )';
    END IF;
END;
/
