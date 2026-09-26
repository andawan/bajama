-- BAJAMA identity and tenant uniqueness.
-- Preflight before running:
--   SELECT LOWER(username), COUNT(*) FROM users GROUP BY LOWER(username) HAVING COUNT(*) > 1;
--   SELECT LOWER(email), COUNT(*) FROM users WHERE email IS NOT NULL AND email <> '' GROUP BY LOWER(email) HAVING COUNT(*) > 1;
--   SELECT LOWER(name), COUNT(*) FROM organizations GROUP BY LOWER(name) HAVING COUNT(*) > 1;
--   SELECT LOWER(email), COUNT(*) FROM organizations WHERE email IS NOT NULL AND email <> '' GROUP BY LOWER(email) HAVING COUNT(*) > 1;
-- Resolve every returned row before applying this migration.

ALTER TABLE users
    DROP INDEX uq_user_org_username,
    DROP INDEX uq_user_org_email,
    ADD UNIQUE KEY uq_users_username_global (username),
    ADD UNIQUE KEY uq_users_email_global (email);

ALTER TABLE organizations
    ADD UNIQUE KEY uq_organizations_name (name),
    ADD UNIQUE KEY uq_organizations_email (email);
