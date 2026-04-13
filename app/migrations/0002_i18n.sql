-- Migration 0002: i18n support tables

-- Tracks which languages are available and active on this installation
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

-- Per-installation string overrides (DB overrides take precedence over JSON files)
CREATE TABLE `i18n_overrides` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `language_code` VARCHAR(10) NOT NULL,
    `string_key` VARCHAR(200) NOT NULL,
    `value` TEXT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_override_key` (`language_code`, `string_key`),
    CONSTRAINT `fk_override_lang` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed English as the default language
INSERT INTO `languages` (`code`, `name`, `native_name`, `is_active`, `is_default`, `completion_pct`, `source`)
VALUES ('en', 'English', 'English', 1, 1, 100.00, 'bundled');
