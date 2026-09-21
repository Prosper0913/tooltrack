-- ================================================================
--  migration_v4_fixes.sql
--  Run once in phpMyAdmin after dropping in this round's files.
-- ================================================================

-- Who physically handed the tool back — was collected in the UI but
-- never actually sent to the backend or stored anywhere.
ALTER TABLE `transactions`
  ADD COLUMN `returnee_name` VARCHAR(150) NULL AFTER `notes`;

-- Persisted at return time: was this specific loan returned after its
-- due_date? (Kept as a real column, not just computed on the fly, so
-- "late returns" can be filtered/reported on directly.)
ALTER TABLE `transactions`
  ADD COLUMN `is_late` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;

-- Add "missing" (lost item) alongside the existing good/minor/damaged
-- return conditions.
ALTER TABLE `transactions`
  MODIFY COLUMN `condition` ENUM('good','minor','damaged','missing') DEFAULT NULL;

-- ── Replacement Requests ────────────────────────────────────────
-- Staff-submitted requests to replace a damaged/lost/worn-out tool,
-- separate from just adding a brand-new tool entry.
CREATE TABLE `replacement_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tool_id` INT(11) DEFAULT NULL COMMENT 'NULL if the tool no longer exists / was fully removed',
  `tool_name_snapshot` VARCHAR(150) NOT NULL COMMENT 'Tool name at time of request, kept even if the tool row is later deleted',
  `reason` ENUM('damaged','lost','worn_out','other') NOT NULL,
  `quantity_needed` INT(11) NOT NULL DEFAULT 1,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('pending','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending',
  `requested_by` INT(11) NOT NULL COMMENT 'FK -> users.id',
  `resolved_by` INT(11) DEFAULT NULL COMMENT 'FK -> users.id',
  `resolution_notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rr_status` (`status`),
  KEY `idx_rr_tool` (`tool_id`),
  CONSTRAINT `fk_rr_tool` FOREIGN KEY (`tool_id`) REFERENCES `tools` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rr_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rr_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
