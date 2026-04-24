# Pattern — Bulk Import (CSV)

> One-line purpose: admins upload a CSV, see a per-row preview with errors, then commit the whole batch in one transaction.

## When to use this pattern
- Seeding data from a legacy system, a spreadsheet, or another vendor's export.
- Admin tools where a human reviews mapping + errors before committing.
- Imports small enough (< ~50k rows) to hold in memory and commit in a single transaction.

Does NOT fit:
- Streaming / continuous ingest — build a dedicated intake pipeline instead.
- Very large files where partial progress matters — this pattern all-or-nothings the batch.

## Schema

No persistent table is required — staged rows live in the session for the preview step. Optionally persist a summary:

```sql
-- Migration: 0026_import_runs.sql
CREATE TABLE import_runs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(40) NOT NULL,
    file_name   VARCHAR(255) NOT NULL,
    row_count   INT UNSIGNED NOT NULL,
    inserted    INT UNSIGNED NOT NULL,
    skipped     INT UNSIGNED NOT NULL,
    performed_by INT UNSIGNED NULL,
    performed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_entity (entity_type, performed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- Summary-only row per run — the raw CSV does not need to be kept.

## Service skeleton

```php
<?php
declare(strict_types=1);
namespace AppCore\Modules\Import\Services;

use AppCore\Core\Database;

final class CsvImportService
{
    /** @var array<string,string>  source column → entity field */
    private array $mapping;

    public function __construct(
        private readonly Database $db,
        private readonly ClientService $clients,
    ) {}

    /** @return array{headers:list<string>,rows:list<array<string,string>>} */
    public function parse(string $path, string $delimiter = ','): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) { throw new \RuntimeException('cannot_open_csv'); }
        $headers = fgetcsv($fh, 0, $delimiter) ?: [];
        $headers = array_map(static fn($h) => strtolower(trim((string)$h)), $headers);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delimiter)) !== false) {
            $rows[] = array_combine($headers, array_pad($r, count($headers), ''));
        }
        fclose($fh);
        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Validate each row against target-entity rules. Returns a preview with per-row errors.
     *
     * @param list<array<string,string>> $rows
     * @param array<string,string>       $mapping  source column → entity field
     * @return list<array{row:int,data:array<string,mixed>,errors:array<string,string>}>
     */
    public function preview(array $rows, array $mapping): array
    {
        $out = [];
        foreach ($rows as $i => $raw) {
            $mapped = [];
            foreach ($mapping as $src => $target) {
                $mapped[$target] = trim($raw[$src] ?? '');
            }
            $errors = $this->clients->validateForImport($mapped);
            $out[] = ['row' => $i + 2, 'data' => $mapped, 'errors' => $errors];
        }
        return $out;
    }

    /**
     * Commit a validated preview in a single transaction. Optional rollback-on-any-error.
     *
     * @param list<array{row:int,data:array<string,mixed>,errors:array<string,string>}> $preview
     * @return array{inserted:int,skipped:int}
     */
    public function commit(array $preview, bool $rollbackOnAnyError = true, ?int $performedBy = null, string $fileName = ''): array
    {
        $hasErrors = false;
        foreach ($preview as $p) { if ($p['errors'] !== []) { $hasErrors = true; break; } }
        if ($hasErrors && $rollbackOnAnyError) {
            return ['inserted' => 0, 'skipped' => count($preview)];
        }

        $inserted = 0; $skipped = 0;
        $this->db->beginTransaction();
        try {
            foreach ($preview as $p) {
                if ($p['errors'] !== []) { $skipped++; continue; }
                $this->clients->createFromImport($p['data']);
                $inserted++;
            }
            $this->db->insert('import_runs', [
                'entity_type'  => 'client',
                'file_name'    => $fileName,
                'row_count'    => count($preview),
                'inserted'     => $inserted,
                'skipped'      => $skipped,
                'performed_by' => $performedBy,
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return ['inserted' => $inserted, 'skipped' => $skipped];
    }
}
```

## Controller integration

Two-phase flow:

```php
public function upload(Request $request): Response
{
    if ($c = $this->requirePermission('clients.write')) return $c;

    $file = $_FILES['csv'] ?? null;
    if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
        $this->flash('error', t('import.no_file')); return $this->redirect(route('import.show'));
    }

    $parsed = $this->import->parse($file['tmp_name']);
    // User-provided mapping could come from the form; here we auto-match identical names:
    $mapping = array_combine($parsed['headers'], $parsed['headers']);
    $preview = $this->import->preview($parsed['rows'], $mapping);

    $_SESSION['import_preview'] = $preview;
    $_SESSION['import_filename'] = $file['name'];
    return $this->render('@import/preview.html.twig', ['preview' => $preview, 'filename' => $file['name']]);
}

public function commit(Request $request): Response
{
    if ($c = $this->requirePermission('clients.write')) return $c;

    $preview = $_SESSION['import_preview'] ?? null;
    if ($preview === null) { return $this->redirect(route('import.show')); }

    $result = $this->import->commit(
        $preview,
        rollbackOnAnyError: true,
        performedBy: $this->userId(),
        fileName: (string) ($_SESSION['import_filename'] ?? '')
    );
    unset($_SESSION['import_preview'], $_SESSION['import_filename']);

    $this->flash('success', t('import.done', ['n' => $result['inserted']]));
    return $this->redirect(route('clients.index'));
}
```

## Template hints

The preview template is a table that highlights rows with `errors` in red and disables the "Commit" button when any row is invalid (or when `rollbackOnAnyError=true` is enforced). Show the column mapping as a row of selects at the top — each source header maps to an entity field drop-down. Include a "Download template" link so users get the exact column set.

## Pitfalls

- Storing the whole preview in `$_SESSION` for large files blows session size. For > 5k rows, stash parsed rows in `/var/cache/imports/{uuid}.json` and keep only the filename in session.
- Not using a transaction on commit — partial imports leave the DB half-populated and are painful to reconcile.
- Trusting the CSV's MIME — users upload `.xls` renamed to `.csv`. Sniff the first bytes or parse and fail gracefully.
- UTF-8 BOM on the first header name (Excel default). Strip `\xEF\xBB\xBF` from the first header before lowercasing.
- Hard-coding the column mapping — give the admin a mapping UI even if auto-match works most of the time.
- Re-running preview between upload and commit is fine; re-running commit against a stale preview is not — clear session after commit.

## Further reading
- [custom-fields.md](custom-fields.md) — mapping UI can also target custom-field keys.
- [timeline.md](timeline.md) — record a `*.imported` event per inserted entity if the history matters.
