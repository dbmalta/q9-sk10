# Pattern — Custom Fields

> One-line purpose: let admins attach per-entity fields that weren't known at schema-design time, without `ALTER TABLE`.

## When to use this pattern
- Domain entities (members, clients, tickets) where every deployment adds its own bespoke fields.
- Fields users need to filter or display but not index.
- Admin-editable schemas — when the field set should be configurable via UI, not a migration.

Does NOT fit:
- Fields you need to index heavily, join on, or aggregate at scale — add a real column.
- Cross-entity relationships or normalised look-ups — use proper FK tables.

## Two approaches

**(a) JSON column on the entity** — fastest, but no admin UI, no validation metadata, fields "drift" per row.
**(b) Definitions + values tables** — recommended when admins add fields via UI or field-level validation is needed.

Ship (b) by default. Use (a) only for unstructured bags where admins never configure anything.

## Schema

```sql
-- Migration: 0021_custom_fields.sql

-- (a) Simple: JSON column on the entity
ALTER TABLE clients
    ADD COLUMN custom_data JSON NULL AFTER notes;

-- (b) Structured: definitions + values
CREATE TABLE custom_field_definitions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(40)  NOT NULL,
    field_key   VARCHAR(60)  NOT NULL,
    label       VARCHAR(150) NOT NULL,
    field_type  ENUM('text','textarea','number','date','select','checkbox') NOT NULL,
    options     JSON NULL,
    required    TINYINT(1) NOT NULL DEFAULT 0,
    sort_order  INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_entity_key (entity_type, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE custom_field_values (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    definition_id INT UNSIGNED NOT NULL,
    entity_type   VARCHAR(40) NOT NULL,
    entity_id     INT UNSIGNED NOT NULL,
    value_text    TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_field_entity (definition_id, entity_type, entity_id),
    KEY idx_entity (entity_type, entity_id),
    CONSTRAINT fk_cfv_def FOREIGN KEY (definition_id) REFERENCES custom_field_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- `entity_type` is a free-form discriminator like `'client'` or `'ticket'`. Keep a short authoritative list in code.
- `options` holds enum choices for `select` fields: `{"choices":["red","green","blue"]}`.
- Only one `value_text` column — cast on read. Typed columns per field type explode quickly and rarely pay off.

## Service skeleton

```php
<?php
declare(strict_types=1);
namespace AppCore\Modules\CustomFields\Services;

use AppCore\Core\Database;

final class CustomFieldService
{
    public function __construct(private readonly Database $db) {}

    /** @return list<array<string,mixed>> */
    public function definitionsFor(string $entityType): array
    {
        return $this->db->fetchAll(
            'SELECT id, field_key, label, field_type, options, required, sort_order
               FROM custom_field_definitions
              WHERE entity_type = ? AND is_active = 1
              ORDER BY sort_order, label',
            [$entityType]
        );
    }

    /** @return array<string,string|null>  keyed by field_key */
    public function valuesFor(string $entityType, int $entityId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT d.field_key, v.value_text
               FROM custom_field_definitions d
          LEFT JOIN custom_field_values v
                 ON v.definition_id = d.id AND v.entity_type = d.entity_type AND v.entity_id = ?
              WHERE d.entity_type = ? AND d.is_active = 1',
            [$entityId, $entityType]
        );
        $out = [];
        foreach ($rows as $r) { $out[$r['field_key']] = $r['value_text']; }
        return $out;
    }

    /**
     * @param array<string,string|null> $values keyed by field_key
     * @return array{success:bool,errors:array<string,string>}
     */
    public function save(string $entityType, int $entityId, array $values): array
    {
        $defs = $this->definitionsFor($entityType);
        $errors = [];
        foreach ($defs as $d) {
            $raw = $values[$d['field_key']] ?? null;
            if ($d['required'] && ($raw === null || $raw === '')) {
                $errors[$d['field_key']] = 'required';
            }
        }
        if ($errors !== []) return ['success' => false, 'errors' => $errors];

        $this->db->beginTransaction();
        try {
            foreach ($defs as $d) {
                $raw = $values[$d['field_key']] ?? null;
                $this->db->query(
                    'INSERT INTO custom_field_values (definition_id, entity_type, entity_id, value_text)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE value_text = VALUES(value_text)',
                    [$d['id'], $entityType, $entityId, $raw]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback(); throw $e;
        }
        return ['success' => true, 'errors' => []];
    }

    public function defineField(string $entityType, string $key, string $label, string $type, ?array $options = null, bool $required = false): int
    {
        return $this->db->insert('custom_field_definitions', [
            'entity_type' => $entityType,
            'field_key'   => $key,
            'label'       => $label,
            'field_type'  => $type,
            'options'     => $options ? json_encode($options) : null,
            'required'    => $required ? 1 : 0,
        ]);
    }
}
```

## Controller integration

```php
public function update(Request $request, int $id): Response
{
    if ($c = $this->requirePermission('clients.write')) return $c;

    $core   = $this->clients->update($id, $request->getParam('name', ''));
    $custom = $this->customFields->save('client', $id, (array) $request->getParam('custom', []));

    if (!$custom['success']) {
        $this->flash('error', t('custom_fields.validation_failed'));
        return $this->redirect(route('clients.edit', ['id' => $id]));
    }
    $this->audit->log('client', $id, 'update', $core['old'], $core['new'], $this->userId());
    return $this->redirect(route('clients.show', ['id' => $id]));
}
```

## Template hints

Render a partial `_custom_fields_form.html.twig` that loops the definitions and switches on `field_type`. On display pages, call `valuesFor()` once and pass as a keyed array, then render via a `_custom_fields_display.html.twig` include so every entity looks the same.

## Pitfalls

- JSON approach (a) cannot enforce required fields at the DB level — validation must live in the service, and historical rows silently miss new required fields.
- `value_text` is TEXT — casting to int/date at query time defeats any index. Do not try to ORDER BY or WHERE on it for large tables; add a real column if you need that.
- Deleting a definition cascades to values; consider setting `is_active = 0` to retain historical data.
- Do not let users pick their own `field_key` freely — sanitise to `[a-z0-9_]+` to keep templates and URLs sane.
- Do not reuse `field_key`s across entity types and assume they share meaning — they don't; the unique key is `(entity_type, field_key)`.

## Further reading
- [timeline.md](timeline.md) — log custom-field schema changes so admins can audit who added what.
- ADR-0005 (no ORM) — approach (b) looks ORM-ish but is just two hand-queried tables.
