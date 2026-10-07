-- ================================================================
--  migration_v5_audit_trail.sql
--  Run once in phpMyAdmin after dropping in this round's files.
-- ================================================================

CREATE TABLE `audit_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) DEFAULT NULL COMMENT 'FK -> users.id; NULL for a failed login (no known user) or if the user was later deleted',
  `username_snapshot` VARCHAR(100) DEFAULT NULL COMMENT 'Kept even if the user account is later deleted',
  `role_snapshot` VARCHAR(20) DEFAULT NULL,
  `action` VARCHAR(40) NOT NULL COMMENT 'login | login_failed | logout | borrow | return | tool_create | tool_edit | tool_retire | tool_reactivate | borrower_create | borrower_edit | borrower_deactivate | borrower_reactivate | user_create | user_delete | password_reset | password_change | replacement_request | replacement_resolved | cms_sync',
  `description` VARCHAR(500) NOT NULL COMMENT 'Human-readable summary, e.g. "Borrowed 2x Spoon (SP-101) for Juan Dela Cruz"',
  `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IPv4 or IPv6',
  `user_agent` VARCHAR(255) DEFAULT NULL COMMENT 'Raw browser User-Agent string',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
