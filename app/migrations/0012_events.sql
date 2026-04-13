-- Events: calendar events and iCal feed tokens

CREATE TABLE `events` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(300) NOT NULL,
    `description` TEXT NULL,
    `location` VARCHAR(300) NULL,
    `start_date` DATETIME NOT NULL,
    `end_date` DATETIME NULL,
    `all_day` TINYINT(1) NOT NULL DEFAULT 0,
    `node_scope_id` INT UNSIGNED NULL,
    `created_by` INT UNSIGNED NULL,
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_event_published_start` (`is_published`, `start_date`),
    INDEX `idx_event_node_scope` (`node_scope_id`),
    CONSTRAINT `fk_event_node_scope` FOREIGN KEY (`node_scope_id`) REFERENCES `org_nodes` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_event_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_ical_tokens` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `token` VARCHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_ical_token` (`token`),
    CONSTRAINT `fk_ical_token_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
