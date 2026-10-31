--
-- Exponential 6.0.15, Oracle: the record of exp:upgrade, expupgrade_step.
--
-- One row per upgrade step exp:upgrade applied or tried: the step id, its stage and
-- version, done or failed, when, how long, by whom, on which engine, the checksum of the
-- step's code and the last message. Since the migration record
-- (dbupdate-6.0.0-6.0.15-migration-tables.sql) exp:upgrade writes there instead, and the rows
-- of this table are copied over; run this file first on a database that has neither.
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
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPUPGRADE_STEP';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE TABLE expupgrade_step (
  applied_at INTEGER DEFAULT 0 NOT NULL,
  applied_by VARCHAR2(150),
  checksum VARCHAR2(64),
  duration_ms INTEGER DEFAULT 0 NOT NULL,
  engine VARCHAR2(30),
  message VARCHAR2(1000),
  stage VARCHAR2(20),
  state VARCHAR2(20),
  step_id VARCHAR2(150),
  version VARCHAR2(30),
  PRIMARY KEY ( step_id )
)';
    END IF;
END;
/
