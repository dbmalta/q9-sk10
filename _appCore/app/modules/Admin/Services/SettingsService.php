<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Services;

use AppCore\Core\Database;

/**
 * Key/value settings store.
 *
 * Values are scalar strings. Callers that need structure should JSON-encode
 * on write and decode on read.
 */
class SettingsService
{
    private Database $db;
    private ?array $cache = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $this->load();
        return $this->cache[$key] ?? $default;
    }

    public function all(): array
    {
        $this->load();
        return $this->cache;
    }

    public function byGroup(string $group): array
    {
        return $this->db->fetchAll(
            "SELECT `key`, `value`, `group` FROM settings WHERE `group` = :g ORDER BY `key`",
            ['g' => $group]
        );
    }

    public function set(string $key, string $value, string $group = 'general'): void
    {
        $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = :k", ['k' => $key]);
        if ($existing === null) {
            $this->db->insert('settings', ['key' => $key, 'value' => $value, 'group' => $group]);
        } else {
            $this->db->update('settings', ['value' => $value], ['key' => $key]);
        }
        $this->cache = null;
    }

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }
        $rows = $this->db->fetchAll("SELECT `key`, `value` FROM settings");
        $this->cache = [];
        foreach ($rows as $row) {
            $this->cache[$row['key']] = $row['value'];
        }
    }
}
