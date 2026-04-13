<?php

declare(strict_types=1);

namespace Tests\Modules\Members;

use PHPUnit\Framework\TestCase;
use App\Core\Database;
use App\Modules\Members\Services\TimelineService;

/**
 * Tests for TimelineService.
 *
 * Covers add, get (filtered/sorted), getLatest, delete, field keys,
 * grouped entries, and validation.
 */
class TimelineSvcTest extends TestCase
{
    private Database $db;
    private TimelineService $service;
    private int $memberId;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        // Drop in dependency order
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("DROP TABLE IF EXISTS `member_timeline`");
        $this->db->query("DROP TABLE IF EXISTS `medical_access_log`");
        $this->db->query("DROP TABLE IF EXISTS `member_pending_changes`");
        $this->db->query("DROP TABLE IF EXISTS `member_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `members`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignment_scopes`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignments`");
        $this->db->query("DROP TABLE IF EXISTS `roles`");
        $this->db->query("DROP TABLE IF EXISTS `password_resets`");
        $this->db->query("DROP TABLE IF EXISTS `user_sessions`");
        $this->db->query("DROP TABLE IF EXISTS `users`");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        // Create minimal users table
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

        // Create minimal members table
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

        // Create timeline table
        $this->db->query("
            CREATE TABLE `member_timeline` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `member_id` INT UNSIGNED NOT NULL,
                `field_key` VARCHAR(100) NOT NULL,
                `value` VARCHAR(500) NOT NULL,
                `effective_date` DATE NOT NULL,
                `recorded_by` INT UNSIGNED NULL,
                `notes` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_member_field` (`member_id`, `field_key`, `effective_date` DESC),
                INDEX `idx_field_key` (`field_key`),
                CONSTRAINT `fk_timeline_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_timeline_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Insert a test user
        $this->db->query(
            "INSERT INTO `users` (`email`, `password_hash`) VALUES (?, ?)",
            ['test@example.com', password_hash('test', PASSWORD_BCRYPT)]
        );

        // Insert a test member
        $this->db->query(
            "INSERT INTO `members` (`membership_number`, `first_name`, `surname`, `status`)
             VALUES (?, ?, ?, ?)",
            ['SK-000001', 'John', 'Doe', 'active']
        );
        $this->memberId = $this->db->lastInsertId();

        $this->service = new TimelineService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
            $this->db->query("DROP TABLE IF EXISTS `member_timeline`");
            $this->db->query("DROP TABLE IF EXISTS `members`");
            $this->db->query("DROP TABLE IF EXISTS `users`");
            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    // ── Add Entry Tests ───────────────────────────────────────────────

    public function testAddEntryReturnsId(): void
    {
        $id = $this->service->addEntry(
            $this->memberId,
            'rank',
            'Scout',
            '2026-01-15'
        );

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testAddEntryWithAllFields(): void
    {
        $id = $this->service->addEntry(
            $this->memberId,
            'qualification',
            'First Aid',
            '2026-03-01',
            1,
            'Completed level 2 course'
        );

        $entry = $this->service->getById($id);
        $this->assertNotNull($entry);
        $this->assertEquals($this->memberId, $entry['member_id']);
        $this->assertSame('qualification', $entry['field_key']);
        $this->assertSame('First Aid', $entry['value']);
        $this->assertSame('2026-03-01', $entry['effective_date']);
        $this->assertEquals(1, $entry['recorded_by']);
        $this->assertSame('Completed level 2 course', $entry['notes']);
    }

    public function testAddEntryRequiresFieldKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->addEntry($this->memberId, '', 'value', '2026-01-01');
    }

    public function testAddEntryRequiresValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->addEntry($this->memberId, 'rank', '', '2026-01-01');
    }

    public function testAddEntryRequiresValidDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->addEntry($this->memberId, 'rank', 'Scout', '01-15-2026');
    }

    // ── Get Entries Tests ─────────────────────────────────────────────

    public function testGetEntriesSortedByDateDesc(): void
    {
        $this->service->addEntry($this->memberId, 'rank', 'Beaver', '2024-01-01');
        $this->service->addEntry($this->memberId, 'rank', 'Cub', '2025-01-01');
        $this->service->addEntry($this->memberId, 'rank', 'Scout', '2026-01-01');

        $entries = $this->service->getEntries($this->memberId, 'rank');
        $this->assertCount(3, $entries);
        $this->assertSame('Scout', $entries[0]['value']);
        $this->assertSame('Cub', $entries[1]['value']);
        $this->assertSame('Beaver', $entries[2]['value']);
    }

    public function testGetEntriesFiltersByFieldKey(): void
    {
        $this->service->addEntry($this->memberId, 'rank', 'Scout', '2026-01-01');
        $this->service->addEntry($this->memberId, 'qualification', 'First Aid', '2026-02-01');
        $this->service->addEntry($this->memberId, 'rank', 'Venture', '2026-06-01');

        $ranks = $this->service->getEntries($this->memberId, 'rank');
        $this->assertCount(2, $ranks);

        $quals = $this->service->getEntries($this->memberId, 'qualification');
        $this->assertCount(1, $quals);
    }

    public function testGetEntriesReturnsAllWhenNoFilter(): void
    {
        $this->service->addEntry($this->memberId, 'rank', 'Scout', '2026-01-01');
        $this->service->addEntry($this->memberId, 'qualification', 'First Aid', '2026-02-01');

        $all = $this->service->getEntries($this->memberId);
        $this->assertCount(2, $all);
    }

    public function testGetEntriesIncludesRecorderEmail(): void
    {
        $this->service->addEntry($this->memberId, 'rank', 'Scout', '2026-01-01', 1);

        $entries = $this->service->getEntries($this->memberId, 'rank');
        $this->assertSame('test@example.com', $entries[0]['recorder_email']);
    }

    // ── Get Latest Entry Tests ────────────────────────────────────────

    public function testGetLatestEntry(): void
    {
        $this->service->addEntry($this->memberId, 'rank', 'Beaver', '2024-01-01');
        $this->service->addEntry($this->memberId, 'rank', 'Scout', '2026-01-01');
        $this->service->addEntry($this->memberId, 'rank', 'Cub', '2025-01-01');

        $latest = $this->service->getLatestEntry($this->memberId, 'rank');
        $this->assertNotNull($latest);
        $this->assertSame('Scout', $latest['value']);
    }

    public function testGetLatestEntryReturnsNullWhenNone(): void
    {
        $result = $this->service->getLatestEntry($this->memberId, 'nonexistent');
        $this->assertNull($result);
    }

    // ── Delete Entry Tests ────────────────────────────────────────────

    public function testDeleteEntry(): void
    {
        $id = $this->service->addEntry($this->memberId, 'rank', 'Scout', '2026-01-01');

        $result = $this->service->deleteEntry($id);
        $this->assertTrue($result);

        $entry = $this->service->getById($id);
        $this->assertNull($entry);
    }

    public function testDeleteNonexistentReturnsFalse(): void
    {
        $result = $this->service->deleteEntry(99999);
        $this->assertFalse($result);
    }

    // ── Field Keys Tests ──────────────────────────────────────────────

    public function testGetFieldKeys(): void
    {
        $this->service->addEntry($this->memberId, 'rank', 'Scout', '2026-01-01');
        $this->service->addEntry($this->memberId, 'qualification', 'First Aid', '2026-02-01');
        $this->service->addEntry($this->memberId, 'rank', 'Venture', '2026-06-01');

        $keys = $this->service->getFieldKeys($this->memberId);
        $this->assertCount(2, $keys);
        $this->assertContains('rank', $keys);
        $this->assertContains('qualification', $keys);
    }

    // ── Grouped Entries Tests ─────────────────────────────────────────

    public function testGetEntriesGrouped(): void
    {
        $this->service->addEntry($this->memberId, 'rank', 'Beaver', '2024-01-01');
        $this->service->addEntry($this->memberId, 'rank', 'Scout', '2026-01-01');
        $this->service->addEntry($this->memberId, 'qualification', 'First Aid', '2026-02-01');

        $grouped = $this->service->getEntriesGrouped($this->memberId);
        $this->assertArrayHasKey('rank', $grouped);
        $this->assertArrayHasKey('qualification', $grouped);
        $this->assertCount(2, $grouped['rank']);
        $this->assertCount(1, $grouped['qualification']);
    }

    // ── Cascade Delete Tests ──────────────────────────────────────────

    public function testCascadeDeleteOnMember(): void
    {
        $this->service->addEntry($this->memberId, 'rank', 'Scout', '2026-01-01');
        $this->service->addEntry($this->memberId, 'rank', 'Venture', '2026-06-01');

        // Delete the member — should cascade
        $this->db->query("DELETE FROM `members` WHERE `id` = ?", [$this->memberId]);

        $entries = $this->service->getEntries($this->memberId);
        $this->assertEmpty($entries);
    }
}
