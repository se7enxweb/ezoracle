--
-- Exponential 6.0.15, Oracle: personal API keys, expapikey.
--
-- One row per key a user made on the API access page (apikey/list). key_prefix is the
-- public part of the key; the secret is never stored, only its keyed hash. Until the table
-- exists no key can be made. update/common/scripts/6.0/createapikeytable.php (in the kernel)
-- creates the same table.
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
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPAPIKEY';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expapikey';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE expapikey (
  created INTEGER DEFAULT 0 NOT NULL,
  created_by INTEGER DEFAULT 0 NOT NULL,
  expires INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  key_prefix VARCHAR2(40),
  last_ip VARCHAR2(64),
  last_used INTEGER DEFAULT 0 NOT NULL,
  name VARCHAR2(255),
  revoked INTEGER DEFAULT 0 NOT NULL,
  revoked_by INTEGER DEFAULT 0 NOT NULL,
  salt VARCHAR2(64),
  scopes VARCHAR2(255),
  secret_hash VARCHAR2(128),
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER expapikey_id_tr
BEFORE INSERT ON expapikey FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expapikey.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX expapikey_prefix ON expapikey ( key_prefix )';
        EXECUTE IMMEDIATE 'CREATE INDEX expapikey_user ON expapikey ( user_id )';
    END IF;
END;
/
