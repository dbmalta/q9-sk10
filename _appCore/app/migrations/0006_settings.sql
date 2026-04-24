-- Migration 0006: Key/value settings store

CREATE TABLE `settings` (
    `key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` TEXT NULL,
    `group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`, `group`) VALUES
('project_name', 'appCore Project', 'general'),
('default_language', 'en', 'general'),
('date_format', 'd/m/Y', 'general'),
('session_timeout', '3600', 'security'),
('mfa_enforcement', 'optional', 'security');
