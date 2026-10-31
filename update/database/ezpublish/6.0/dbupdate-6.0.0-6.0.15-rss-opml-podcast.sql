--
-- Exponential 6.0.15, Oracle: OPML and podcast exports, ezrss_export.opml_head, podcast_head and ezrss_export_opml_item.
--
-- An RSS export can be written as OPML, a list of feeds: its outlines are the rows of
-- ezrss_export_opml_item, each naming another export or a content node, and opml_head holds
-- the OPML head fields as JSON. podcast_head holds the channel fields of a podcast feed as
-- JSON. An installation without OPML or podcast exports never fills them in.
--
-- Text columns are nullable: Oracle stores an empty string as NULL, so NOT NULL DEFAULT ''
-- would refuse the rows the kernel writes. Fresh installs get the same from the schema handler
-- and settings/dbschema.ini.append.php (ColumnOptionTranslations).
--
-- Each table and column is added only when missing, so the file can run again.
--
-- Run with SQL*Plus or SQLcl as the schema owner.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZRSS_EXPORT' AND column_name = 'OPML_HEAD';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezrss_export ADD opml_head CLOB';
    END IF;
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZRSS_EXPORT' AND column_name = 'PODCAST_HEAD';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezrss_export ADD podcast_head CLOB';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EZRSS_EXPORT_OPML_ITEM';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE s_rss_export_opml_item';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE ezrss_export_opml_item (
  category VARCHAR2(255),
  created INTEGER DEFAULT 0 NOT NULL,
  description VARCHAR2(255),
  html_url VARCHAR2(255),
  id INTEGER NOT NULL,
  is_breakpoint INTEGER DEFAULT 0 NOT NULL,
  is_comment INTEGER DEFAULT 0 NOT NULL,
  language VARCHAR2(50),
  outline_text VARCHAR2(255),
  outline_type VARCHAR2(50) DEFAULT ''rss'',
  parent_id INTEGER DEFAULT 0 NOT NULL,
  priority INTEGER DEFAULT 0 NOT NULL,
  rssexport_id INTEGER DEFAULT 0 NOT NULL,
  source_node_id INTEGER DEFAULT 0 NOT NULL,
  status INTEGER DEFAULT 0 NOT NULL,
  subnodes INTEGER DEFAULT 0 NOT NULL,
  target_export_id INTEGER DEFAULT 0 NOT NULL,
  title VARCHAR2(255),
  url VARCHAR2(255),
  xml_url VARCHAR2(255),
  PRIMARY KEY ( id, status )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER ezrss_export_opml_item_id_tr
BEFORE INSERT ON ezrss_export_opml_item FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT s_rss_export_opml_item.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE INDEX ezrss_export_opml_rsseid ON ezrss_export_opml_item ( rssexport_id )';
    END IF;
END;
/
