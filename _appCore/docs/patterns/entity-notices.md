# Pattern — Entity Notices

> One-line purpose: contextual banners attached to a specific entity (not broadcast), displayed on that entity's detail pages until resolved or dismissed.

## When to use this pattern
- "This client has outstanding invoices" banner on the client profile.
- "This member is missing medical consent" on the member profile.
- Any per-entity warning/error/info that guides the operator looking at that record.

Does NOT fit:
- Global broadcasts to all users — use the shipped `Admin\Notices` module (`notices` + `notice_acknowledgements`).
- Transient toast messages — use the existing flash-message mechanism.
- Per-user reminders unrelated to any entity — use a simple `user_reminders` table; this pattern is entity-scoped.

## Schema

```sql
-- Migration: 0027_entity_notices.sql
CREATE TABLE entity_notices (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type  VARCHAR(40) NOT NULL,
    entity_id    INT UNSIGNED NOT NULL,
    severity     ENUM('info','warning','error') NOT NULL DEFAULT 'info',
    message      VARCHAR(500) NOT NULL,
    dismissible  TINYINT(1) NOT NULL DEFAULT 1,
    dismissed_by JSON NULL,
    created_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- `dismissed_by` is a JSON array of user IDs who dismissed it — avoids a separate junction table for what's usually a small set. If a notice is routinely dismissed by thousands of users, promote to a real table.
- `dismissible = 0` for notices operators must never lose sight of (outstanding fees, compliance blocks).
- `message` stores a rendered string. If the message has placeholders that should be translated, store an i18n key instead and interpolate at render time.

## Service skeleton

```php
<?php
declare(strict_types=1);
namespace AppCore\Modules\EntityNotices\Services;

use AppCore\Core\Database;

final class EntityNoticeService
{
    public function __construct(private readonly Database $db) {}

    public function create(
        string $entityType,
        int $entityId,
        string $severity,
        string $message,
        bool $dismissible = true,
        ?int $createdBy = null
    ): int {
        if (!in_array($severity, ['info','warning','error'], true)) {
            $severity = 'info';
        }
        return $this->db->insert('entity_notices', [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'severity'    => $severity,
            'message'     => $message,
            'dismissible' => $dismissible ? 1 : 0,
            'created_by'  => $createdBy,
        ]);
    }

    /**
     * Active (not-dismissed-by-this-user) notices for a given entity.
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $entityType, int $entityId, ?int $viewingUserId = null): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, severity, message, dismissible, dismissed_by, created_at
               FROM entity_notices
              WHERE entity_type = ? AND entity_id = ?
              ORDER BY FIELD(severity, "error", "warning", "info"), id DESC',
            [$entityType, $entityId]
        );
        if ($viewingUserId === null) return $rows;

        return array_values(array_filter($rows, static function (array $r) use ($viewingUserId): bool {
            if ((int) $r['dismissible'] === 0) return true;
            $dismissed = $r['dismissed_by'] ? (array) json_decode($r['dismissed_by'], true) : [];
            return !in_array($viewingUserId, $dismissed, true);
        }));
    }

    public function dismiss(int $id, int $userId): void
    {
        $row = $this->db->fetchOne('SELECT dismissible, dismissed_by FROM entity_notices WHERE id = ?', [$id]);
        if ($row === null || (int) $row['dismissible'] === 0) return;

        $set = $row['dismissed_by'] ? (array) json_decode($row['dismissed_by'], true) : [];
        if (!in_array($userId, $set, true)) {
            $set[] = $userId;
            $this->db->update('entity_notices', [
                'dismissed_by' => json_encode(array_values($set)),
            ], ['id' => $id]);
        }
    }

    public function remove(int $id): void
    {
        $this->db->query('DELETE FROM entity_notices WHERE id = ?', [$id]);
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

    $notices = $this->entityNotices->forEntity('client', $id, $this->userId());

    return $this->render('@clients/show.html.twig', [
        'client'  => $client,
        'notices' => $notices,
    ]);
}

public function dismiss(Request $request, int $noticeId): Response
{
    if ($c = $this->requireAuth()) return $c;
    $this->entityNotices->dismiss($noticeId, $this->userId());
    if ($request->isHtmx()) return $this->json(['ok' => true]);
    return $this->redirect($request->header('Referer') ?? '/');
}
```

Other services create notices as side effects of domain events — e.g. the billing service, on invoice overdue:

```php
$this->entityNotices->create('client', $clientId, 'warning',
    t('billing.overdue_notice', ['n' => $count]), dismissible: false);
```

## Template hints

Create `_entity_notices.html.twig` shared partial:

```twig
{% for n in notices %}
  <div class="alert alert-{{ {'info':'info','warning':'warning','error':'danger'}[n.severity] }} d-flex justify-content-between">
    <span>{{ n.message }}</span>
    {% if n.dismissible %}
      <button hx-post="{{ route('entity_notices.dismiss', {id: n.id}) }}"
              hx-swap="outerHTML" hx-target="closest .alert"
              class="btn-close"></button>
    {% endif %}
  </div>
{% endfor %}
```

Include it at the top of every entity detail template with `{% include '_entity_notices.html.twig' %}`.

## Pitfalls

- Treating this as a replacement for global broadcast. The shipped `notices` table handles global announcements + acknowledgement tracking — use it for that.
- Leaving stale notices. Notices should be removed when the underlying condition is fixed (invoice paid → remove the overdue notice). Don't let them accumulate.
- Using `message` for markup — autoescape is on for a reason. If you need bold/link, render an i18n key that has its own small template, not raw HTML.
- Putting more than a few dozen dismissals in `dismissed_by` JSON — the column grows unbounded. Promote to a junction table past ~100 rows per notice.
- Forgetting the `viewingUserId` filter and showing every user notices they already dismissed.

## Further reading
- `NoticeService` in `app/modules/Admin/Services/NoticeService.php` — the global/broadcast counterpart shipped in appCore.
- [timeline.md](timeline.md) — the same underlying event that creates a notice usually warrants a timeline entry too.
