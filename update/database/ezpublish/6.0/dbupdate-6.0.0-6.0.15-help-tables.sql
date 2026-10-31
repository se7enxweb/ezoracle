--
-- Exponential 6.0.15, Oracle: help maintained as content, exphelp_help_id and exphelp_feedback.
--
-- exphelp_help_id records every help_id that was released and the object it belongs to;
-- exphelp_feedback keeps the answers to "Was this helpful?", nothing about who answered.
-- Without the tables both features are off and every page works as before;
-- ./console exp:help:content install creates them too.
--
-- Text columns are nullable: Oracle stores an empty string as NULL, so NOT NULL DEFAULT ''
-- would refuse the rows the kernel writes. Fresh installs get the same from the schema handler
-- and settings/dbschema.ini.append.php (ColumnOptionTranslations).
--
-- Each table is created only when missing, so the file can run again.
--
-- Run with SQL*Plus or SQLcl as the schema owner.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPHELP_HELP_ID';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE TABLE exphelp_help_id (
  help_id VARCHAR2(80),
  object_id INTEGER DEFAULT 0 NOT NULL,
  released INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( help_id )
)';
        EXECUTE IMMEDIATE 'CREATE INDEX exphelp_help_id_object ON exphelp_help_id ( object_id )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPHELP_FEEDBACK';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_exphelp_feedback';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE exphelp_feedback (
  created INTEGER DEFAULT 0 NOT NULL,
  help_id VARCHAR2(80),
  helpful INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  language VARCHAR2(20),
  object_id INTEGER DEFAULT 0 NOT NULL,
  reason VARCHAR2(32),
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER exphelp_feedback_id_tr
BEFORE INSERT ON exphelp_feedback FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_exphelp_feedback.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE INDEX exphelp_feedback_object ON exphelp_feedback ( object_id, created )';
        EXECUTE IMMEDIATE 'CREATE INDEX exphelp_feedback_created ON exphelp_feedback ( created )';
    END IF;
END;
/
