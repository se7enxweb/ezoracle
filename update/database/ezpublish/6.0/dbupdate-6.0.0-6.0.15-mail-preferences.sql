--
-- Exponential 6.0.15, Oracle: e-mail preferences and consent, the expmail_* tables.
--
-- Categories of mail (expmail_category), what each recipient chose (expmail_preference),
-- the log of consent given and withdrawn (expmail_consent_log), confirmations not yet
-- answered (expmail_pending) and addresses no mail goes to (expmail_suppression). New tables
-- only; mail without a category is sent as before, so the file can run before or after the code.
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
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPMAIL_CATEGORY';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expmail_category';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE expmail_category (
  created INTEGER DEFAULT 0 NOT NULL,
  default_on INTEGER DEFAULT 0 NOT NULL,
  description CLOB,
  double_opt_in INTEGER DEFAULT 0 NOT NULL,
  essential INTEGER DEFAULT 0 NOT NULL,
  frequencies VARCHAR2(100),
  handler_class VARCHAR2(255),
  id INTEGER NOT NULL,
  identifier VARCHAR2(100),
  modified INTEGER DEFAULT 0 NOT NULL,
  name VARCHAR2(255),
  priority INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER expmail_category_id_tr
BEFORE INSERT ON expmail_category FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expmail_category.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX expmail_category_identifier ON expmail_category ( identifier )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPMAIL_CONSENT_LOG';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expmail_consent_log';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE expmail_consent_log (
  action VARCHAR2(30),
  actor_user_id INTEGER DEFAULT 0 NOT NULL,
  anonymised INTEGER DEFAULT 0 NOT NULL,
  category VARCHAR2(100),
  created INTEGER DEFAULT 0 NOT NULL,
  email VARCHAR2(255),
  id INTEGER NOT NULL,
  ip VARCHAR2(64),
  new_value VARCHAR2(100),
  old_value VARCHAR2(100),
  recipient_key VARCHAR2(80),
  siteaccess VARCHAR2(100),
  source VARCHAR2(20),
  user_id INTEGER DEFAULT 0 NOT NULL,
  wording CLOB,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER expmail_consent_log_id_tr
BEFORE INSERT ON expmail_consent_log FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expmail_consent_log.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE INDEX expmail_consent_log_created ON expmail_consent_log ( created )';
        EXECUTE IMMEDIATE 'CREATE INDEX expmail_consent_log_recipient ON expmail_consent_log ( recipient_key, created )';
        EXECUTE IMMEDIATE 'CREATE INDEX expmail_consent_log_user ON expmail_consent_log ( user_id )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPMAIL_PENDING';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expmail_pending';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE expmail_pending (
  category VARCHAR2(100),
  created INTEGER DEFAULT 0 NOT NULL,
  data CLOB,
  expires INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  kind VARCHAR2(20),
  recipient_key VARCHAR2(80),
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER expmail_pending_id_tr
BEFORE INSERT ON expmail_pending FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expmail_pending.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE INDEX expmail_pending_expires ON expmail_pending ( expires )';
        EXECUTE IMMEDIATE 'CREATE INDEX expmail_pending_recipient ON expmail_pending ( recipient_key, kind )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPMAIL_PREFERENCE';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expmail_preference';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE expmail_preference (
  category VARCHAR2(100),
  created INTEGER DEFAULT 0 NOT NULL,
  frequency VARCHAR2(20),
  id INTEGER NOT NULL,
  modified INTEGER DEFAULT 0 NOT NULL,
  recipient_key VARCHAR2(80),
  state VARCHAR2(20),
  user_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER expmail_preference_id_tr
BEFORE INSERT ON expmail_preference FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expmail_preference.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE INDEX expmail_preference_category ON expmail_preference ( category, state )';
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX expmail_preference_recipient ON expmail_preference ( recipient_key, category )';
        EXECUTE IMMEDIATE 'CREATE INDEX expmail_preference_user ON expmail_preference ( user_id )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXPMAIL_SUPPRESSION';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_expmail_suppression';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE expmail_suppression (
  created INTEGER DEFAULT 0 NOT NULL,
  created_by INTEGER DEFAULT 0 NOT NULL,
  email_hash VARCHAR2(64),
  id INTEGER NOT NULL,
  note CLOB,
  reason VARCHAR2(30),
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER expmail_suppression_id_tr
BEFORE INSERT ON expmail_suppression FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_expmail_suppression.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX expmail_suppression_hash ON expmail_suppression ( email_hash )';
    END IF;
END;
/
