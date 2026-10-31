--
-- Exponential 6.0.15, Oracle: the migration record, exp_migration and exp_migration_run.
--
-- One row per migration in exp_migration (the key is its source and id) and one per run in
-- exp_migration_run. exp_migration replaces expupgrade_step: exp:upgrade writes its steps
-- there as migrations of the source "kernel", and the rows expupgrade_step has are copied
-- over (the upgrade step 6.0.15-migration-tables does the same).
--
-- Text columns are nullable: Oracle stores an empty string as NULL, so NOT NULL DEFAULT ''
-- would refuse the rows the kernel writes. Fresh installs get the same from the schema handler
-- and settings/dbschema.ini.append.php (ColumnOptionTranslations).
-- Only the keys (source, migration_id, run_id) stay NOT NULL, through their primary keys.
--
-- Each table is created only when missing, and the copy leaves out a row that exp_migration
-- has already, so the file can run again.
--
-- Run with SQL*Plus or SQLcl as the schema owner.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXP_MIGRATION';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE TABLE exp_migration (
  caption_id VARCHAR2(150),
  checksum VARCHAR2(64),
  duration_ms INTEGER DEFAULT 0 NOT NULL,
  engine VARCHAR2(30),
  environment VARCHAR2(50),
  file_path VARCHAR2(255),
  finished_at INTEGER DEFAULT 0 NOT NULL,
  message VARCHAR2(1000),
  migration_id VARCHAR2(150),
  revision VARCHAR2(64),
  run_by VARCHAR2(150),
  run_id VARCHAR2(64),
  source VARCHAR2(100),
  stage VARCHAR2(20),
  started_at INTEGER DEFAULT 0 NOT NULL,
  state VARCHAR2(20),
  version VARCHAR2(30),
  PRIMARY KEY ( source, migration_id )
)';
        EXECUTE IMMEDIATE 'CREATE INDEX exp_migration_run_id ON exp_migration ( run_id )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXP_MIGRATION_RUN';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE TABLE exp_migration_run (
  caption_post VARCHAR2(150),
  caption_pre VARCHAR2(150),
  commit_id VARCHAR2(64),
  environment VARCHAR2(50),
  finished_at INTEGER DEFAULT 0 NOT NULL,
  log_file VARCHAR2(255),
  message VARCHAR2(1000),
  push_state VARCHAR2(20),
  run_by VARCHAR2(150),
  run_id VARCHAR2(64),
  started_at INTEGER DEFAULT 0 NOT NULL,
  state VARCHAR2(20),
  tag_name VARCHAR2(150),
  PRIMARY KEY ( run_id )
)';
        EXECUTE IMMEDIATE 'CREATE INDEX exp_migration_run_started ON exp_migration_run ( started_at )';
    END IF;
END;
/

--
-- The record of expupgrade_step, copied as migrations of the source "kernel": started_at is
-- worked out from when the step finished and how long it took, and a caption step keeps its
-- caption id (the step id after "caption:"). Only when expupgrade_step exists; a row
-- exp_migration has already is left out.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPUPGRADE_STEP';
    IF n = 1 THEN
        EXECUTE IMMEDIATE '
INSERT INTO exp_migration ( source, migration_id, stage, version, state, started_at, finished_at, duration_ms, run_by, engine, checksum, message, caption_id )
  SELECT ''kernel'', s.step_id, s.stage, s.version, s.state,
         CASE WHEN s.applied_at > 0 THEN GREATEST( s.applied_at - ROUND( s.duration_ms / 1000 ), 0 ) ELSE 0 END,
         s.applied_at, s.duration_ms, s.applied_by, s.engine, s.checksum, s.message,
         CASE WHEN s.stage = ''caption'' AND s.step_id LIKE ''caption:%'' THEN SUBSTR( s.step_id, 9 ) END
    FROM expupgrade_step s
   WHERE NOT EXISTS ( SELECT 1 FROM exp_migration m WHERE m.source = ''kernel'' AND m.migration_id = s.step_id )';
        COMMIT;
    END IF;
END;
/
