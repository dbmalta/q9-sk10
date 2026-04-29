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
-- Only insert rows whose base-code language exists.  ON DUPLICATE KEY id=id is
-- a deliberate no-op: if the base code already has an override for that key,
-- keep the existing value (it was set more recently / intentionally).

INSERT INTO `i18n_overrides` (`language_code`, `string_key`, `value`)
SELECT
    LEFT(`orphan`.`language_code`, 2) AS `language_code`,
    `orphan`.`string_key`,
    `orphan`.`value`
FROM `i18n_overrides` AS `orphan`
WHERE LENGTH(`orphan`.`language_code`) = 5
  AND LEFT(`orphan`.`language_code`, 2) IN (
      SELECT `code` FROM `languages` WHERE LENGTH(`code`) = 2
  )
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ── Step 2: delete orphan regional-code records ───────────────────────────────
-- Uses a derived-table subquery to avoid MySQL's restriction on reading and
-- modifying the same table in one statement.
-- The ON DELETE CASCADE on i18n_overrides.language_code removes remaining
-- overrides for the orphan automatically.

DELETE FROM `languages`
WHERE LENGTH(`code`) = 5
  AND LEFT(`code`, 2) IN (
      SELECT `base_code`
      FROM (
          SELECT `code` AS `base_code`
          FROM `languages`
          WHERE LENGTH(`code`) = 2
      ) AS `base_codes`
  );
