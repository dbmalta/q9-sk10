-- Phase 5: Administration tables

-- Terms & Conditions versioning
CREATE TABLE `terms_versions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(300) NOT NULL,
    `content` LONGTEXT NOT NULL,
    `version_number` VARCHAR(20) NOT NULL,
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `published_at` DATETIME NULL,
    `grace_period_days` INT UNSIGNED NOT NULL DEFAULT 14,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_terms_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Terms acceptances
CREATE TABLE `terms_acceptances` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `terms_version_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `accepted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) NULL,
    UNIQUE KEY `uq_terms_user` (`terms_version_id`, `user_id`),
    CONSTRAINT `fk_acceptance_terms` FOREIGN KEY (`terms_version_id`) REFERENCES `terms_versions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_acceptance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Important notices
CREATE TABLE `notices` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(300) NOT NULL,
    `content` TEXT NOT NULL,
    `type` ENUM('must_acknowledge', 'informational') NOT NULL DEFAULT 'informational',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_notice_active` (`is_active`, `type`),
    CONSTRAINT `fk_notice_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notice acknowledgements
CREATE TABLE `notice_acknowledgements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `notice_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `acknowledged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_notice_user` (`notice_id`, `user_id`),
    CONSTRAINT `fk_ack_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ack_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System settings (key-value store)
CREATE TABLE `settings` (
    `key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` TEXT NULL,
    `group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log
CREATE TABLE `audit_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `action` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT UNSIGNED NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_date` (`created_at`),
    INDEX `idx_audit_action` (`action`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Language management
CREATE TABLE `languages` (
    `code` VARCHAR(10) NOT NULL PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `native_name` VARCHAR(100) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `completion_pct` DECIMAL(5,2) NOT NULL DEFAULT 0,
    `source` ENUM('bundled', 'uploaded') NOT NULL DEFAULT 'bundled',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- i18n string overrides
CREATE TABLE `i18n_overrides` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `language_code` VARCHAR(10) NOT NULL,
    `string_key` VARCHAR(200) NOT NULL,
    `value` TEXT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_override_key` (`language_code`, `string_key`),
    CONSTRAINT `fk_override_lang` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default English language
INSERT INTO `languages` (`code`, `name`, `native_name`, `is_active`, `is_default`, `completion_pct`, `source`)
VALUES ('en', 'English', 'English', 1, 1, 100.00, 'bundled');

-- Seed default settings
INSERT INTO `settings` (`key`, `value`, `group`) VALUES
('org_name', 'ScoutKeeper', 'general'),
('timezone', 'Europe/Malta', 'general'),
('date_format', 'd/m/Y', 'general'),
('self_registration', '1', 'registration'),
('waiting_list', '1', 'registration'),
('admin_approval', '1', 'registration'),
('session_timeout', '3600', 'security'),
('mfa_enforcement', 'optional', 'security'),
('gdpr_enabled', '1', 'gdpr'),
('gdpr_retention_days', '2555', 'gdpr'),
('cron_mode', 'pseudo', 'cron');
