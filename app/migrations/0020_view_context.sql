-- Migration 0020: View context (Admin/Member mode + node scope)
--
-- Adds per-user "last seen" mode and scope so the switcher can seed
-- itself on login, plus optional node_id + view_mode columns on
-- audit_log so writes can be filtered by scope and replayed with the
-- mode context that produced them.
--
-- Secondary memberships already live in `member_nodes` (is_primary flag),
-- added in migration 0006 — no new link table needed here.

ALTER TABLE `users`
    ADD COLUMN `view_mode_last` ENUM('admin', 'member') NULL AFTER `is_super_admin`,
    ADD COLUMN `scope_node_id_last` INT UNSIGNED NULL AFTER `view_mode_last`,
    ADD CONSTRAINT `fk_users_scope_node`
        FOREIGN KEY (`scope_node_id_last`) REFERENCES `org_nodes`(`id`) ON DELETE SET NULL;

ALTER TABLE `audit_log`
    ADD COLUMN `node_id` INT UNSIGNED NULL,
    ADD COLUMN `view_mode` VARCHAR(10) NULL,
    ADD INDEX `idx_audit_node` (`node_id`);
