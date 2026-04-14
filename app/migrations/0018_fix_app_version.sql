-- Fix app_version: was incorrectly set to 1.0.0 during setup
-- The actual release version scheme starts at 0.x.x
UPDATE `settings`
SET `value` = '0.1.7'
WHERE `key` = 'app_version'
  AND `value` = '1.0.0';
