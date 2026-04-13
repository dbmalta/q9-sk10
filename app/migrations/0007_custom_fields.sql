-- Custom field definitions
-- Actual values stored in members.member_custom_data JSON column (no migration when fields added/changed)

CREATE TABLE `custom_field_definitions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `field_key` VARCHAR(100) NOT NULL,
    `field_type` ENUM('short_text', 'long_text', 'number', 'dropdown', 'date') NOT NULL,
    `label` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `is_required` TINYINT(1) NOT NULL DEFAULT 0,
    `validation_rules` JSON NULL,
    `display_group` VARCHAR(50) NOT NULL DEFAULT 'additional',
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_field_key` (`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
