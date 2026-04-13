-- Member timeline / history fields
-- Stores time-series data per member: rank progressions, qualifications, etc.

CREATE TABLE `member_timeline` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `field_key` VARCHAR(100) NOT NULL,
    `value` VARCHAR(500) NOT NULL,
    `effective_date` DATE NOT NULL,
    `recorded_by` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_member_field` (`member_id`, `field_key`, `effective_date` DESC),
    INDEX `idx_field_key` (`field_key`),
    CONSTRAINT `fk_timeline_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_timeline_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
