--
-- Exponential 6.0.15, Oracle: ezorder.siteaccess.
--
-- The siteaccess (store or site) an order was placed in, written by the kernel's
-- shop when a basket becomes an order since Exponential 6.0.15; the order view and
-- the order lists show it and filter by it (doc/eng-us/features/6.0/order-siteaccess.md
-- in the kernel). Until the column exists the kernel stores and reads orders without
-- it. Orders placed before keep no siteaccess and are shown as unknown.
--
-- siteaccess is nullable: Oracle stores an empty string as NULL, so NOT NULL
-- DEFAULT '' would refuse an order without one. The kernel treats NULL as ''. Fresh
-- installs get the same from settings/dbschema.ini.append.php (ColumnOptionTranslations).
--
-- The column is added only when missing, so the file can run again.
-- Run with SQL*Plus or SQLcl as the schema owner.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZORDER' AND column_name = 'SITEACCESS';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezorder ADD siteaccess VARCHAR2(255)';
    END IF;
END;
/
