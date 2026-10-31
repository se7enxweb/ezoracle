--
-- Exponential 6.0.15, Oracle: PKCE for the OAuth authorization code flow, ezprest_authcode.code_challenge and code_challenge_method.
--
-- oauth/authorize stores the code_challenge and code_challenge_method of the request with
-- the code; the token end point checks the code_verifier against them. Without these
-- columns no authorization code can be issued. A database without the REST tables gets
-- nothing.
--
-- Each column is added only when missing, so the file can run again.
--
-- Run with SQL*Plus or SQLcl as the schema owner.
--

DECLARE
    n INTEGER;
BEGIN
    SELECT COUNT(*) INTO n FROM user_tables WHERE table_name = 'EZPREST_AUTHCODE';
    IF n = 1 THEN
        SELECT COUNT(*) INTO n FROM user_tab_columns
         WHERE table_name = 'EZPREST_AUTHCODE' AND column_name = 'CODE_CHALLENGE';
        IF n = 0 THEN
            EXECUTE IMMEDIATE 'ALTER TABLE ezprest_authcode ADD code_challenge VARCHAR2(128)';
        END IF;
        SELECT COUNT(*) INTO n FROM user_tab_columns
         WHERE table_name = 'EZPREST_AUTHCODE' AND column_name = 'CODE_CHALLENGE_METHOD';
        IF n = 0 THEN
            EXECUTE IMMEDIATE 'ALTER TABLE ezprest_authcode ADD code_challenge_method VARCHAR2(10)';
        END IF;
    END IF;
END;
/
