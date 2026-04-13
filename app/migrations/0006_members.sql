-- Migration 0006: Members tables
-- Core member management: members, node assignments, pending changes, medical access log

CREATE TABLE `members` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `membership_number` VARCHAR(50) NOT NULL UNIQUE,
    `first_name` VARCHAR(100) NOT NULL,
    `surname` VARCHAR(100) NOT NULL,
    `dob` DATE NULL,
    `gender` ENUM('male', 'female', 'other', 'prefer_not_to_say') NULL,
    `email` VARCHAR(255) NULL,
    `phone` VARCHAR(50) NULL,
    `address_line1` VARCHAR(200) NULL,
    `address_line2` VARCHAR(200) NULL,
    `city` VARCHAR(100) NULL,
    `postcode` VARCHAR(20) NULL,
    `country` VARCHAR(100) NULL DEFAULT 'Malta',
    `medical_notes` TEXT NULL COMMENT 'AES-256-GCM encrypted',
    `photo_path` VARCHAR(500) NULL,
    `member_custom_data` JSON NULL,
    `status` ENUM('active', 'pending', 'suspended', 'inactive', 'left') NOT NULL DEFAULT 'pending',
    `status_reason` VARCHAR(500) NULL,
    `joined_date` DATE NULL,
    `left_date` DATE NULL,
    `gdpr_consent` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FULLTEXT INDEX `ft_members_search` (`first_name`, `surname`, `email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_nodes` (
    `member_id` INT UNSIGNED NOT NULL,
    `node_id` INT UNSIGNED NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`member_id`, `node_id`),
    CONSTRAINT `fk_member_nodes_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_member_nodes_node` FOREIGN KEY (`node_id`) REFERENCES `org_nodes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_pending_changes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `field_name` VARCHAR(100) NOT NULL,
    `old_value` TEXT NULL,
    `new_value` TEXT NULL,
    `requested_by` INT UNSIGNED NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `reviewed_by` INT UNSIGNED NULL,
    `reviewed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_pending_changes_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pending_changes_requested` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pending_changes_reviewed` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `medical_access_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `accessed_by` INT UNSIGNED NOT NULL,
    `action` VARCHAR(50) NOT NULL DEFAULT 'view',
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_medical_log_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_medical_log_user` FOREIGN KEY (`accessed_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_medical_log_member` (`member_id`),
    INDEX `idx_medical_log_accessed` (`accessed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
