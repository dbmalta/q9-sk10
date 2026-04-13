-- Migration 0004: Roles and permission assignments
--
-- Permissions are fully explicit — position in the org hierarchy
-- grants nothing automatically. Admins define roles with module-level
-- permissions, then assign roles to users with a specific scope.
--
-- Note: role_assignments uses user_id (not member_id) because permissions
-- control system access, which requires a user account. When the Members
-- module is introduced, members link to users via members.user_id.
--
-- Foreign keys to org_nodes/org_teams are omitted here because those
-- tables are created in migration 0005 (org structure). Context and
-- scope references are validated at the application level.

CREATE TABLE `roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(500) NULL,
    `permissions` JSON NOT NULL DEFAULT ('{}'),
    `can_publish_events` TINYINT(1) NOT NULL DEFAULT 0,
    `can_access_medical` TINYINT(1) NOT NULL DEFAULT 0,
    `can_access_financial` TINYINT(1) NOT NULL DEFAULT 0,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_assignments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    `context_type` ENUM('node', 'team') NULL,
    `context_id` INT UNSIGNED NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NULL,
    `assigned_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_role_assignments_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_role_assignments_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_role_assignments_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_role_assignments_user` (`user_id`),
    INDEX `idx_role_assignments_active` (`user_id`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_assignment_scopes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `assignment_id` INT UNSIGNED NOT NULL,
    `node_id` INT UNSIGNED NOT NULL,
    CONSTRAINT `fk_scope_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `role_assignments`(`id`) ON DELETE CASCADE,
    INDEX `idx_scope_assignment` (`assignment_id`),
    INDEX `idx_scope_node` (`node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed system roles

INSERT INTO `roles` (`name`, `description`, `permissions`, `can_publish_events`, `can_access_medical`, `can_access_financial`, `is_system`) VALUES
(
    'Super Admin',
    'Full access to all modules and data across the entire organisation.',
    '{"members.read":true,"members.write":true,"events.read":true,"events.write":true,"directory.read":true,"directory.write":true,"communications.read":true,"communications.write":true,"achievements.read":true,"achievements.write":true,"org_structure.read":true,"org_structure.write":true,"custom_fields.read":true,"custom_fields.write":true,"reports.read":true,"audit_log.read":true,"settings.read":true,"settings.write":true,"roles.read":true,"roles.write":true,"terms.read":true,"terms.write":true}',
    1, 1, 1, 1
),
(
    'Group Leader',
    'Can manage members and communications, view events within their scope.',
    '{"members.read":true,"members.write":true,"events.read":true,"directory.read":true,"communications.read":true,"communications.write":true,"achievements.read":true}',
    0, 0, 0, 1
),
(
    'Section Leader',
    'Can view members and events within their scope.',
    '{"members.read":true,"events.read":true,"directory.read":true,"achievements.read":true}',
    0, 0, 0, 1
);
