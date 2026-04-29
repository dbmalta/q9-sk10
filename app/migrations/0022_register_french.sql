-- Migration 0022: Register French as a bundled language

INSERT INTO `languages` (`code`, `name`, `native_name`, `is_active`, `is_default`, `completion_pct`, `source`)
VALUES ('fr', 'French', 'Français', 1, 0, 85.18, 'bundled')
ON DUPLICATE KEY UPDATE
    `name`           = VALUES(`name`),
    `native_name`    = VALUES(`native_name`),
    `is_active`      = VALUES(`is_active`),
    `completion_pct` = VALUES(`completion_pct`),
    `source`         = VALUES(`source`);
