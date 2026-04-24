# Pattern — Calendar + iCal Feed

> One-line purpose: a simple events table with a per-user token-authenticated `.ics` feed for external calendar subscription.

## When to use this pattern
- Projects that schedule events users want in their phone/Outlook/Google calendar.
- Admin-managed calendars with a few hundred events per year per user.
- Optional attendance tracking.

Does NOT fit:
- Booking/availability systems with conflict detection — use a dedicated scheduling library.
- Recurring-rule complexity (RRULE with exceptions, overrides) — add a real iCal library if you need full RFC-5545 recurrence.

## Schema

```sql
-- Migration: 0025_events.sql
CREATE TABLE events (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title       VARCHAR(200) NOT NULL,
    description TEXT NULL,
    start_at    DATETIME NOT NULL,
    end_at      DATETIME NOT NULL,
    location    VARCHAR(200) NULL,
    created_by  INT UNSIGNED NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_start (start_at),
    KEY idx_range (start_at, end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE event_attendance (
    event_id INT UNSIGNED NOT NULL,
    user_id  INT UNSIGNED NOT NULL,
    status   ENUM('yes','no','maybe') NOT NULL DEFAULT 'yes',
    PRIMARY KEY (event_id, user_id),
    CONSTRAINT fk_ea_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user subscription token
ALTER TABLE users ADD COLUMN ical_token CHAR(32) NULL UNIQUE AFTER email;
```

- `ical_token` is a 32-char random hex string; revoke by setting it NULL and reissuing.
- `idx_range` supports "events between X and Y" window queries.

## Service skeleton

```php
<?php
declare(strict_types=1);
namespace AppCore\Modules\Calendar\Services;

use AppCore\Core\Database;

final class EventService
{
    public function __construct(private readonly Database $db) {}

    public function create(string $title, \DateTimeImmutable $start, \DateTimeImmutable $end, ?string $location, ?string $description, int $createdBy): int
    {
        return $this->db->insert('events', [
            'title'       => $title,
            'description' => $description,
            'start_at'    => $start->format('Y-m-d H:i:s'),
            'end_at'      => $end->format('Y-m-d H:i:s'),
            'location'    => $location,
            'created_by'  => $createdBy,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->db->fetchAll(
            'SELECT id, title, description, start_at, end_at, location
               FROM events
              WHERE start_at < ? AND end_at > ?
              ORDER BY start_at',
            [$to->format('Y-m-d H:i:s'), $from->format('Y-m-d H:i:s')]
        );
    }

    public function forUserSubscription(int $userId): array
    {
        // Extend: filter by permission, attendance, etc.
        $from = (new \DateTimeImmutable('-30 days'));
        $to   = (new \DateTimeImmutable('+365 days'));
        return $this->between($from, $to);
    }

    public function ensureToken(int $userId): string
    {
        $u = $this->db->fetchOne('SELECT ical_token FROM users WHERE id = ?', [$userId]);
        if (!empty($u['ical_token'])) return $u['ical_token'];
        $token = bin2hex(random_bytes(16));
        $this->db->update('users', ['ical_token' => $token], ['id' => $userId]);
        return $token;
    }

    public function findByToken(string $token): ?array
    {
        return $this->db->fetchOne('SELECT id, name, email FROM users WHERE ical_token = ?', [$token]);
    }
}
```

```php
<?php
declare(strict_types=1);
namespace AppCore\Modules\Calendar\Services;

final class IcalFormatter
{
    /** @param list<array<string,mixed>> $events */
    public function render(array $events, string $calendarName, string $prodId): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . $this->esc($prodId),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->esc($calendarName),
        ];
        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $e['id'] . '@appcore';
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', strtotime($e['start_at']));
            $lines[] = 'DTEND:'   . gmdate('Ymd\THis\Z', strtotime($e['end_at']));
            $lines[] = 'SUMMARY:' . $this->esc($e['title']);
            if (!empty($e['location']))    $lines[] = 'LOCATION:'    . $this->esc($e['location']);
            if (!empty($e['description'])) $lines[] = 'DESCRIPTION:' . $this->esc($e['description']);
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map($this->fold(...), $lines)) . "\r\n";
    }

    private function esc(string $v): string
    {
        return str_replace(["\\", "\n", ",", ";"], ["\\\\", "\\n", "\\,", "\\;"], $v);
    }

    /** RFC 5545 §3.1 line folding at 75 octets. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) return $line;
        $out = ''; $i = 0;
        while ($i < strlen($line)) {
            $chunk = substr($line, $i, 75);
            $out  .= ($i === 0 ? '' : "\r\n ") . $chunk;
            $i    += 75;
        }
        return $out;
    }
}
```

## Controller integration

```php
public function feed(Request $request): Response
{
    $token = (string) $request->getParam('token', '');
    if ($token === '') return $this->notFound();

    $user = $this->events->findByToken($token);
    if ($user === null) return $this->notFound();

    $body = $this->ical->render(
        $this->events->forUserSubscription((int) $user['id']),
        t('calendar.feed_name'),
        '-//AppCore//EN'
    );
    return Response::raw($body, 200, [
        'Content-Type'        => 'text/calendar; charset=utf-8',
        'Content-Disposition' => 'inline; filename="calendar.ics"',
        'Cache-Control'       => 'private, max-age=300',
    ]);
}
```

Route: `$router->get('/calendar.ics', [CalendarController::class, 'feed'], 'calendar.feed');` — no auth middleware; the token is the auth.

## Template hints

For the in-app view, render a month grid via Twig loops or a small Alpine component. Each event cell links to the detail page; HTMX loads the detail in a modal (`hx-target="#modal"`). Surface the subscription URL to the user with a copy button rather than clickable link — clicking opens the file download in most browsers.

## Pitfalls

- Timezone bugs. Always store `start_at`/`end_at` in UTC if your app spans timezones, and emit `DTSTART` with `Z`. Mixing local and UTC silently shifts events.
- Line folding — calendars are strict about the 75-octet limit. The formatter above handles it; don't skip it for "simple" lines.
- Forgetting `METHOD:PUBLISH` and `UID` — subscribers treat events as new every refresh and duplicate them.
- Treating the feed URL as secret enough to skip rate-limiting. Add a cheap cache (5-minute `max-age` as above) so a subscribed client refreshing every minute doesn't hammer the DB.
- Pulling a full iCal library just for VEVENT. A 40-line formatter is fine; grow only if RRULEs land on the roadmap.

## Further reading
- No ADR on iCal — the hand-rolled formatter is a conscious dependency-avoidance choice (see ADR-0001, ADR-0005 philosophy on lean stack).
