-- Registration invitations and waiting list

CREATE TABLE `registration_invitations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `token` VARCHAR(64) NOT NULL,
    `target_node_id` INT UNSIGNED NOT NULL,
    `created_by` INT UNSIGNED NULL,
    `email` VARCHAR(255) NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_invitation_token` (`token`),
    INDEX `idx_invitation_email` (`email`),
    INDEX `idx_invitation_expires` (`expires_at`),
    CONSTRAINT `fk_invitation_node` FOREIGN KEY (`target_node_id`) REFERENCES `org_nodes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_invitation_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `waiting_list` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `position` INT UNSIGNED NOT NULL DEFAULT 0,
    `parent_name` VARCHAR(200) NOT NULL,
    `parent_email` VARCHAR(255) NOT NULL,
    `child_name` VARCHAR(200) NOT NULL,
    `child_dob` DATE NULL,
    `preferred_node_id` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `status` ENUM('waiting', 'contacted', 'converted', 'withdrawn') NOT NULL DEFAULT 'waiting',
    `converted_member_id` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_waiting_status` (`status`, `position`),
    CONSTRAINT `fk_waiting_node` FOREIGN KEY (`preferred_node_id`) REFERENCES `org_nodes` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_waiting_member` FOREIGN KEY (`converted_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
