-- Migration 0023: Clean up orphan language records and normalise bundled codes.
--
-- Background: before migrations 0021/0022 were introduced, admins could upload
-- French or Italian using regional codes (fr-FR, it-IT). When the system update
-- then registered the same languages as simple bundled codes (fr, it), the old
-- regional records became orphans — their translation files were gone but the DB
-- rows remained, causing duplicate entries in the language switcher and 0 %
-- completion on the admin page.
--
-- This migration:
--   1. Copies any i18n_overrides from orphan regional codes to their base code
--      (so hand-edited strings survive the cleanup).
--   2. Deletes the orphan language records (cascade removes their overrides).
--
-- The ongoing guard is LanguageService::syncFromFilesystem(), called on every
-- admin languages page load, which keeps DB and filesystem in sync going forward.

-- ── Step 1: migrate overrides from regional → base code ──────────────────────
-- Only copy rows that do not already exist for the base code; on conflict, the
-- existing base-code override wins (it was set more recently / intentionally).

INSERT INTO `i18n_overrides` (`language_code`, `string_key`, `value`)
SELECT `base`.`code`, `orphan`.`string_key`, `orphan`.`value`
FROM `i18n_overrides` AS `orphan`
JOIN `languages`       AS `orphan_lang` ON `orphan_lang`.`code` = `orphan`.`language_code`
JOIN `languages`       AS `base`        ON `base`.`code` = LEFT(`orphan`.`language_code`, 2)
WHERE LENGTH(`orphan`.`language_code`) = 5          -- regional format: xx-XX
  AND `base`.`code` != `orphan`.`language_code`     -- different from the orphan
ON DUPLICATE KEY UPDATE `value` = `i18n_overrides`.`value`; -- keep existing base-code value

-- ── Step 2: delete orphan regional-code records ───────────────────────────────
-- Removes language records whose code is in regional format (xx-XX) AND whose
-- base code (first two characters) exists as a separate bundled record.
-- The ON DELETE CASCADE on i18n_overrides.language_code removes remaining
-- overrides for the orphan automatically.

DELETE `orphan_lang`
FROM `languages` AS `orphan_lang`
JOIN `languages` AS `base_lang` ON `base_lang`.`code` = LEFT(`orphan_lang`.`code`, 2)
WHERE LENGTH(`orphan_lang`.`code`) = 5
  AND `base_lang`.`code` != `orphan_lang`.`code`;
