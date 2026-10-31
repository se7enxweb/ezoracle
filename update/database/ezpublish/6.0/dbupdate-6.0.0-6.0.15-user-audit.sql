--
-- Exponential 6.0.15, Oracle: the user audit log, expuser_audit.
--
-- One row per security event about a user account or a user group: who, when, where, whom
-- and what. Filled from the audit records of the access channel; read on user/audit. Never
-- a password, a hash or a secret. update/common/scripts/6.0/createuseraudittable.php (in the
-- kernel) creates the same table.
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
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPUSER_AUDIT';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expuser_audit';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE expuser_audit (
  actor_id INTEGER DEFAULT 0 NOT NULL,
  actor_login VARCHAR2(150),
  client_ip VARCHAR2(64),
  created INTEGER DEFAULT 0 NOT NULL,
  event_id VARCHAR2(26),
  event_name VARCHAR2(128),
  host VARCHAR2(255),
  id INTEGER NOT NULL,
  module_view VARCHAR2(128),
  reason VARCHAR2(64),
  request_id VARCHAR2(40),
  result VARCHAR2(16),
  siteaccess VARCHAR2(64),
  target_id INTEGER DEFAULT 0 NOT NULL,
  target_login VARCHAR2(150),
  target_type VARCHAR2(16),
  time_ms INTEGER DEFAULT 0 NOT NULL,
  value_after CLOB,
  value_before CLOB,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER expuser_audit_id_tr
BEFORE INSERT ON expuser_audit FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expuser_audit.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX expuser_audit_event ON expuser_audit ( event_id )';
        EXECUTE IMMEDIATE 'CREATE INDEX expuser_audit_target ON expuser_audit ( target_id, created )';
        EXECUTE IMMEDIATE 'CREATE INDEX expuser_audit_actor ON expuser_audit ( actor_id, created )';
        EXECUTE IMMEDIATE 'CREATE INDEX expuser_audit_name ON expuser_audit ( event_name, created )';
        EXECUTE IMMEDIATE 'CREATE INDEX expuser_audit_created ON expuser_audit ( created )';
    END IF;
END;
/
