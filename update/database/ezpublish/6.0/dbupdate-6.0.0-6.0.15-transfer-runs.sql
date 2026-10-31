--
-- Exponential 6.0.15, Oracle: the run history of the import and export tools, exp_transfer_run.
--
-- One row per run of a tool of the transfer registry: tool, direction, kind, state, the
-- counts, the duration, the engine and job that ran it and the link to the tool's own page
-- of the run. update/common/scripts/6.0/createtransferruntable.php (in the kernel) creates
-- the same table.
--
-- Text columns are nullable: Oracle stores an empty string as NULL, so NOT NULL DEFAULT ''
-- would refuse the rows the kernel writes. Fresh installs get the same from the schema handler
-- and settings/dbschema.ini.append.php (ColumnOptionTranslations).
--
-- The table is created only when missing, so the file can run again.
--
-- Run with SQL*Plus or SQLcl as the schema owner.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXP_TRANSFER_RUN';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_exp_transfer_run';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE exp_transfer_run (
  bytes INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  detail CLOB,
  detail_url VARCHAR2(255),
  direction VARCHAR2(10),
  duration INTEGER DEFAULT 0 NOT NULL,
  engine VARCHAR2(16),
  error VARCHAR2(255),
  file_ref VARCHAR2(255),
  finished INTEGER DEFAULT 0 NOT NULL,
  format VARCHAR2(32),
  id INTEGER NOT NULL,
  job_ref VARCHAR2(64),
  kind VARCHAR2(20),
  modified INTEGER DEFAULT 0 NOT NULL,
  rows_done INTEGER DEFAULT 0 NOT NULL,
  rows_failed INTEGER DEFAULT 0 NOT NULL,
  rows_total INTEGER DEFAULT 0 NOT NULL,
  scope VARCHAR2(20),
  scope_ref VARCHAR2(255),
  siteaccess VARCHAR2(64),
  started INTEGER DEFAULT 0 NOT NULL,
  state VARCHAR2(16),
  title VARCHAR2(255),
  tool VARCHAR2(64),
  tool_ref VARCHAR2(64),
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER exp_transfer_run_id_tr
BEFORE INSERT ON exp_transfer_run FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_exp_transfer_run.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE INDEX exp_transfer_run_started ON exp_transfer_run ( started )';
        EXECUTE IMMEDIATE 'CREATE INDEX exp_transfer_run_tool ON exp_transfer_run ( tool, started )';
        EXECUTE IMMEDIATE 'CREATE INDEX exp_transfer_run_toolref ON exp_transfer_run ( tool, tool_ref )';
        EXECUTE IMMEDIATE 'CREATE INDEX exp_transfer_run_user ON exp_transfer_run ( user_id )';
    END IF;
END;
/
