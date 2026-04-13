<?php

declare(strict_types=1);

namespace Tests\Modules\Members;

use PHPUnit\Framework\TestCase;
use App\Core\Database;
use App\Modules\Members\Services\AttachmentService;

/**
 * Tests for AttachmentService.
 *
 * Covers upload (valid/invalid type/size), listing, download, deletion,
 * size/count helpers, and file system operations.
 */
class AttachmentSvcTest extends TestCase
{
    private Database $db;
    private AttachmentService $service;
    private int $memberId;
    private string $uploadDir;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        // Temp upload directory
        $this->uploadDir = sys_get_temp_dir() . '/sk10_test_uploads_' . uniqid();
        mkdir($this->uploadDir, 0755, true);

        // Drop tables
        $this->db->query("DROP TABLE IF EXISTS `member_attachments`");
        $this->db->query("DROP TABLE IF EXISTS `member_timeline`");
        $this->db->query("DROP TABLE IF EXISTS `medical_access_log`");
        $this->db->query("DROP TABLE IF EXISTS `member_pending_changes`");
        $this->db->query("DROP TABLE IF EXISTS `member_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `member_email_preferences`");
        $this->db->query("DROP TABLE IF EXISTS `member_achievements`");
        $this->db->query("DROP TABLE IF EXISTS `members`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignment_scopes`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignments`");
        $this->db->query("DROP TABLE IF EXISTS `roles`");
        $this->db->query("DROP TABLE IF EXISTS `password_resets`");
        $this->db->query("DROP TABLE IF EXISTS `user_sessions`");
        $this->db->query("DROP TABLE IF EXISTS `users`");

        // Create minimal tables
        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `mfa_secret` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->query("
            CREATE TABLE `members` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `membership_number` VARCHAR(20) NOT NULL,
                `first_name` VARCHAR(100) NOT NULL,
                `surname` VARCHAR(100) NOT NULL,
                `status` ENUM('active','pending','suspended','inactive','left') NOT NULL DEFAULT 'pending',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FULLTEXT KEY `ft_member_search` (`first_name`, `surname`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->query("
            CREATE TABLE `member_attachments` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `member_id` INT UNSIGNED NOT NULL,
                `field_key` VARCHAR(100) NOT NULL DEFAULT 'general',
                `file_path` VARCHAR(500) NOT NULL,
                `original_name` VARCHAR(255) NOT NULL,
                `mime_type` VARCHAR(100) NOT NULL,
                `file_size` INT UNSIGNED NOT NULL,
                `uploaded_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_member_attachments` (`member_id`, `field_key`),
                CONSTRAINT `fk_attachment_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_attachment_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Insert test user + member
        $this->db->query(
            "INSERT INTO `users` (`email`, `password_hash`) VALUES (?, ?)",
            ['test@example.com', password_hash('test', PASSWORD_BCRYPT)]
        );
        $this->db->query(
            "INSERT INTO `members` (`membership_number`, `first_name`, `surname`, `status`)
             VALUES (?, ?, ?, ?)",
            ['SK-000001', 'John', 'Doe', 'active']
        );
        $this->memberId = $this->db->lastInsertId();

        $this->service = new AttachmentService($this->db, $this->uploadDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query("DROP TABLE IF EXISTS `member_attachments`");
            $this->db->query("DROP TABLE IF EXISTS `member_email_preferences`");
            $this->db->query("DROP TABLE IF EXISTS `member_achievements`");
            $this->db->query("DROP TABLE IF EXISTS `members`");
            $this->db->query("DROP TABLE IF EXISTS `users`");
        }

        // Clean up temp upload directory
        if (isset($this->uploadDir) && is_dir($this->uploadDir)) {
            $this->deleteDirectory($this->uploadDir);
        }
    }

    // ── Upload Tests ──────────────────────────────────────────────────

    public function testUploadPdf(): void
    {
        $tmpFile = $this->createTempFile('test.pdf', '%PDF-1.4 test content');
        $file = $this->makeFileArray($tmpFile, 'report.pdf');

        $id = $this->service->upload($this->memberId, $file, 'general', 1);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $attachment = $this->service->getById($id);
        $this->assertNotNull($attachment);
        $this->assertEquals($this->memberId, $attachment['member_id']);
        $this->assertSame('report.pdf', $attachment['original_name']);
        $this->assertSame('application/pdf', $attachment['mime_type']);
        $this->assertSame('general', $attachment['field_key']);
        $this->assertEquals(1, $attachment['uploaded_by']);

        // File should exist on disk
        $absolutePath = $this->service->getAbsolutePath($attachment);
        $this->assertFileExists($absolutePath);
    }

    public function testUploadPng(): void
    {
        $tmpFile = $this->createPngFile();
        $file = $this->makeFileArray($tmpFile, 'photo.png');

        $id = $this->service->upload($this->memberId, $file);

        $attachment = $this->service->getById($id);
        $this->assertSame('image/png', $attachment['mime_type']);
        $this->assertStringEndsWith('.png', $attachment['file_path']);
    }

    public function testUploadRejectsInvalidType(): void
    {
        $tmpFile = $this->createTempFile('test.exe', 'MZ executable content');
        $file = $this->makeFileArray($tmpFile, 'malware.exe');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File type not allowed');
        $this->service->upload($this->memberId, $file);
    }

    public function testUploadRejectsOversizedFile(): void
    {
        // Create a file that reports as > MAX_FILE_SIZE
        $tmpFile = $this->createTempFile('big.pdf', '%PDF-1.4 content');
        $file = $this->makeFileArray($tmpFile, 'big.pdf');
        $file['size'] = AttachmentService::MAX_FILE_SIZE + 1;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum size');
        $this->service->upload($this->memberId, $file);
    }

    public function testUploadRejectsEmptyFile(): void
    {
        $tmpFile = $this->createTempFile('empty.pdf', '');
        $file = $this->makeFileArray($tmpFile, 'empty.pdf');
        $file['size'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');
        $this->service->upload($this->memberId, $file);
    }

    public function testUploadRejectsUploadError(): void
    {
        $file = [
            'name' => 'test.pdf',
            'tmp_name' => '/nonexistent',
            'size' => 100,
            'type' => 'application/pdf',
            'error' => UPLOAD_ERR_INI_SIZE,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('error code');
        $this->service->upload($this->memberId, $file);
    }

    public function testUploadWithFieldKey(): void
    {
        $tmpFile = $this->createTempFile('doc.pdf', '%PDF-1.4 content');
        $file = $this->makeFileArray($tmpFile, 'certificate.pdf');

        $id = $this->service->upload($this->memberId, $file, 'certificate');

        $attachment = $this->service->getById($id);
        $this->assertSame('certificate', $attachment['field_key']);
    }

    // ── Listing Tests ─────────────────────────────────────────────────

    public function testGetForMember(): void
    {
        $this->uploadTestFile('file1.pdf');
        $this->uploadTestFile('file2.pdf');

        $attachments = $this->service->getForMember($this->memberId);
        $this->assertCount(2, $attachments);
    }

    public function testGetForMemberFiltersByFieldKey(): void
    {
        $this->uploadTestFile('general.pdf', 'general');
        $this->uploadTestFile('cert.pdf', 'certificate');

        $general = $this->service->getForMember($this->memberId, 'general');
        $this->assertCount(1, $general);

        $certs = $this->service->getForMember($this->memberId, 'certificate');
        $this->assertCount(1, $certs);
    }

    public function testGetForMemberIncludesUploaderEmail(): void
    {
        $this->uploadTestFile('test.pdf', 'general', 1);

        $attachments = $this->service->getForMember($this->memberId);
        $this->assertSame('test@example.com', $attachments[0]['uploader_email']);
    }

    // ── Download Tests ────────────────────────────────────────────────

    public function testDownloadReturnsFileInfo(): void
    {
        $id = $this->uploadTestFile('report.pdf');
        $attachment = $this->service->getById($id);

        $result = $this->service->download($attachment);

        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('mime_type', $result);
        $this->assertArrayHasKey('original_name', $result);
        $this->assertFileExists($result['path']);
        $this->assertSame('report.pdf', $result['original_name']);
    }

    public function testDownloadThrowsWhenFileMissing(): void
    {
        $id = $this->uploadTestFile('willdelete.pdf');
        $attachment = $this->service->getById($id);

        // Delete the file from disk
        $path = $this->service->getAbsolutePath($attachment);
        unlink($path);

        $this->expectException(\RuntimeException::class);
        $this->service->download($attachment);
    }

    // ── Delete Tests ──────────────────────────────────────────────────

    public function testDelete(): void
    {
        $id = $this->uploadTestFile('deleteme.pdf');
        $attachment = $this->service->getById($id);
        $path = $this->service->getAbsolutePath($attachment);

        $this->assertFileExists($path);

        $result = $this->service->delete($id);
        $this->assertTrue($result);

        // DB record gone
        $this->assertNull($this->service->getById($id));

        // File gone
        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteNonexistentReturnsFalse(): void
    {
        $result = $this->service->delete(99999);
        $this->assertFalse($result);
    }

    // ── Size/Count Tests ──────────────────────────────────────────────

    public function testGetTotalSize(): void
    {
        $this->uploadTestFile('a.pdf');
        $this->uploadTestFile('b.pdf');

        $totalSize = $this->service->getTotalSize($this->memberId);
        $this->assertGreaterThan(0, $totalSize);
    }

    public function testGetCount(): void
    {
        $this->uploadTestFile('a.pdf');
        $this->uploadTestFile('b.pdf');
        $this->uploadTestFile('c.pdf');

        $count = $this->service->getCount($this->memberId);
        $this->assertSame(3, $count);
    }

    // ── Cascade Delete Tests ──────────────────────────────────────────

    public function testCascadeDeleteOnMember(): void
    {
        $this->uploadTestFile('cascade.pdf');

        $this->db->query("DELETE FROM `members` WHERE `id` = ?", [$this->memberId]);

        $attachments = $this->service->getForMember($this->memberId);
        $this->assertEmpty($attachments);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function uploadTestFile(string $name, string $fieldKey = 'general', ?int $uploadedBy = null): int
    {
        $tmpFile = $this->createTempFile($name, '%PDF-1.4 test content for ' . $name);
        $file = $this->makeFileArray($tmpFile, $name);
        return $this->service->upload($this->memberId, $file, $fieldKey, $uploadedBy);
    }

    private function createTempFile(string $name, string $content): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('sk10_') . '_' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    private function createPngFile(): string
    {
        // Minimal valid PNG (1x1 pixel, transparent)
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg=='
        );
        $path = sys_get_temp_dir() . '/' . uniqid('sk10_') . '.png';
        file_put_contents($path, $png);
        return $path;
    }

    private function makeFileArray(string $tmpPath, string $originalName): array
    {
        return [
            'name' => $originalName,
            'tmp_name' => $tmpPath,
            'size' => filesize($tmpPath),
            'type' => mime_content_type($tmpPath) ?: 'application/octet-stream',
            'error' => UPLOAD_ERR_OK,
        ];
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
