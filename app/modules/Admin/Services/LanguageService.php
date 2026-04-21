<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Database;

/**
 * Language management service.
 *
 * Manages installed languages, file-based translations, and per-installation
 * string overrides stored in the database. Works alongside the core I18n
 * class which handles runtime translation loading.
 */
class LanguageService
{
    private Database $db;

    /** @var string Absolute path to the lang/ directory */
    private string $langPath;

    public function __construct(Database $db, string $langPath)
    {
        $this->db = $db;
        $this->langPath = $langPath;
    }

    // ──── Language records ────

    /**
     * Get all languages from the database.
     *
     * @return array All language records
     */
    public function getLanguages(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM languages ORDER BY is_default DESC, name ASC"
        );
    }

    /**
     * Get only active languages.
     *
     * @return array Active language records
     */
    public function getActiveLanguages(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM languages WHERE is_active = 1 ORDER BY is_default DESC, name ASC"
        );
    }

    /**
     * Get the language code of the default language.
     *
     * @return string Language code (falls back to 'en' if no default is set)
     */
    public function getDefaultLanguage(): string
    {
        $code = $this->db->fetchColumn(
            "SELECT code FROM languages WHERE is_default = 1 LIMIT 1"
        );

        return is_string($code) ? $code : 'en';
    }

    /**
     * Set a language as the default.
     *
     * Unsets the current default first, then marks the given language.
     *
     * @param string $code Language code to set as default
     * @throws \RuntimeException If the language does not exist
     */
    public function setDefault(string $code): void
    {
        $language = $this->db->fetchOne(
            "SELECT code FROM languages WHERE code = :code",
            ['code' => $code]
        );

        if ($language === null) {
            throw new \RuntimeException("Language '$code' not found");
        }

        $this->db->beginTransaction();

        try {
            // Unset all defaults
            $this->db->query("UPDATE languages SET is_default = 0");

            // Set new default (also ensure it is active)
            $this->db->update('languages', [
                'is_default' => 1,
                'is_active' => 1,
            ], ['code' => $code]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Activate a language.
     *
     * @param string $code Language code
     */
    public function activate(string $code): void
    {
        $this->db->update('languages', ['is_active' => 1], ['code' => $code]);
    }

    /**
     * Deactivate a language.
     *
     * English ('en') cannot be deactivated as it is the fallback language.
     *
     * @param string $code Language code
     * @throws \InvalidArgumentException If attempting to deactivate English
     */
    public function deactivate(string $code): void
    {
        if ($code === 'en') {
            throw new \InvalidArgumentException('English cannot be deactivated — it is the fallback language');
        }

        // If this is the default language, do not allow deactivation
        $language = $this->db->fetchOne(
            "SELECT is_default FROM languages WHERE code = :code",
            ['code' => $code]
        );

        if ($language !== null && (bool) $language['is_default']) {
            throw new \InvalidArgumentException('Cannot deactivate the default language. Set another language as default first.');
        }

        $this->db->update('languages', ['is_active' => 0], ['code' => $code]);
    }

    // ──── Upload / import ────

    /**
     * Upload (create or update) a language with its translations.
     *
     * Validates the translation keys against the master en.json file,
     * calculates the completion percentage, inserts or updates the
     * language record, and writes the JSON translation file.
     *
     * @param string $code         Language code (e.g. 'mt', 'it')
     * @param string $name         Language name in English (e.g. 'Maltese')
     * @param string $nativeName   Language name in its own script (e.g. 'Malti')
     * @param array  $translations Key => value translation pairs
     */
    public function uploadLanguage(string $code, string $name, string $nativeName, array $translations): void
    {
        // Validate code format
        if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $code)) {
            throw new \InvalidArgumentException(
                "Invalid language code '$code'. Expected format: 'xx' or 'xx-XX'"
            );
        }

        // Filter translations to only include keys present in master
        $masterStrings = $this->getMasterStrings();
        $validTranslations = array_intersect_key($translations, $masterStrings);

        // Calculate completion
        $totalKeys = count($masterStrings);
        $completionPct = $totalKeys > 0
            ? round((count($validTranslations) / $totalKeys) * 100, 2)
            : 0.0;

        // Write the translation file
        $filePath = $this->langPath . '/' . $code . '.json';
        file_put_contents(
            $filePath,
            json_encode($validTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        // Upsert language record
        $existing = $this->db->fetchOne(
            "SELECT code FROM languages WHERE code = :code",
            ['code' => $code]
        );

        if ($existing !== null) {
            $this->db->update('languages', [
                'name'           => $name,
                'native_name'    => $nativeName,
                'is_active'      => 1,
                'completion_pct' => $completionPct,
                'source'         => 'uploaded',
            ], ['code' => $code]);
        } else {
            $this->db->insert('languages', [
                'code'           => $code,
                'name'           => $name,
                'native_name'    => $nativeName,
                'is_active'      => 1,
                'is_default'     => 0,
                'completion_pct' => $completionPct,
                'source'         => 'uploaded',
            ]);
        }
    }

    // ──── Overrides ────

    /**
     * Get all string overrides for a language.
     *
     * @param string $code Language code
     * @return array Override records with string_key and value
     */
    public function getOverrides(string $code): array
    {
        return $this->db->fetchAll(
            "SELECT id, string_key, value, updated_at
             FROM i18n_overrides
             WHERE language_code = :code
             ORDER BY string_key ASC",
            ['code' => $code]
        );
    }

    /**
     * Set (upsert) a string override for a language.
     *
     * @param string $code  Language code
     * @param string $key   Translation key (e.g. 'nav.members')
     * @param string $value Override value
     */
    public function setOverride(string $code, string $key, string $value): void
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM i18n_overrides WHERE language_code = :code AND string_key = :key",
            ['code' => $code, 'key' => $key]
        );

        if ($existing !== null) {
            $this->db->update('i18n_overrides', [
                'value' => $value,
            ], ['id' => (int) $existing['id']]);
        } else {
            $this->db->insert('i18n_overrides', [
                'language_code' => $code,
                'string_key'    => $key,
                'value'         => $value,
            ]);
        }
    }

    /**
     * Remove a string override for a language.
     *
     * @param string $code Language code
     * @param string $key  Translation key to clear
     */
    public function clearOverride(string $code, string $key): void
    {
        $this->db->delete('i18n_overrides', [
            'language_code' => $code,
            'string_key'    => $key,
        ]);
    }

    // ──── String inspection ────

    /**
     * Get all translation strings for a language merged with overrides.
     *
     * Returns each key with its English reference, translated value,
     * and whether a DB override exists.
     *
     * @param string $code Language code
     * @return array List of [{key, english, translated, has_override}]
     */
    public function getStringsForLanguage(string $code): array
    {
        $masterStrings = $this->getMasterStrings();

        // Load file translations
        $fileTranslations = [];
        $langFile = $this->langPath . '/' . $code . '.json';
        if (file_exists($langFile)) {
            $fileTranslations = json_decode(file_get_contents($langFile), true) ?? [];
        }

        // Load DB overrides
        $overrides = [];
        $overrideRows = $this->db->fetchAll(
            "SELECT string_key, value FROM i18n_overrides WHERE language_code = :code",
            ['code' => $code]
        );
        foreach ($overrideRows as $row) {
            $overrides[$row['string_key']] = $row['value'];
        }

        // Build merged list
        $result = [];
        foreach ($masterStrings as $key => $english) {
            $hasOverride = isset($overrides[$key]);
            $translated = $hasOverride
                ? $overrides[$key]
                : ($fileTranslations[$key] ?? '');

            $result[] = [
                'key'          => $key,
                'english'      => $english,
                'translated'   => $translated,
                'has_override' => $hasOverride,
            ];
        }

        return $result;
    }

    /**
     * Export the master (English) language file as formatted JSON.
     *
     * @return string JSON string
     */
    public function exportMasterFile(): string
    {
        return json_encode(
            $this->getMasterStrings(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Calculate the translation completion percentage for a language.
     *
     * Compares keys present in lang/{code}.json against the master en.json.
     *
     * @param string $code Language code
     * @return float Percentage (0.0 to 100.0)
     */
    public function calculateCompletion(string $code): float
    {
        $masterStrings = $this->getMasterStrings();
        $totalKeys = count($masterStrings);

        if ($totalKeys === 0) {
            return 100.0;
        }

        $langFile = $this->langPath . '/' . $code . '.json';
        if (!file_exists($langFile)) {
            return 0.0;
        }

        $langStrings = json_decode(file_get_contents($langFile), true) ?? [];
        $translated = count(array_intersect_key($langStrings, $masterStrings));

        return round(($translated / $totalKeys) * 100, 2);
    }

    // ──── Private helpers ────

    /**
     * Read and decode the master English language file.
     *
     * @return array Key => value pairs
     */
    private function getMasterStrings(): array
    {
        $masterFile = $this->langPath . '/en.json';

        if (!file_exists($masterFile)) {
            return [];
        }

        return json_decode(file_get_contents($masterFile), true) ?? [];
    }
}
