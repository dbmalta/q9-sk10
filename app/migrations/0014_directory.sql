-- Migration 0014: Directory / Organogram support
--
-- The directory module does not need its own tables — it queries
-- org_nodes, role_assignments, roles, members, and users.
--
-- This migration adds a visibility flag to roles so that admins can
-- control which role holders appear in the public directory.

ALTER TABLE `roles`
    ADD COLUMN `is_directory_visible` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `can_access_financial`;
