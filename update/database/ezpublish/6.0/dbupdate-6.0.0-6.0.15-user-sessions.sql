--
-- Exponential 6.0.15, Oracle: the signed-in sessions of the users, expuser_session and expuser_session_info.
--
-- One row per sign-in ("Your devices"): when it started and was last used, the address,
-- the browser, the sign-in method, and when and why it ended; expuser_session_info adds the
-- host, the place a local GeoIP database names and the second step of the sign-in. Neither
-- the session token nor the session id is stored. Without the tables nothing is tracked and
-- every page works as before. update/common/scripts/6.0/createusersessiontable.php (in the
-- kernel) creates the same tables.
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
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPUSER_SESSION';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expuser_session';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE expuser_session (
  browser VARCHAR2(64),
  created INTEGER DEFAULT 0 NOT NULL,
  device_type VARCHAR2(16),
  end_reason VARCHAR2(32),
  ended INTEGER DEFAULT 0 NOT NULL,
  ended_by INTEGER DEFAULT 0 NOT NULL,
  first_ip VARCHAR2(64),
  id INTEGER NOT NULL,
  last_active INTEGER DEFAULT 0 NOT NULL,
  last_ip VARCHAR2(64),
  method VARCHAR2(32),
  os VARCHAR2(64),
  remember_until INTEGER DEFAULT 0 NOT NULL,
  siteaccess VARCHAR2(64),
  token_hash VARCHAR2(64),
  user_agent VARCHAR2(512),
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER expuser_session_id_tr
BEFORE INSERT ON expuser_session FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expuser_session.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX expuser_session_token ON expuser_session ( token_hash )';
        EXECUTE IMMEDIATE 'CREATE INDEX expuser_session_user ON expuser_session ( user_id, ended )';
        EXECUTE IMMEDIATE 'CREATE INDEX expuser_session_active ON expuser_session ( ended, last_active )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPUSER_SESSION_INFO';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'CREATE TABLE expuser_session_info (
  auth_detail VARCHAR2(32),
  country VARCHAR2(2),
  host VARCHAR2(255),
  location VARCHAR2(128),
  session_id INTEGER DEFAULT 0 NOT NULL,
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( session_id )
)';
        EXECUTE IMMEDIATE 'CREATE INDEX expuser_session_info_user ON expuser_session_info ( user_id )';
    END IF;
END;
/
