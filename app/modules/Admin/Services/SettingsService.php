<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Database;

/**
 * System settings service.
 *
 * Provides a key-value configuration store backed by the `settings` table.
 * Settings are grouped for logical organisation (general, registration,
 * security, gdpr, cron, etc.) and support single or bulk upsert operations.
 */
class SettingsService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Get a single setting value by key.
     *
     * Returns the stored value or the provided default if the key does
     * not exist. Values are stored as text and returned as-is; the caller
     * is responsible for type casting.
     *
     * @param string $key Setting key
     * @param mixed $default Value to return if the key is not found
     * @return mixed The setting value, or $default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->db->fetchOne(
            "SELECT `value` FROM `settings` WHERE `key` = :key",
            ['key' => $key]
        );

        if ($row === null) {
            return $default;
        }

        return $row['value'];
    }

    /**
     * Set (upsert) a single setting.
     *
     * Creates the setting if it does not exist, or updates its value and
     * group if it does. Non-string values are JSON-encoded before storage.
     *
     * @param string $key Setting key
     * @param mixed $value Setting value (will be cast to string/JSON)
     * @param string $group Logical group name
     */
    public function set(string $key, mixed $value, string $group = 'general'): void
    {
        $storedValue = $this->encodeValue($value);

        $this->db->query(
            "INSERT INTO `settings` (`key`, `value`, `group`)
             VALUES (:key, :value, :grp)
             ON DUPLICATE KEY UPDATE `value` = :value2, `group` = :grp2",
            [
                'key' => $key,
                'value' => $storedValue,
                'grp' => $group,
                'value2' => $storedValue,
                'grp2' => $group,
            ]
        );
    }

    /**
     * Get all settings in a specific group as a key => value map.
     *
     * @param string $group Group name
     * @return array Associative array of key => value
     */
    public function getGroup(string $group): array
    {
        $rows = $this->db->fetchAll(
            "SELECT `key`, `value` FROM `settings` WHERE `group` = :grp ORDER BY `key` ASC",
            ['grp' => $group]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }

        return $result;
    }

    /**
     * Get all settings, grouped by their group name.
     *
     * @return array Nested array: group => [key => value, ...]
     */
    public function getAll(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT `key`, `value`, `group` FROM `settings` ORDER BY `group` ASC, `key` ASC"
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['group']][$row['key']] = $row['value'];
        }

        return $grouped;
    }

    /**
     * Set multiple settings at once within a single group.
     *
     * Each entry in the array is upserted. This is a convenience wrapper
     * around set() for bulk operations.
     *
     * @param array $settings Associative array of key => value pairs
     * @param string $group Logical group name
     */
    public function setMultiple(array $settings, string $group = 'general'): void
    {
        foreach ($settings as $key => $value) {
            $this->set($key, $value, $group);
        }
    }

    /**
     * Delete a setting by key.
     *
     * @param string $key Setting key to remove
     */
    public function delete(string $key): void
    {
        $this->db->query(
            "DELETE FROM `settings` WHERE `key` = :key",
            ['key' => $key]
        );
    }

    // ── Internal ─────────────────────────────────────────────────────

    /**
     * Encode a value for storage.
     *
     * Strings are stored as-is. Booleans are converted to '1'/'0'.
     * Arrays and objects are JSON-encoded. Null is stored as null.
     *
     * @param mixed $value The value to encode
     * @return string|null The encoded string representation
     */
    private function encodeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
