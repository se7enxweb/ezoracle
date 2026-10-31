--
-- Exponential 6.0.15, Oracle: the audit index, expaudit_event, expaudit_cursor and expaudit_file.
--
-- One row per audit record (expaudit_event), how far each live channel file has been
-- indexed (expaudit_cursor) and each file's verification state (expaudit_file). The JSON lines
-- files under var/<site>/log/audit/ are the record; these tables are a copy for the console,
-- filled by the auditindex cronjob part and rebuilt from the files at any time. The full-text
-- index MySQL has on expaudit_event.search_text has no counterpart here: without it the
-- console searches with LIKE. update/common/scripts/6.0/createaudittables.php (in the kernel)
-- creates the same tables.
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
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPAUDIT_CURSOR';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE TABLE expaudit_cursor (
  byte_offset INTEGER DEFAULT 0 NOT NULL,
  channel VARCHAR2(32),
  file_name VARCHAR2(64),
  last_hash VARCHAR2(80),
  last_seq INTEGER DEFAULT 0 NOT NULL,
  updated_ms INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( channel, file_name )
)';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPAUDIT_EVENT';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE TABLE expaudit_event (
  channel VARCHAR2(32),
  depth INTEGER DEFAULT 0 NOT NULL,
  domain_name VARCHAR2(16),
  engine VARCHAR2(16),
  file_name VARCHAR2(64),
  id CHAR(26),
  imported INTEGER DEFAULT 0 NOT NULL,
  ip VARCHAR2(64),
  job_id VARCHAR2(32),
  login VARCHAR2(150),
  module_view VARCHAR2(128),
  name VARCHAR2(128),
  object_id VARCHAR2(64),
  object_name VARCHAR2(255),
  object_type VARCHAR2(32),
  parent_id CHAR(26),
  pseudonymised INTEGER DEFAULT 0 NOT NULL,
  reason VARCHAR2(32),
  record CLOB,
  request_id VARCHAR2(40),
  result VARCHAR2(8),
  run_id VARCHAR2(40),
  search_text CLOB,
  seq INTEGER DEFAULT 0 NOT NULL,
  session_h VARCHAR2(24),
  severity INTEGER DEFAULT 0 NOT NULL,
  siteaccess VARCHAR2(64),
  target_id VARCHAR2(64),
  target_type VARCHAR2(32),
  time_ms INTEGER DEFAULT 0 NOT NULL,
  ua VARCHAR2(128),
  user_id INTEGER,
  verb VARCHAR2(32),
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE INDEX expaudit_event_domain ON expaudit_event ( domain_name, severity, time_ms )';
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX expaudit_event_file_seq ON expaudit_event ( channel, file_name, seq )';
        EXECUTE IMMEDIATE 'CREATE INDEX expaudit_event_ip ON expaudit_event ( ip, time_ms )';
        EXECUTE IMMEDIATE 'CREATE INDEX expaudit_event_job ON expaudit_event ( job_id )';
        EXECUTE IMMEDIATE 'CREATE INDEX expaudit_event_name ON expaudit_event ( name, time_ms )';
        EXECUTE IMMEDIATE 'CREATE INDEX expaudit_event_object ON expaudit_event ( object_type, object_id, time_ms )';
        EXECUTE IMMEDIATE 'CREATE INDEX expaudit_event_parent ON expaudit_event ( parent_id )';
        EXECUTE IMMEDIATE 'CREATE INDEX expaudit_event_request ON expaudit_event ( request_id )';
        EXECUTE IMMEDIATE 'CREATE INDEX expaudit_event_result ON expaudit_event ( result, time_ms )';
        EXECUTE IMMEDIATE 'CREATE INDEX expaudit_event_time ON expaudit_event ( time_ms )';
        EXECUTE IMMEDIATE 'CREATE INDEX expaudit_event_user ON expaudit_event ( user_id, time_ms )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPAUDIT_FILE';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE TABLE expaudit_file (
  archive_path VARCHAR2(255),
  break_line INTEGER DEFAULT 0 NOT NULL,
  channel VARCHAR2(32),
  file_name VARCHAR2(64),
  records INTEGER DEFAULT 0 NOT NULL,
  state VARCHAR2(16) DEFAULT ''live'',
  verified VARCHAR2(16) DEFAULT ''unchecked'',
  verified_ms INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( channel, file_name )
)';
    END IF;
END;
/
