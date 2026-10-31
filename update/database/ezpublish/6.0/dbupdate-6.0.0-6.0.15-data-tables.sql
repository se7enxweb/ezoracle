--
-- Exponential 6.0.15, Oracle: data table connections, the secret store and synchronisation.
--
-- exp_data_connection: the named database connections of the data tables (the NAME of the
-- secret that holds a password, never the password). exp_secret: the kernel's secret store,
-- values encrypted under the store's key. exp_data_sync, exp_data_sync_link and
-- exp_data_sync_conflict: the synchronisation between content and data tables. The
-- administration creates each of them when it is missing.
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
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXP_DATA_CONNECTION';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_exp_data_connection';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE exp_data_connection (
  created INTEGER DEFAULT 0 NOT NULL,
  creator_id INTEGER DEFAULT 0 NOT NULL,
  database_name VARCHAR2(255),
  description VARCHAR2(255),
  engine VARCHAR2(32),
  id INTEGER NOT NULL,
  identifier VARCHAR2(64),
  modified INTEGER DEFAULT 0 NOT NULL,
  modifier_id INTEGER DEFAULT 0 NOT NULL,
  name VARCHAR2(255),
  options CLOB,
  port INTEGER DEFAULT 0 NOT NULL,
  read_only INTEGER DEFAULT 0 NOT NULL,
  secret VARCHAR2(128),
  server VARCHAR2(255),
  user_name VARCHAR2(255),
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER exp_data_connection_id_tr
BEFORE INSERT ON exp_data_connection FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_exp_data_connection.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX exp_data_connection_identifier ON exp_data_connection ( identifier )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXP_SECRET';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_exp_secret';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE exp_secret (
  ciphertext CLOB,
  created INTEGER DEFAULT 0 NOT NULL,
  creator_id INTEGER DEFAULT 0 NOT NULL,
  description VARCHAR2(255),
  id INTEGER NOT NULL,
  key_id VARCHAR2(16),
  modified INTEGER DEFAULT 0 NOT NULL,
  modifier_id INTEGER DEFAULT 0 NOT NULL,
  name VARCHAR2(128),
  scope VARCHAR2(64),
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER exp_secret_id_tr
BEFORE INSERT ON exp_secret FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_exp_secret.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX exp_secret_name ON exp_secret ( name )';
        EXECUTE IMMEDIATE 'CREATE INDEX exp_secret_scope ON exp_secret ( scope )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXP_DATA_SYNC';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_exp_data_sync';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE exp_data_sync (
  class_name VARCHAR2(128),
  conflict_rule VARCHAR2(16),
  content_class VARCHAR2(128),
  created INTEGER DEFAULT 0 NOT NULL,
  creator_id INTEGER DEFAULT 0 NOT NULL,
  direction VARCHAR2(16),
  id INTEGER NOT NULL,
  interval_seconds INTEGER DEFAULT 0 NOT NULL,
  key_columns VARCHAR2(255),
  last_run INTEGER DEFAULT 0 NOT NULL,
  last_run_id INTEGER DEFAULT 0 NOT NULL,
  map CLOB,
  modified INTEGER DEFAULT 0 NOT NULL,
  name VARCHAR2(255),
  next_run INTEGER DEFAULT 0 NOT NULL,
  options CLOB,
  run_mode VARCHAR2(16),
  state VARCHAR2(16),
  subtree_node_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER exp_data_sync_id_tr
BEFORE INSERT ON exp_data_sync FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_exp_data_sync.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE INDEX exp_data_sync_mode ON exp_data_sync ( run_mode, state )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXP_DATA_SYNC_CONFLICT';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_exp_data_sync_conflict';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE exp_data_sync_conflict (
  content_values CLOB,
  detected INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  link_id INTEGER DEFAULT 0 NOT NULL,
  object_id INTEGER DEFAULT 0 NOT NULL,
  resolution VARCHAR2(16),
  resolved INTEGER DEFAULT 0 NOT NULL,
  resolver_id INTEGER DEFAULT 0 NOT NULL,
  row_key VARCHAR2(255),
  row_values CLOB,
  state VARCHAR2(16),
  sync_id INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER exp_data_sync_conflict_id_tr
BEFORE INSERT ON exp_data_sync_conflict FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_exp_data_sync_conflict.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE INDEX exp_data_sync_conflict_state ON exp_data_sync_conflict ( sync_id, state )';
    END IF;
END;
/

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EXP_DATA_SYNC_LINK';
    IF n = 0 THEN
        BEGIN
            EXECUTE IMMEDIATE 'CREATE SEQUENCE se_exp_data_sync_link';
        EXCEPTION WHEN OTHERS THEN
            IF SQLCODE != -955 THEN RAISE; END IF;
        END;
        EXECUTE IMMEDIATE 'CREATE TABLE exp_data_sync_link (
  content_hash VARCHAR2(32),
  content_modified INTEGER DEFAULT 0 NOT NULL,
  id INTEGER NOT NULL,
  object_id INTEGER DEFAULT 0 NOT NULL,
  row_hash VARCHAR2(32),
  row_key VARCHAR2(255),
  row_modified INTEGER DEFAULT 0 NOT NULL,
  sync_id INTEGER DEFAULT 0 NOT NULL,
  synced INTEGER DEFAULT 0 NOT NULL,
  PRIMARY KEY ( id )
)';
        EXECUTE IMMEDIATE 'CREATE OR REPLACE TRIGGER exp_data_sync_link_id_tr
BEFORE INSERT ON exp_data_sync_link FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_exp_data_sync_link.nextval INTO :new.id FROM dual;
END;';
        EXECUTE IMMEDIATE 'CREATE UNIQUE INDEX exp_data_sync_link_key ON exp_data_sync_link ( sync_id, row_key )';
        EXECUTE IMMEDIATE 'CREATE INDEX exp_data_sync_link_object ON exp_data_sync_link ( sync_id, object_id )';
    END IF;
END;
/
