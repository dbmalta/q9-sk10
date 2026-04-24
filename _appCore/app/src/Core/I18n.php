<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * Internationalisation.
 *
 * Loads translations from /lang/{code}.json and layers per-installation
 * overrides from the `language_overrides` table on top. Missing keys
 * return the key itself so absent translations are visible at a glance.
 */
class I18n
{
    private string $langPath;
    private ?Database $db;
    private string $language;
    private array $translations = [];
    private bool $loaded = false;
    private ?array $availableLanguagesCache = null;

    public function __construct(string $langPath, ?Database $db, string $language = 'en')
    {
        $this->langPath = $langPath;
        $this->db = $db;
        $this->language = $language;
    }

    public function t(string $key, array $params = []): string
    {
        $this->load();
        $text = $this->translations[$key] ?? $key;
        foreach ($params as $placeholder => $value) {
            $text = str_replace('{' . $placeholder . '}', (string) $value, $text);
        }
        return $text;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): void
    {
        $this->language = $language;
        $this->loaded = false;
        $this->translations = [];
    }

    public function getAll(): array
    {
        $this->load();
        return $this->translations;
    }

    public function getMasterStrings(): array
    {
        $masterFile = $this->langPath . '/en.json';
        if (!file_exists($masterFile)) {
            return [];
        }
        return json_decode((string) file_get_contents($masterFile), true) ?? [];
    }

    /**
     * List languages from filesystem files and from the `languages` DB table.
     */
    public function getAvailableLanguages(): array
    {
        if ($this->availableLanguagesCache !== null) {
            return $this->availableLanguagesCache;
        }

        $languages = [];

        $files = glob($this->langPath . '/*.json') ?: [];
        foreach ($files as $file) {
            $code = pathinfo($file, PATHINFO_FILENAME);
            $languages[$code] = [
                'code'           => $code,
                'name'           => $code,
                'native_name'    => strtoupper($code),
                'is_active'      => true,
                'is_default'     => $code === 'en',
                'completion_pct' => $code === 'en' ? 100 : $this->getCompletionPercentage($code),
            ];
        }

        if ($this->db !== null) {
            try {
                $dbLanguages = $this->db->fetchAll("SELECT * FROM languages");
                foreach ($dbLanguages as $row) {
                    if (isset($languages[$row['code']])) {
                        $languages[$row['code']]['name']        = $row['name'];
                        $languages[$row['code']]['native_name'] = $row['native_name'];
                        $languages[$row['code']]['is_active']   = (bool) $row['is_active'];
                        $languages[$row['code']]['is_default']  = (bool) $row['is_default'];
                    } else {
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
                // languages table may not exist yet
            }
        }

        $this->availableLanguagesCache = array_values($languages);
        return $this->availableLanguagesCache;
    }

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

        $langStrings = json_decode((string) file_get_contents($langFile), true) ?? [];
        $translated = count(array_intersect_key($langStrings, array_flip($masterKeys)));

        return (int) round(($translated / count($masterKeys)) * 100);
    }

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

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $cacheFile = ROOT_PATH . '/var/cache/i18n_' . $this->language . '.json';
        if (file_exists($cacheFile)) {
            $this->translations = json_decode((string) file_get_contents($cacheFile), true) ?? [];
            $this->loaded = true;
            return;
        }

        $this->translations = $this->getMasterStrings();

        if ($this->language !== 'en') {
            $langFile = $this->langPath . '/' . $this->language . '.json';
            if (file_exists($langFile)) {
                $langStrings = json_decode((string) file_get_contents($langFile), true) ?? [];
                $this->translations = array_merge($this->translations, $langStrings);
            }
        }

        if ($this->db !== null) {
            try {
                $overrides = $this->db->fetchAll(
                    "SELECT string_key, value FROM language_overrides WHERE language_code = :lang",
                    ['lang' => $this->language]
                );
                foreach ($overrides as $row) {
                    $this->translations[$row['string_key']] = $row['value'];
                }
            } catch (\PDOException) {
                // Table may not exist during setup
            }
        }

        $cacheDir = dirname($cacheFile);
        if (is_dir($cacheDir)) {
            file_put_contents($cacheFile, json_encode($this->translations, JSON_UNESCAPED_UNICODE));
        }

        $this->loaded = true;
    }
}
