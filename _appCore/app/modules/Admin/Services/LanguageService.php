<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Services;

use AppCore\Core\Database;
use AppCore\Core\I18n;

/**
 * Language management: activate/deactivate, set defaults, and manage
 * per-installation string overrides that layer on top of JSON files.
 */
class LanguageService
{
    private Database $db;
    private I18n $i18n;

    public function __construct(Database $db, I18n $i18n)
    {
        $this->db = $db;
        $this->i18n = $i18n;
    }

    public function list(): array
    {
        return $this->i18n->getAvailableLanguages();
    }

    public function activate(string $code): void
    {
        $this->ensureRow($code);
        $this->db->update('languages', ['is_active' => 1], ['code' => $code]);
        $this->i18n->clearCache($code);
    }

    public function deactivate(string $code): void
    {
        $this->db->update('languages', ['is_active' => 0], ['code' => $code]);
        $this->i18n->clearCache($code);
    }

    public function setDefault(string $code): void
    {
        $this->db->query("UPDATE languages SET is_default = 0");
        $this->db->update('languages', ['is_default' => 1, 'is_active' => 1], ['code' => $code]);
    }

    /**
     * Upsert a per-installation override. A null value deletes the override.
     */
    public function saveOverride(string $code, string $stringKey, ?string $value): void
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM language_overrides WHERE language_code = :c AND string_key = :k",
            ['c' => $code, 'k' => $stringKey]
        );

        if ($value === null || $value === '') {
            if ($existing !== null) {
                $this->db->delete('language_overrides', ['id' => $existing['id']]);
            }
        } elseif ($existing === null) {
            $this->db->insert('language_overrides', [
                'language_code' => $code,
                'string_key'    => $stringKey,
                'value'         => $value,
            ]);
        } else {
            $this->db->update('language_overrides', ['value' => $value], ['id' => $existing['id']]);
        }

        $this->i18n->clearCache($code);
    }

    /**
     * @return array<string, array{master: string, override: ?string}>
     */
    public function getStrings(string $code): array
    {
        $master = $this->i18n->getMasterStrings();
        $overrides = $this->db->fetchAll(
            "SELECT string_key, value FROM language_overrides WHERE language_code = :c",
            ['c' => $code]
        );
        $overrideMap = [];
        foreach ($overrides as $row) {
            $overrideMap[$row['string_key']] = $row['value'];
        }

        $out = [];
        foreach ($master as $key => $value) {
            $out[$key] = [
                'master'   => (string) $value,
                'override' => $overrideMap[$key] ?? null,
            ];
        }
        return $out;
    }

    private function ensureRow(string $code): void
    {
        $existing = $this->db->fetchOne("SELECT code FROM languages WHERE code = :c", ['c' => $code]);
        if ($existing === null) {
            $this->db->insert('languages', [
                'code'        => $code,
                'name'        => $code,
                'native_name' => strtoupper($code),
                'is_active'   => 1,
                'is_default'  => 0,
                'source'      => 'bundled',
            ]);
        }
    }
}
