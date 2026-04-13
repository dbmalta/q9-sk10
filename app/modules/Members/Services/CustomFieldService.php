<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Core\Database;

/**
 * Custom field definitions engine.
 *
 * Manages field definitions (CRUD, reorder, activation) and provides
 * validation and rendering helpers for custom member data stored in
 * the members.member_custom_data JSON column.
 */
class CustomFieldService
{
    private Database $db;

    /** @var array Allowed field types */
    public const FIELD_TYPES = ['short_text', 'long_text', 'number', 'dropdown', 'date'];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ── Definition CRUD ──────────────────────────────────────────────

    /**
     * Get all field definitions, optionally filtered by active status.
     *
     * @param bool|null $activeOnly Null = all, true = active only, false = inactive only
     * @return array
     */
    public function getDefinitions(?bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM `custom_field_definitions`";
        $params = [];

        if ($activeOnly !== null) {
            $sql .= " WHERE `is_active` = ?";
            $params[] = $activeOnly ? 1 : 0;
        }

        $sql .= " ORDER BY `sort_order` ASC, `id` ASC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get a single field definition by ID.
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM `custom_field_definitions` WHERE `id` = ?",
            [$id]
        );
        return $row ?: null;
    }

    /**
     * Get a single field definition by field key.
     *
     * @param string $fieldKey
     * @return array|null
     */
    public function getByKey(string $fieldKey): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM `custom_field_definitions` WHERE `field_key` = ?",
            [$fieldKey]
        );
        return $row ?: null;
    }

    /**
     * Create a new custom field definition.
     *
     * @param array $data Must contain: field_key, field_type, label
     * @return int Inserted ID
     * @throws \InvalidArgumentException
     */
    public function create(array $data): int
    {
        $this->validateDefinition($data);

        // Check uniqueness of field_key
        $existing = $this->getByKey($data['field_key']);
        if ($existing) {
            throw new \InvalidArgumentException("Field key '{$data['field_key']}' already exists.");
        }

        // Determine next sort_order
        $maxSort = $this->db->fetchOne(
            "SELECT COALESCE(MAX(`sort_order`), 0) AS `max_sort` FROM `custom_field_definitions`"
        );
        $nextSort = (int) ($maxSort['max_sort'] ?? 0) + 10;

        $validationRules = $this->normaliseValidationRules($data);

        $this->db->query(
            "INSERT INTO `custom_field_definitions`
             (`field_key`, `field_type`, `label`, `description`, `is_required`,
              `validation_rules`, `display_group`, `sort_order`, `is_active`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['field_key'],
                $data['field_type'],
                $data['label'],
                $data['description'] ?? null,
                isset($data['is_required']) ? (int) $data['is_required'] : 0,
                $validationRules ? json_encode($validationRules) : null,
                $data['display_group'] ?? 'additional',
                $data['sort_order'] ?? $nextSort,
                isset($data['is_active']) ? (int) $data['is_active'] : 1,
            ]
        );

        return $this->db->lastInsertId();
    }

    /**
     * Update an existing custom field definition.
     *
     * @param int $id
     * @param array $data
     * @throws \InvalidArgumentException
     */
    public function update(int $id, array $data): void
    {
        $existing = $this->getById($id);
        if (!$existing) {
            throw new \InvalidArgumentException("Custom field #{$id} not found.");
        }

        $sets = [];
        $params = [];

        // field_key change — check uniqueness
        if (isset($data['field_key']) && $data['field_key'] !== $existing['field_key']) {
            $conflict = $this->getByKey($data['field_key']);
            if ($conflict) {
                throw new \InvalidArgumentException("Field key '{$data['field_key']}' already exists.");
            }
            $sets[] = "`field_key` = ?";
            $params[] = $data['field_key'];
        }

        // field_type change
        if (isset($data['field_type'])) {
            if (!in_array($data['field_type'], self::FIELD_TYPES, true)) {
                throw new \InvalidArgumentException("Invalid field type: {$data['field_type']}");
            }
            $sets[] = "`field_type` = ?";
            $params[] = $data['field_type'];
        }

        $simpleFields = ['label', 'description', 'display_group'];
        foreach ($simpleFields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "`{$field}` = ?";
                $params[] = $data[$field];
            }
        }

        if (array_key_exists('is_required', $data)) {
            $sets[] = "`is_required` = ?";
            $params[] = (int) $data['is_required'];
        }

        if (array_key_exists('is_active', $data)) {
            $sets[] = "`is_active` = ?";
            $params[] = (int) $data['is_active'];
        }

        if (array_key_exists('sort_order', $data)) {
            $sets[] = "`sort_order` = ?";
            $params[] = (int) $data['sort_order'];
        }

        if (array_key_exists('validation_rules', $data)) {
            $rules = $data['validation_rules'];
            if (is_array($rules)) {
                $rules = json_encode($rules);
            }
            $sets[] = "`validation_rules` = ?";
            $params[] = $rules;
        }

        if (empty($sets)) {
            return; // Nothing to update
        }

        $params[] = $id;
        $this->db->query(
            "UPDATE `custom_field_definitions` SET " . implode(', ', $sets) . " WHERE `id` = ?",
            $params
        );
    }

    /**
     * Soft-deactivate a custom field definition.
     *
     * @param int $id
     */
    public function deactivate(int $id): void
    {
        $this->db->query(
            "UPDATE `custom_field_definitions` SET `is_active` = 0 WHERE `id` = ?",
            [$id]
        );
    }

    /**
     * Re-activate a custom field definition.
     *
     * @param int $id
     */
    public function activate(int $id): void
    {
        $this->db->query(
            "UPDATE `custom_field_definitions` SET `is_active` = 1 WHERE `id` = ?",
            [$id]
        );
    }

    /**
     * Reorder field definitions. Accepts an array of IDs in desired order.
     *
     * @param array $orderedIds Array of definition IDs in desired display order
     */
    public function reorder(array $orderedIds): void
    {
        $sortOrder = 10;
        foreach ($orderedIds as $id) {
            $this->db->query(
                "UPDATE `custom_field_definitions` SET `sort_order` = ? WHERE `id` = ?",
                [$sortOrder, (int) $id]
            );
            $sortOrder += 10;
        }
    }

    // ── Field Grouping ───────────────────────────────────────────────

    /**
     * Get active field definitions for a display group.
     *
     * @param string $group
     * @return array
     */
    public function getFieldsForGroup(string $group): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM `custom_field_definitions`
             WHERE `display_group` = ? AND `is_active` = 1
             ORDER BY `sort_order` ASC, `id` ASC",
            [$group]
        );
    }

    // ── Validation ───────────────────────────────────────────────────

    /**
     * Validate a single field value against its definition.
     *
     * @param array $definition The field definition row
     * @param mixed $value The submitted value
     * @return string|null Error message or null if valid
     */
    public function validateFieldValue(array $definition, mixed $value): ?string
    {
        $key = $definition['field_key'];
        $type = $definition['field_type'];
        $required = (bool) $definition['is_required'];
        $rules = $definition['validation_rules'] ?? null;

        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }

        // Required check
        if ($required && ($value === null || $value === '')) {
            return "The field \"{$definition['label']}\" is required.";
        }

        // Empty non-required field is always valid
        if ($value === null || $value === '') {
            return null;
        }

        switch ($type) {
            case 'short_text':
                if (!is_string($value)) {
                    return "The field \"{$definition['label']}\" must be text.";
                }
                $maxLen = $rules['max_length'] ?? 255;
                if (mb_strlen($value) > $maxLen) {
                    return "The field \"{$definition['label']}\" must be at most {$maxLen} characters.";
                }
                break;

            case 'long_text':
                if (!is_string($value)) {
                    return "The field \"{$definition['label']}\" must be text.";
                }
                $maxLen = $rules['max_length'] ?? 10000;
                if (mb_strlen($value) > $maxLen) {
                    return "The field \"{$definition['label']}\" must be at most {$maxLen} characters.";
                }
                break;

            case 'number':
                if (!is_numeric($value)) {
                    return "The field \"{$definition['label']}\" must be a number.";
                }
                $numVal = (float) $value;
                if (isset($rules['min']) && $numVal < (float) $rules['min']) {
                    return "The field \"{$definition['label']}\" must be at least {$rules['min']}.";
                }
                if (isset($rules['max']) && $numVal > (float) $rules['max']) {
                    return "The field \"{$definition['label']}\" must be at most {$rules['max']}.";
                }
                break;

            case 'dropdown':
                $options = $rules['dropdown_options'] ?? [];
                if (!empty($options) && !in_array($value, $options, true)) {
                    $allowed = implode(', ', $options);
                    return "The field \"{$definition['label']}\" must be one of: {$allowed}.";
                }
                break;

            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                    return "The field \"{$definition['label']}\" must be a valid date (YYYY-MM-DD).";
                }
                $parts = explode('-', (string) $value);
                if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                    return "The field \"{$definition['label']}\" contains an invalid date.";
                }
                break;
        }

        return null;
    }

    /**
     * Validate all custom data against active field definitions.
     *
     * @param array $data Key-value pairs of custom data (field_key => value)
     * @return array Array of errors (field_key => error message). Empty if valid.
     */
    public function validateAllCustomData(array $data): array
    {
        $definitions = $this->getDefinitions(true);
        $errors = [];

        foreach ($definitions as $def) {
            $value = $data[$def['field_key']] ?? null;
            $error = $this->validateFieldValue($def, $value);
            if ($error !== null) {
                $errors[$def['field_key']] = $error;
            }
        }

        return $errors;
    }

    /**
     * Filter custom data to only include keys matching active field definitions.
     * Strips unknown keys and coerces types.
     *
     * @param array $data Raw submitted custom data
     * @return array Cleaned data
     */
    public function sanitiseCustomData(array $data): array
    {
        $definitions = $this->getDefinitions(true);
        $validKeys = array_column($definitions, 'field_key');
        $cleaned = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $validKeys, true)) {
                $cleaned[$key] = $value === '' ? null : $value;
            }
        }

        return $cleaned;
    }

    // ── Rendering ────────────────────────────────────────────────────

    /**
     * Get rendering context for a field definition (for Twig templates).
     *
     * Returns the definition enriched with parsed validation_rules so
     * templates can render the correct input type, options, and constraints.
     *
     * @param array $definition
     * @param mixed $value Current value (or null)
     * @return array Template-ready field data
     */
    public function renderField(array $definition, mixed $value = null): array
    {
        $rules = $definition['validation_rules'] ?? null;
        if (is_string($rules)) {
            $rules = json_decode($rules, true) ?? [];
        }
        if ($rules === null) {
            $rules = [];
        }

        return [
            'id' => $definition['id'],
            'key' => $definition['field_key'],
            'type' => $definition['field_type'],
            'label' => $definition['label'],
            'description' => $definition['description'] ?? '',
            'required' => (bool) $definition['is_required'],
            'value' => $value,
            'rules' => $rules,
            'options' => $rules['dropdown_options'] ?? [],
            'min' => $rules['min'] ?? null,
            'max' => $rules['max'] ?? null,
            'max_length' => $rules['max_length'] ?? null,
        ];
    }

    /**
     * Get all active fields with rendering context and current values.
     *
     * @param array $customData Current member_custom_data values
     * @param string $group Display group (default: 'additional')
     * @return array Array of renderField() results
     */
    public function getRenderableFields(array $customData = [], string $group = 'additional'): array
    {
        $definitions = $this->getFieldsForGroup($group);
        $fields = [];

        foreach ($definitions as $def) {
            $value = $customData[$def['field_key']] ?? null;
            $fields[] = $this->renderField($def, $value);
        }

        return $fields;
    }

    // ── Internal Helpers ─────────────────────────────────────────────

    /**
     * Validate required fields for a definition.
     *
     * @param array $data
     * @throws \InvalidArgumentException
     */
    private function validateDefinition(array $data): void
    {
        if (empty($data['field_key'])) {
            throw new \InvalidArgumentException("Field key is required.");
        }

        if (!preg_match('/^[a-z][a-z0-9_]{0,99}$/', $data['field_key'])) {
            throw new \InvalidArgumentException(
                "Field key must start with a lowercase letter, contain only lowercase letters, digits, and underscores, and be at most 100 characters."
            );
        }

        if (empty($data['field_type']) || !in_array($data['field_type'], self::FIELD_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Field type must be one of: " . implode(', ', self::FIELD_TYPES) . "."
            );
        }

        if (empty($data['label'])) {
            throw new \InvalidArgumentException("Label is required.");
        }
    }

    /**
     * Parse and normalise validation rules from input data.
     *
     * @param array $data
     * @return array|null
     */
    private function normaliseValidationRules(array $data): ?array
    {
        // If validation_rules is already provided as-is, use it
        if (isset($data['validation_rules'])) {
            $rules = $data['validation_rules'];
            if (is_string($rules)) {
                $rules = json_decode($rules, true);
            }
            return is_array($rules) ? $rules : null;
        }

        // Build rules from individual fields (form submission pattern)
        $rules = [];

        if (isset($data['dropdown_options']) && $data['field_type'] === 'dropdown') {
            $options = $data['dropdown_options'];
            if (is_string($options)) {
                $options = array_map('trim', explode("\n", $options));
                $options = array_values(array_filter($options, fn($o) => $o !== ''));
            }
            $rules['dropdown_options'] = $options;
        }

        if (isset($data['min']) && $data['field_type'] === 'number') {
            $rules['min'] = (float) $data['min'];
        }
        if (isset($data['max']) && $data['field_type'] === 'number') {
            $rules['max'] = (float) $data['max'];
        }
        if (isset($data['max_length'])) {
            $rules['max_length'] = (int) $data['max_length'];
        }

        return !empty($rules) ? $rules : null;
    }
}
