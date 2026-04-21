<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Internationalisation (i18n) system.
 *
 * Loads translations from JSON language files and allows per-installation
 * string overrides stored in the database. DB overrides take precedence
 * over file-based translations.
 */
class I18n
{
    private string $langPath;
    private ?Database $db;
    private string $language;
    private array $translations = [];
    private bool $loaded = false;
    private ?array $availableLanguagesCache = null;

    /**
     * @param string $langPath Path to the /lang/ directory
     * @param Database|null $db Database connection (null if not yet available)
     * @param string $language Current language code (e.g. 'en')
     */
    public function __construct(string $langPath, ?Database $db, string $language = 'en')
    {
        $this->langPath = $langPath;
        $this->db = $db;
        $this->language = $language;
    }

    /**
     * Translate a key with optional placeholder replacement.
     *
     * Missing keys return the key itself and are logged for review.
     *
     * @param string $key Translation key e.g. 'nav.members'
     * @param array $params Placeholder values e.g. ['name' => 'John']
     * @return string The translated string
     */
    public function t(string $key, array $params = []): string
    {
        $this->load();

        $text = $this->translations[$key] ?? $key;

        // Replace {placeholder} tokens
        foreach ($params as $placeholder => $value) {
            $text = str_replace('{' . $placeholder . '}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * Get the current language code.
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Set the active language (reloads translations).
     */
    public function setLanguage(string $language): void
    {
        $this->language = $language;
        $this->loaded = false;
        $this->translations = [];
    }

    /**
     * Get all translations for the current language (merged: file + DB overrides).
     */
    public function getAll(): array
    {
        $this->load();
        return $this->translations;
    }

    /**
     * Get all keys from the master (English) language file.
     * This is the definitive list of every translatable string.
     */
    public function getMasterStrings(): array
    {
        $masterFile = $this->langPath . '/en.json';
        if (!file_exists($masterFile)) {
            return [];
        }
        return json_decode(file_get_contents($masterFile), true) ?? [];
    }

    /**
     * Export the master language file content for download.
     */
    public function exportMasterFile(): string
    {
        return json_encode($this->getMasterStrings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get available languages from /lang/ JSON files and the languages DB table.
     *
     * @return array List of languages with code, name, native_name, is_active, completion_pct
     */
    public function getAvailableLanguages(): array
    {
        if ($this->availableLanguagesCache !== null) {
            return $this->availableLanguagesCache;
        }

        $languages = [];

        // Scan filesystem for language files. Any file present is considered
        // available and active by default; the DB overlay below can override
        // these flags for languages that have been explicitly registered.
        $files = glob($this->langPath . '/*.json');
        foreach ($files as $file) {
            $code = pathinfo($file, PATHINFO_FILENAME);
            $languages[$code] = [
                'code' => $code,
                'name' => $code,
                'native_name' => strtoupper($code),
                'is_active' => true,
                'is_default' => $code === 'en',
                'completion_pct' => $code === 'en' ? 100 : $this->getCompletionPercentage($code),
            ];
        }

        // Merge with DB language records if available
        if ($this->db !== null) {
            try {
                $dbLanguages = $this->db->fetchAll("SELECT * FROM languages");
                foreach ($dbLanguages as $row) {
                    if (isset($languages[$row['code']])) {
                        $languages[$row['code']]['name'] = $row['name'];
                        $languages[$row['code']]['native_name'] = $row['native_name'];
                        $languages[$row['code']]['is_active'] = (bool) $row['is_active'];
                        $languages[$row['code']]['is_default'] = (bool) $row['is_default'];
                    } else {
                        // Language registered in DB but JSON file not found by glob() — include it
                        // so it appears in the switcher even if the translation file is unavailable
                        // (falls back to English strings at runtime).
                        $languages[$row['code']] = [
                            'code'           => $row['code'],
                            'name'           => $row['name'],
                            'native_name'    => $row['native_name'],
                            'is_active'      => (bool) $row['is_active'],
                            'is_default'     => (bool) $row['is_default'],
                            'completion_pct' => (float) ($row['completion_pct'] ?? 0),
                        ];
                    }
                }
            } catch (\PDOException) {
                // Table may not exist yet during setup
            }
        }

        $this->availableLanguagesCache = array_values($languages);
        return $this->availableLanguagesCache;
    }

    /**
     * Calculate the translation completion percentage for a language.
     */
    public function getCompletionPercentage(string $language): int
    {
        $masterKeys = array_keys($this->getMasterStrings());
        if (empty($masterKeys)) {
            return 100;
        }

        $langFile = $this->langPath . '/' . $language . '.json';
        if (!file_exists($langFile)) {
            return 0;
        }

        $langStrings = json_decode(file_get_contents($langFile), true) ?? [];
        $translated = count(array_intersect_key($langStrings, array_flip($masterKeys)));

        return (int) round(($translated / count($masterKeys)) * 100);
    }

    /**
     * Get keys present in master but missing from a given language.
     */
    public function getMissing(string $language): array
    {
        $masterKeys = array_keys($this->getMasterStrings());
        $langFile = $this->langPath . '/' . $language . '.json';

        if (!file_exists($langFile)) {
            return $masterKeys;
        }

        $langStrings = json_decode(file_get_contents($langFile), true) ?? [];
        return array_values(array_diff($masterKeys, array_keys($langStrings)));
    }

    /**
     * Clear the translation cache for a language.
     */
    public function clearCache(string $language): void
    {
        $cacheFile = ROOT_PATH . '/var/cache/i18n_' . $language . '.json';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        if ($language === $this->language) {
            $this->loaded = false;
            $this->translations = [];
        }
    }

    /**
     * Load translations from file + DB overrides, with caching.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        // Try cache first
        $cacheFile = ROOT_PATH . '/var/cache/i18n_' . $this->language . '.json';
        if (file_exists($cacheFile)) {
            $this->translations = json_decode(file_get_contents($cacheFile), true) ?? [];
            $this->loaded = true;
            return;
        }

        // Load from English base (always loaded as fallback)
        $this->translations = $this->getMasterStrings();

        // Overlay target language if different from English
        if ($this->language !== 'en') {
            $langFile = $this->langPath . '/' . $this->language . '.json';
            if (file_exists($langFile)) {
                $langStrings = json_decode(file_get_contents($langFile), true) ?? [];
                $this->translations = array_merge($this->translations, $langStrings);
            }
        }

        // Apply DB overrides
        if ($this->db !== null) {
            try {
                $overrides = $this->db->fetchAll(
                    "SELECT string_key, value FROM i18n_overrides WHERE language_code = :lang",
                    ['lang' => $this->language]
                );
                foreach ($overrides as $row) {
                    $this->translations[$row['string_key']] = $row['value'];
                }
            } catch (\PDOException) {
                // Table may not exist yet during setup
            }
        }

        // Write cache
        $cacheDir = dirname($cacheFile);
        if (is_dir($cacheDir)) {
            file_put_contents($cacheFile, json_encode($this->translations, JSON_UNESCAPED_UNICODE));
        }

        $this->loaded = true;
    }
}
