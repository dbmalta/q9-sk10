-- Migration 0010: Seed default language and super-admin role

INSERT INTO `languages` (`code`, `name`, `native_name`, `is_active`, `is_default`, `completion_pct`, `source`)
VALUES ('en', 'English', 'English', 1, 1, 100.00, 'bundled');

INSERT INTO `roles` (`name`, `description`, `permissions`, `is_system`) VALUES
('Super Admin',
 'Full access to all modules and data.',
 '{"admin.dashboard":true,"admin.settings":true,"admin.audit":true,"admin.logs":true,"admin.backup":true,"admin.export":true,"admin.languages":true,"admin.notices":true,"admin.terms":true,"admin.monitoring":true,"admin.updates":true,"roles.read":true,"roles.write":true}',
 1);
