# Pattern — Admin Reports

> One-line purpose: hand-written admin read-only queries; one SQL query per item, rendered as HTML or exported as CSV.

## When to use this pattern
- Admin views that answer a specific question ("sign-ups by month", "overdue invoices").
- Read-only aggregations that don't fit the normal CRUD list screen.
- CSV export alongside the on-screen table.

Does NOT fit:
- Ad-hoc user-built queries — that's a BI tool, not this.
- Real-time dashboards with sub-second refresh — add Redis or a proper OLAP path.
- Very large result sets (> 100k rows) streamed to the browser — stream to disk and offer a download job instead.

## Schema

No new schema. Readers query existing tables.

## Service skeleton

One method per view. Each returns a tagged result:

```php
<?php
declare(strict_types=1);
namespace AppCore\Modules\Admin\Services;

use AppCore\Core\Database;

final class ReportService
{
    public function __construct(private readonly Database $db) {}

    /**
     * User signups grouped by calendar month.
     *
     * @return array{
     *   id: string,
     *   title: string,
     *   columns: list<array{key:string,label:string,type:string}>,
     *   rows: list<array<string,mixed>>,
     *   generated_at: string
     * }
     */
    public function userSignupsByMonth(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
                    COUNT(*)                          AS signups,
                    SUM(is_locked = 0)                AS active
               FROM users
              WHERE created_at >= ? AND created_at < ?
              GROUP BY month
              ORDER BY month",
            [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 00:00:00')]
        );

        return [
            'id'      => 'user_signups_by_month',
            'title'   => 'User signups by month',
            'columns' => [
                ['key' => 'month',   'label' => 'Month',   'type' => 'string'],
                ['key' => 'signups', 'label' => 'Signups', 'type' => 'int'],
                ['key' => 'active',  'label' => 'Active',  'type' => 'int'],
            ],
            'rows'         => $rows,
            'generated_at' => date('c'),
        ];
    }

    public function overdueInvoices(): array { /* similar shape */ return []; }
    public function roleAssignmentCounts(): array { /* similar shape */ return []; }

    /** Convert any result's rows + columns to a CSV string. */
    public function toCsv(array $data): string
    {
        $fh = fopen('php://temp', 'r+b');
        fputcsv($fh, array_column($data['columns'], 'label'));
        foreach ($data['rows'] as $r) {
            $line = [];
            foreach ($data['columns'] as $c) { $line[] = $r[$c['key']] ?? ''; }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $csv;
    }
}
```

## Controller integration

```php
public function show(Request $request, string $id): Response
{
    if ($c = $this->requirePermission('admin.reports')) return $c;

    $from = new \DateTimeImmutable((string) $request->getParam('from', '-12 months'));
    $to   = new \DateTimeImmutable((string) $request->getParam('to', 'now'));

    $data = match ($id) {
        'user_signups_by_month' => $this->reports->userSignupsByMonth($from, $to),
        'overdue_invoices'      => $this->reports->overdueInvoices(),
        'role_assignments'      => $this->reports->roleAssignmentCounts(),
        default                 => null,
    };
    if ($data === null) return $this->notFound();

    if ($request->getParam('format') === 'csv') {
        $csv = $this->reports->toCsv($data);
        return Response::raw($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $data['id'] . '.csv"',
        ]);
    }
    return $this->render('@admin/reports/show.html.twig', ['data' => $data]);
}
```

## Template hints

Use a single generic `@admin/reports/show.html.twig` that renders any result from `columns` + `rows`. Format numbers via a Twig filter switched on `column.type`. Put the "Download CSV" button alongside the filter form — same route, `?format=csv`.

## Pitfalls

- Building a "generic query-builder" framework. Resist. One method per view is much cheaper to debug than 400 lines of abstraction.
- Running heavy aggregations on each page load. Cache the result in `settings` or a small cache table keyed on id + filter hash when the query runs > 1 second.
- Returning raw `rows` with column names driven by `SELECT *` — the template can't know what's there. Always define `columns` explicitly in the service.
- Letting users pass arbitrary SQL via filter params. Every filter value is a bound parameter; whitelist group-by/order-by columns against a known list.
- Timezones on "today". `NOW()` runs in the DB server TZ, not the user's. Pass `$from`/`$to` in PHP and bind them — the example above does this.

## Further reading
- ADR-0005 (no ORM) — hand-written SQL is the rule, not the exception.
- For per-user access scoping (views that only show a user's subtree), combine with [hierarchy-closure-table.md](hierarchy-closure-table.md) and filter by descendant node set.
