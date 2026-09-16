-- ================================================================
--  migration_v3_no_delete.sql
--  Run this once against your existing tooltrack_db (phpMyAdmin →
--  SQL tab) AFTER dropping in this round's updated PHP/JS files.
--
--  What changed: hard DELETE is gone for both Tools and Borrowers,
--  so transaction history can never be silently destroyed (deleting
--  a tool used to CASCADE-delete every transaction row for it;
--  deleting a borrower used to NULL out borrower_id on their past
--  transactions). Both are now a reversible "retire"/"deactivate"
--  toggle instead. Borrowers already had `is_active` — tools didn't,
--  so this adds it.
-- ================================================================

ALTER TABLE `tools`
  ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1=active/borrowable, 0=retired (soft-removed, transaction history kept)'
    AFTER `min_stock`;
