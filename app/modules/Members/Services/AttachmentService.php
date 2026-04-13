<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Core\Database;

/**
 * Member attachment service.
 *
 * Handles file uploads, downloads, listing, and deletion for member
 * attachments. Files are stored on disk with UUID-based filenames.
 */
class AttachmentService
{
    private Database $db;
    private string $uploadBasePath;

    /** @var int Maximum file size in bytes (10 MB) */
    public const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /** @var array Allowed MIME types and their extensions */
    public const ALLOWED_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    /**
     * @param Database $db
     * @param string $uploadBasePath Base path for uploads (e.g. /var/www/data/uploads)
     */
    public function __construct(Database $db, string $uploadBasePath = '')
    {
        $this->db = $db;
        $this->uploadBasePath = $uploadBasePath ?: (defined('ROOT_PATH') ? ROOT_PATH . '/data/uploads' : '/data/uploads');
    }

    /**
     * Upload a file attachment for a member.
     *
     * @param int $memberId
     * @param array $file PHP $_FILES entry (name, tmp_name, size, type, error)
     * @param string $fieldKey Category key (default: 'general')
     * @param int|null $uploadedBy User ID
     * @return int Attachment record ID
     * @throws \InvalidArgumentException On validation failure
     * @throws \RuntimeException On disk write failure
     */
    public function upload(int $memberId, array $file, string $fieldKey = 'general', ?int $uploadedBy = null): int
    {
        // Validate upload
        if (!isset($file['tmp_name']) || !isset($file['error'])) {
            throw new \InvalidArgumentException("Invalid file upload.");
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException("File upload error code: {$file['error']}");
        }

        $originalName = $file['name'] ?? 'unnamed';
        $tmpName = $file['tmp_name'];
        $fileSize = $file['size'] ?? 0;

        // Size check
        if ($fileSize > self::MAX_FILE_SIZE) {
            $maxMb = self::MAX_FILE_SIZE / (1024 * 1024);
            throw new \InvalidArgumentException("File exceeds the maximum size of {$maxMb} MB.");
        }

        if ($fileSize === 0) {
            throw new \InvalidArgumentException("File is empty.");
        }

        // MIME type check — use finfo for reliable detection
        $mimeType = $this->detectMimeType($tmpName);
        if (!isset(self::ALLOWED_TYPES[$mimeType])) {
            $allowed = implode(', ', array_values(self::ALLOWED_TYPES));
            throw new \InvalidArgumentException("File type not allowed. Accepted: {$allowed}.");
        }

        $ext = self::ALLOWED_TYPES[$mimeType];

        // Generate storage path
        $uuid = $this->generateUuid();
        $relativeDir = "members/{$memberId}";
        $absoluteDir = $this->uploadBasePath . '/' . $relativeDir;
        $filename = "{$uuid}.{$ext}";
        $relativePath = "{$relativeDir}/{$filename}";
        $absolutePath = "{$absoluteDir}/{$filename}";

        // Ensure directory exists
        if (!is_dir($absoluteDir)) {
            if (!mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
                throw new \RuntimeException("Failed to create upload directory.");
            }
        }

        // Move uploaded file
        if (is_uploaded_file($tmpName)) {
            if (!move_uploaded_file($tmpName, $absolutePath)) {
                throw new \RuntimeException("Failed to save uploaded file.");
            }
        } else {
            // For testing: copy instead of move_uploaded_file
            if (!copy($tmpName, $absolutePath)) {
                throw new \RuntimeException("Failed to save file.");
            }
        }

        // Insert DB record
        $this->db->query(
            "INSERT INTO `member_attachments`
             (`member_id`, `field_key`, `file_path`, `original_name`, `mime_type`, `file_size`, `uploaded_by`)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$memberId, $fieldKey, $relativePath, $originalName, $mimeType, $fileSize, $uploadedBy]
        );

        return $this->db->lastInsertId();
    }

    /**
     * Get all attachments for a member, optionally filtered by field key.
     *
     * @param int $memberId
     * @param string|null $fieldKey Filter by field key (null = all)
     * @return array
     */
    public function getForMember(int $memberId, ?string $fieldKey = null): array
    {
        $sql = "SELECT a.*, u.email AS uploader_email
                FROM `member_attachments` a
                LEFT JOIN `users` u ON u.id = a.uploaded_by
                WHERE a.`member_id` = ?";
        $params = [$memberId];

        if ($fieldKey !== null) {
            $sql .= " AND a.`field_key` = ?";
            $params[] = $fieldKey;
        }

        $sql .= " ORDER BY a.`created_at` DESC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get a single attachment by ID.
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM `member_attachments` WHERE `id` = ?",
            [$id]
        );
        return $row ?: null;
    }

    /**
     * Get the absolute file path for an attachment.
     *
     * @param array $attachment The attachment record
     * @return string
     */
    public function getAbsolutePath(array $attachment): string
    {
        return $this->uploadBasePath . '/' . $attachment['file_path'];
    }

    /**
     * Send a file download response (headers + content).
     *
     * Should be called from a controller — sends headers and streams the file.
     *
     * @param array $attachment
     * @return array Headers and path for the controller to stream
     * @throws \RuntimeException If file not found on disk
     */
    public function download(array $attachment): array
    {
        $absolutePath = $this->getAbsolutePath($attachment);
        if (!file_exists($absolutePath)) {
            throw new \RuntimeException("File not found on disk.");
        }

        return [
            'path' => $absolutePath,
            'mime_type' => $attachment['mime_type'],
            'original_name' => $attachment['original_name'],
            'file_size' => $attachment['file_size'],
        ];
    }

    /**
     * Delete an attachment (DB record + file on disk).
     *
     * @param int $id Attachment ID
     * @return bool True if deleted
     */
    public function delete(int $id): bool
    {
        $attachment = $this->getById($id);
        if (!$attachment) {
            return false;
        }

        // Delete file from disk
        $absolutePath = $this->getAbsolutePath($attachment);
        if (file_exists($absolutePath)) {
            unlink($absolutePath);
        }

        // Delete DB record
        $stmt = $this->db->query(
            "DELETE FROM `member_attachments` WHERE `id` = ?",
            [$id]
        );

        // Clean up empty member directory
        $memberDir = dirname($absolutePath);
        if (is_dir($memberDir) && count(glob($memberDir . '/*')) === 0) {
            @rmdir($memberDir);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Get total attachment size for a member (in bytes).
     *
     * @param int $memberId
     * @return int
     */
    public function getTotalSize(int $memberId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(`file_size`), 0) AS `total` FROM `member_attachments` WHERE `member_id` = ?",
            [$memberId]
        );
        return (int) ($row['total'] ?? 0);
    }

    /**
     * Get attachment count for a member.
     *
     * @param int $memberId
     * @return int
     */
    public function getCount(int $memberId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS `cnt` FROM `member_attachments` WHERE `member_id` = ?",
            [$memberId]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    // ── Internal Helpers ─────────────────────────────────────────────

    /**
     * Detect MIME type of a file using finfo.
     */
    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);
        return $mime ?: 'application/octet-stream';
    }

    /**
     * Generate a UUID v4.
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
