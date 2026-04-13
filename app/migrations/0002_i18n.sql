-- Migration 0002: i18n support tables

-- Per-installation string overrides (DB overrides take precedence over JSON files)
CREATE TABLE `i18n_overrides` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `language` VARCHAR(10) NOT NULL,
    `translation_key` VARCHAR(255) NOT NULL,
    `translation_value` TEXT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_lang_key` (`language`, `translation_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks which languages are available and active on this installation
CREATE TABLE `languages` (
    `code` VARCHAR(10) NOT NULL PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `native_name` VARCHAR(100) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed English as the default language
INSERT INTO `languages` (`code`, `name`, `native_name`, `is_active`, `is_default`)
VALUES ('en', 'English', 'English', 1, 1);
