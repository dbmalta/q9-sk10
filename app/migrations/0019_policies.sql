-- Migration 0019: Policies
-- Extend terms_versions so multiple independent policies (each with their
-- own versions) can exist. Add per-policy audience scoping via node ids,
-- plus an active flag so superseded policies can be retired.

CREATE TABLE `policies` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_policy_active` (`is_active`),
    CONSTRAINT `fk_policy_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `policy_scopes` (
    `policy_id` INT UNSIGNED NOT NULL,
    `node_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`policy_id`, `node_id`),
    CONSTRAINT `fk_policy_scope_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_policy_scope_node` FOREIGN KEY (`node_id`) REFERENCES `org_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a default policy to hold any pre-existing terms versions
INSERT INTO `policies` (`name`, `description`, `is_active`)
VALUES ('General Policy', 'Imported from legacy terms & conditions.', 1);

-- Backfill-capable column, NOT NULL applied after backfill.
ALTER TABLE `terms_versions`
    ADD COLUMN `policy_id` INT UNSIGNED NULL AFTER `id`;

UPDATE `terms_versions`
SET `policy_id` = (SELECT `id` FROM `policies` WHERE `name` = 'General Policy' LIMIT 1)
WHERE `policy_id` IS NULL;

ALTER TABLE `terms_versions`
    MODIFY COLUMN `policy_id` INT UNSIGNED NOT NULL,
    ADD CONSTRAINT `fk_terms_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`id`) ON DELETE CASCADE,
    ADD INDEX `idx_terms_policy_pub` (`policy_id`, `is_published`);
