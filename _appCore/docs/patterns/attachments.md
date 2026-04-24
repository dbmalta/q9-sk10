# Pattern — Attachments

> One-line purpose: accept file uploads, link them to entities, store on disk outside the webroot, stream on download.

## When to use this pattern
- Entities that need associated files (client contracts, ticket screenshots, member photos).
- Multiple files per entity, mixed MIME types, browsable by admins.
- You want files on local disk — simple, cheap, backup-friendly.

Does NOT fit:
- Public CDN-served assets (logos, landing-page images). Put those in `/assets/` and commit them.
- Multi-GB files or media streaming — use S3/R2 and a signed URL pattern; this doc assumes local disk.

## Schema

```sql
-- Migration: 0023_attachments.sql
CREATE TABLE attachments (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type  VARCHAR(40) NOT NULL,
    entity_id    INT UNSIGNED NOT NULL,
    filename     VARCHAR(255) NOT NULL,
    mime         VARCHAR(120) NOT NULL,
    size_bytes   INT UNSIGNED NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    uploaded_by  INT UNSIGNED NULL,
    uploaded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_entity (entity_type, entity_id),
    KEY idx_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- `storage_path` is relative to `/data/attachments/` — e.g. `2026/04/abc123-contract.pdf`. Don't store absolute paths; they break on backup restore.
- `filename` is the original display name; `storage_path` is the safe on-disk name with a UUID prefix.

## Storage layout & .htaccess

Files go under `/data/attachments/{YYYY}/{MM}/{uuid}-{original}`. The repo's root `.htaccess` must deny direct web access:

```apache
# /data/.htaccess
Require all denied
```

All reads go through the controller, which enforces permissions and streams via `Response::file()`.

## Service skeleton

```php
<?php
declare(strict_types=1);
namespace AppCore\Modules\Attachments\Services;

use AppCore\Core\Database;
use RuntimeException;

final class AttachmentService
{
    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB
    private const ALLOWED_MIMES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
        'text/plain', 'text/csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly string $storageRoot, // /data/attachments
    ) {}

    /**
     * @param array{name:string,type:string,tmp_name:string,size:int,error:int} $file
     */
    public function store(string $entityType, int $entityId, array $file, int $userId): int
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('upload_failed');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('file_too_large');
        }

        // Re-detect MIME; don't trust $file['type'] from the client.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException('mime_not_allowed');
        }

        $safeName = $this->safeFilename($file['name']);
        $uuid     = bin2hex(random_bytes(8));
        $relDir   = date('Y') . '/' . date('m');
        $absDir   = $this->storageRoot . '/' . $relDir;
        if (!is_dir($absDir) && !mkdir($absDir, 0750, true) && !is_dir($absDir)) {
            throw new RuntimeException('storage_unwritable');
        }
        $relPath = $relDir . '/' . $uuid . '-' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $this->storageRoot . '/' . $relPath)) {
            throw new RuntimeException('store_failed');
        }

        return $this->db->insert('attachments', [
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'filename'     => $safeName,
            'mime'         => $mime,
            'size_bytes'   => $file['size'],
            'storage_path' => $relPath,
            'uploaded_by'  => $userId,
        ]);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, entity_type, entity_id, filename, mime, size_bytes, storage_path, uploaded_by, uploaded_at
               FROM attachments WHERE id = ?',
            [$id]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forEntity(string $entityType, int $entityId): array
    {
        return $this->db->fetchAll(
            'SELECT id, filename, mime, size_bytes, uploaded_by, uploaded_at
               FROM attachments WHERE entity_type = ? AND entity_id = ?
               ORDER BY uploaded_at DESC',
            [$entityType, $entityId]
        );
    }

    public function absolutePath(array $row): string
    {
        return $this->storageRoot . '/' . $row['storage_path'];
    }

    public function delete(int $id): void
    {
        $row = $this->find($id);
        if ($row === null) return;
        $this->db->query('DELETE FROM attachments WHERE id = ?', [$id]);
        $abs = $this->absolutePath($row);
        if (is_file($abs)) @unlink($abs);
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'file';
        return substr($name, 0, 120);
    }
}
```

## Controller integration

```php
public function download(Request $request, int $id): Response
{
    if ($c = $this->requirePermission('clients.read')) return $c;

    $row = $this->attachments->find($id);
    if ($row === null) return $this->notFound();

    // Scope check: is the caller allowed to see this entity?
    if (!$this->clients->canRead($row['entity_id'], $this->userId())) {
        return $this->forbidden();
    }
    $abs = $this->attachments->absolutePath($row);
    if (!is_file($abs)) return $this->notFound();

    return Response::file($abs, $row['filename'], $row['mime']);
}

public function upload(Request $request, int $clientId): Response
{
    if ($c = $this->requirePermission('clients.write')) return $c;

    $file = $_FILES['file'] ?? null;
    if ($file === null) { $this->flash('error', t('attachments.no_file')); return $this->redirect(route('clients.show', ['id' => $clientId])); }

    $id = $this->attachments->store('client', $clientId, $file, $this->userId());
    $this->timeline->record('client', $clientId, 'client.attachment_added', $this->userId(), ['attachment_id' => $id]);
    $this->audit->log('attachment', $id, 'create', [], ['entity' => "client:$clientId"], $this->userId());

    return $this->redirect(route('clients.show', ['id' => $clientId]));
}
```

## Template hints

Upload form needs `enctype="multipart/form-data"`. List via a partial that reuses `_empty_state` and renders size via a Twig filter. For image previews, render `<img src="{{ route('attachments.download', {id: a.id}) }}">` — the controller still enforces permissions on every fetch.

## Pitfalls

- Trusting `$_FILES['file']['type']` — the browser sets it. Always re-detect with `finfo`.
- Storing under the webroot — a poorly configured host will serve the files directly. Always `/data/` + `Require all denied`.
- Forgetting the `entity_type` scope check on download. `attachments.id` is enumerable; every download must re-verify the caller can see the linked entity.
- Using the original filename on disk — path traversal, collisions, case-sensitivity bugs. Always a UUID-prefixed sanitised name.
- Not handling `UPLOAD_ERR_*` cases — you'll look at empty rows and blame the DB. Explicit check first.

## Further reading
- [timeline.md](timeline.md) — record `*.attachment_added` / `*.attachment_removed` events.
- ADR-0005 (PDO, no ORM) — rows are arrays; the service returns them raw.
