-- Phase 6: Setup and update tracking

-- Applied migrations tracker
CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `filename` VARCHAR(200) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_migration` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update state tracking
CREATE TABLE IF NOT EXISTS `update_state` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `from_version` VARCHAR(20) NOT NULL,
    `to_version` VARCHAR(20) NOT NULL,
    `status` ENUM('pending', 'in_progress', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'pending',
    `current_step` VARCHAR(100) NULL,
    `started_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `error_message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
