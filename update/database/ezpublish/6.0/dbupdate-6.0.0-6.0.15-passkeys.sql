--
-- Exponential 6.0.15, Oracle: passkeys, exppasskey.
--
-- One row per passkey (WebAuthn credential) a user registered on user/passkeys; a sign-in
-- looks it up by credential_hash. update/common/scripts/6.0/createpasskeytable.php (in the
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
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPPASSKEY';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_exppasskey';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE exppasskey (
  aaguid VARCHAR2(36),
  algorithm INTEGER DEFAULT 0 NOT NULL,
  backup_eligible INTEGER DEFAULT 0 NOT NULL,
  backup_state INTEGER DEFAULT 0 NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  credential_hash VARCHAR2(64),
  credential_id CLOB,
  id INTEGER NOT NULL,
  last_ip VARCHAR2(64),
  last_used INTEGER DEFAULT 0 NOT NULL,
  name VARCHAR2(255),
  public_key CLOB,
  sign_count INTEGER DEFAULT 0 NOT NULL,
  transports VARCHAR2(255),
  user_handle VARCHAR2(128),
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER exppasskey_id_tr
BEFORE INSERT ON exppasskey FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_exppasskey.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX exppasskey_credential ON exppasskey ( credential_hash )';
        EXECUTE IMMEDIATE 'CREATE INDEX exppasskey_user ON exppasskey ( user_id )';
    END IF;
END;
/
