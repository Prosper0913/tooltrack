-- ================================================================
--  migration_v2_roles_security.sql
--  Run this once against your existing tooltrack_db (phpMyAdmin →
--  SQL tab, or `mysql -u root tooltrack_db < migration_v2_roles_security.sql`)
--  AFTER dropping in the updated PHP files.
-- ================================================================

-- 1. Standardize the existing admin's role string to match the new
--    ROLE_ADMIN constant ('Admin') used throughout the updated code.
UPDATE users SET role = 'Admin' WHERE role = 'Department Admin';

-- 2. Passwords: nothing to run here. auth.php now auto-detects a
--    plaintext legacy password on next successful login and silently
--    rehashes it with bcrypt — so your existing admin account
--    (username admin / password 12345678) upgrades itself the first
--    time it logs in after this update. You can verify it worked by
--    checking that `password` in the `users` table now starts with
--    "$2y$" instead of being a plain string.
--
--    IMPORTANT: change the admin1234/12345678 default password to
--    something real once you've confirmed login still works.

-- 3. To add a Staff account (there's no UI for this yet), generate a
--    hashed-password INSERT with the CLI helper and run its output:
--      php genhash.php "Staff Name" "staffuser" "theirpassword" "Staff"
--    Do NOT hand-write an INSERT with a plaintext password — it will
--    be accepted (auth.php's legacy-plaintext path still works) but
--    it defeats the point of hashing until someone logs in with it.
