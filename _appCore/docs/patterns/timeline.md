# Pattern — Timeline (Per-entity activity feed)

> One-line purpose: a user-facing chronological feed of meaningful events for a specific entity (a client, a ticket, a member).

## When to use this pattern
- Entity detail pages that need "what happened and when" in human terms.
- Cross-cutting event aggregation — uploads, status changes, notes, communications — rendered in one feed.
- Narrative history: things a user reads, not a tamper-evident compliance log.

Does NOT fit:
- Compliance or forensic change-log — use `audit_log` instead. Audit is append-only, tamper-evident, machine-shaped. Timeline is user copy; it can be edited, re-worded, translated.
- High-volume machine events (webhook deliveries, metric samples) — those belong in their own log, not in a shared `timeline_events` table.

## Schema

```sql
-- Migration: 0022_timeline_events.sql
CREATE TABLE timeline_events (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(40) NOT NULL,
    entity_id   INT UNSIGNED NOT NULL,
    event_type  VARCHAR(60) NOT NULL,
    actor_id    INT UNSIGNED NULL,
    payload     JSON NULL,
    occurred_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_entity_time (entity_type, entity_id, occurred_at DESC),
    KEY idx_actor (actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- `event_type` is a namespaced string like `'client.status_changed'` or `'member.attachment_added'` — keep a registry in code.
- `payload` is free-form context the UI uses to render the event line (old/new status, filename, etc.); never rely on it for queries.
- `occurred_at` uses millisecond precision to keep sort stable when many events fire in the same second (common during imports).

## Service skeleton

```php
<?php
declare(strict_types=1);
namespace AppCore\Modules\Timeline\Services;

use AppCore\Core\Database;

final class TimelineService
{
    public function __construct(private readonly Database $db) {}

    public function record(
        string $entityType,
        int $entityId,
        string $eventType,
        ?int $actorId = null,
        array $payload = []
    ): int {
        return $this->db->insert('timeline_events', [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'event_type'  => $eventType,
            'actor_id'    => $actorId,
            'payload'     => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function forEntity(string $entityType, int $entityId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->db->fetchAll(
            'SELECT t.id, t.event_type, t.actor_id, t.payload, t.occurred_at,
                    u.name AS actor_name
               FROM timeline_events t
          LEFT JOIN users u ON u.id = t.actor_id
              WHERE t.entity_type = ? AND t.entity_id = ?
              ORDER BY t.occurred_at DESC, t.id DESC
              LIMIT ? OFFSET ?',
            [$entityType, $entityId, $limit, $offset]
        );
        foreach ($rows as &$r) {
            $r['payload'] = $r['payload'] ? json_decode($r['payload'], true) : [];
        }
        return $rows;
    }

    public function count(string $entityType, int $entityId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM timeline_events WHERE entity_type = ? AND entity_id = ?',
            [$entityType, $entityId]
        );
        return (int) ($row['c'] ?? 0);
    }

    public function delete(int $id): void
    {
        // Timeline is user-facing — may legitimately be edited/deleted. Audit this in audit_log.
        $this->db->query('DELETE FROM timeline_events WHERE id = ?', [$id]);
    }
}
```

## Controller integration

```php
public function show(Request $request, int $id): Response
{
    if ($c = $this->requirePermission('clients.read')) return $c;

    $client = $this->clients->find($id);
    if ($client === null) return $this->notFound();

    $timeline = $this->timeline->forEntity('client', $id, limit: 50);

    if ($request->isHtmx() && $request->getParam('partial') === 'timeline') {
        return $this->render('@clients/_timeline.html.twig', ['events' => $timeline]);
    }
    return $this->render('@clients/show.html.twig', [
        'client'   => $client,
        'timeline' => $timeline,
    ]);
}
```

Callers record events from their own service, not the controller:

```php
$this->timeline->record('client', $clientId, 'client.status_changed', $userId, [
    'from' => $old['status'], 'to' => $new['status'],
]);
```

## Template hints

Render with `hx-get="/clients/{id}?partial=timeline" hx-trigger="revealed"` to lazy-load long histories. Match each `event_type` to an i18n key like `timeline.client.status_changed` with placeholders from payload. Reuse `_empty_state` when `events` is empty and `_pagination` for older pages.

## Pitfalls

- Do not treat timeline as audit. Audit rows are immutable; timeline rows may legitimately be deleted or translated — keep them in separate tables.
- Do not push schema-bound fields into `payload` (user_id, entity_id, etc.) — extract them to real columns when you start filtering on them.
- Recording from the controller tempts you to forget when the write path is indirect (cron, import). Always `record()` from the service that made the change.
- `entity_type` strings drift. Keep a small constants file or enum and reject unknown values in `record()`.
- Do not JOIN timeline into list views — it's read-per-detail-page, not per-row.

## Further reading
- [attachments.md](attachments.md) — record `*.attachment_added` events from the attachment service.
- [email-queue.md](email-queue.md) — record `*.email_sent` on successful dispatch.
- `AuditService` in `app/modules/Admin/Services/AuditService.php` — the tamper-evident counterpart.
