-- Migration 0005: Organisational structure
--
-- Two types of organisational unit:
-- 1. Hierarchy nodes — the geographic/programmatic tree (National → Region → District → Group → Section)
-- 2. Teams — functional groups attached to any hierarchy node
--
-- The closure table (org_closure) enables efficient ancestor/descendant
-- queries for tree rendering and reporting. It is NOT used for permissions.

CREATE TABLE `org_level_types` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `depth` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_leaf` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `org_nodes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `parent_id` INT UNSIGNED NULL,
    `level_type_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `short_name` VARCHAR(50) NULL,
    `description` TEXT NULL,
    `age_group_min` TINYINT UNSIGNED NULL,
    `age_group_max` TINYINT UNSIGNED NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_org_nodes_parent` FOREIGN KEY (`parent_id`) REFERENCES `org_nodes`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_org_nodes_level_type` FOREIGN KEY (`level_type_id`) REFERENCES `org_level_types`(`id`) ON DELETE RESTRICT,
    INDEX `idx_org_nodes_parent` (`parent_id`),
    INDEX `idx_org_nodes_level_type` (`level_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `org_closure` (
    `ancestor_id` INT UNSIGNED NOT NULL,
    `descendant_id` INT UNSIGNED NOT NULL,
    `depth` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`ancestor_id`, `descendant_id`),
    CONSTRAINT `fk_org_closure_ancestor` FOREIGN KEY (`ancestor_id`) REFERENCES `org_nodes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_org_closure_descendant` FOREIGN KEY (`descendant_id`) REFERENCES `org_nodes`(`id`) ON DELETE CASCADE,
    INDEX `idx_org_closure_descendant` (`descendant_id`),
    INDEX `idx_org_closure_depth` (`depth`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `org_teams` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `node_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `is_permanent` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_org_teams_node` FOREIGN KEY (`node_id`) REFERENCES `org_nodes`(`id`) ON DELETE CASCADE,
    INDEX `idx_org_teams_node` (`node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
