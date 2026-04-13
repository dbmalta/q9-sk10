-- Migration 0013: Achievements and training definitions, member awards

CREATE TABLE `achievement_definitions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `category` ENUM('achievement', 'training') NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_achievement_defs_category` (`category`),
    INDEX `idx_achievement_defs_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_achievements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `achievement_id` INT UNSIGNED NOT NULL,
    `awarded_date` DATE NOT NULL,
    `awarded_by` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_member_achievement_date` (`member_id`, `achievement_id`, `awarded_date`),
    INDEX `idx_member_achievements_member` (`member_id`),
    INDEX `idx_member_achievements_achievement` (`achievement_id`),
    CONSTRAINT `fk_member_achievements_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_member_achievements_achievement` FOREIGN KEY (`achievement_id`) REFERENCES `achievement_definitions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_member_achievements_awarded_by` FOREIGN KEY (`awarded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
