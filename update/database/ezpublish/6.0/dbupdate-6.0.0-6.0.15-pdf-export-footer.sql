--
-- Exponential 6.0.15, Oracle: the footer wording of a pdf export, ezpdf_export.show_footer and footer_text.
--
-- show_footer says whether the footer carries a line of text at all, footer_text what it
-- reads. Left empty with show_footer set, the wording that shipped is used, so an export that
-- predates the columns looks exactly as it did.
--
-- footer_text is nullable: Oracle stores an empty string as NULL. Fresh installs get the same
-- from settings/dbschema.ini.append.php (ColumnOptionTranslations).
--
-- Each column is added only when missing, so the file can run again.
--
-- Run with SQL*Plus or SQLcl as the schema owner.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZPDF_EXPORT' AND column_name = 'SHOW_FOOTER';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezpdf_export ADD show_footer INTEGER DEFAULT 1 NOT NULL';
    END IF;
    SELECT COUNT(*) INTO n FROM user_tab_columns
     WHERE table_name = 'EZPDF_EXPORT' AND column_name = 'FOOTER_TEXT';
    IF n = 0 THEN
        EXECUTE IMMEDIATE 'ALTER TABLE ezpdf_export ADD footer_text VARCHAR2(255)';
    END IF;
END;
/
