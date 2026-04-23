<?php

declare(strict_types=1);

namespace Tests\Modules\Members;

use App\Core\Database;
use App\Modules\Members\Services\MemberDashboardService;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the /me dashboard aggregator.
 *
 * Verifies that the service pulls member details, node memberships,
 * upcoming events scoped to the member's nodes, deduplicated articles
 * across nodes + org-wide, and unacknowledged notices.
 */
class MemberDashboardServiceTest extends TestCase
{
    private Database $db;
    private int $memberId;
    private int $userId;
    private int $nodeId;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach ([
            'notice_acknowledgements', 'notices',
            'articles',
            'events',
            'member_nodes', 'members',
            'org_nodes',
            'users',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("CREATE TABLE `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `org_nodes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(200) NOT NULL,
            `parent_id` INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `members` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `membership_number` VARCHAR(20) NOT NULL,
            `first_name` VARCHAR(100) NOT NULL,
            `surname` VARCHAR(100) NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `user_id` INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `member_nodes` (
            `member_id` INT UNSIGNED NOT NULL,
            `node_id` INT UNSIGNED NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`member_id`, `node_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `events` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT NULL,
            `location` VARCHAR(200) NULL,
            `start_date` DATETIME NOT NULL,
            `end_date` DATETIME NULL,
            `all_day` TINYINT(1) DEFAULT 0,
            `is_published` TINYINT(1) DEFAULT 1,
            `node_scope_id` INT UNSIGNED NULL,
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `articles` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL,
            `slug` VARCHAR(200) NOT NULL,
            `body` TEXT NULL,
            `excerpt` TEXT NULL,
            `node_scope_id` INT UNSIGNED NULL,
            `is_published` TINYINT(1) DEFAULT 1,
            `published_at` DATETIME NULL,
            `author_id` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `notices` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(300) NOT NULL,
            `content` TEXT NOT NULL,
            `type` ENUM('must_acknowledge', 'informational') NOT NULL DEFAULT 'must_acknowledge',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `notice_acknowledgements` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `notice_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `acknowledged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->userId   = $this->db->insert('users',     ['email' => 'u@test']);
        $this->nodeId   = $this->db->insert('org_nodes', ['name' => 'Scouts A']);
        $this->memberId = $this->db->insert('members',   [
            'membership_number' => 'T-1', 'first_name' => 'A', 'surname' => 'B',
            'user_id' => $this->userId,
        ]);
        $this->db->insert('member_nodes', [
            'member_id' => $this->memberId, 'node_id' => $this->nodeId, 'is_primary' => 1,
        ]);
    }

    public function testLoadReturnsMemberNodesAndEmptyListsWhenNoContent(): void
    {
        $svc = new MemberDashboardService($this->db);
        $data = $svc->load($this->memberId, $this->userId);

        $this->assertSame('A', $data['member']['first_name']);
        $this->assertCount(1, $data['nodes']);
        $this->assertSame('Scouts A', $data['nodes'][0]['name']);
        $this->assertSame([], $data['upcoming_events']);
        $this->assertSame([], $data['recent_articles']);
        $this->assertSame([], $data['notices']);
    }

    public function testLoadOnlyReturnsFutureEvents(): void
    {
        $this->db->insert('events', [
            'title' => 'Past', 'start_date' => '2020-01-01 10:00:00',
            'end_date' => '2020-01-01 12:00:00', 'node_scope_id' => $this->nodeId,
            'is_published' => 1,
        ]);
        $this->db->insert('events', [
            'title' => 'Future', 'start_date' => '2099-01-01 10:00:00',
            'end_date' => '2099-01-01 12:00:00', 'node_scope_id' => $this->nodeId,
            'is_published' => 1,
        ]);

        $svc = new MemberDashboardService($this->db);
        $data = $svc->load($this->memberId, $this->userId);

        $titles = array_column($data['upcoming_events'], 'title');
        $this->assertContains('Future', $titles);
        $this->assertNotContains('Past', $titles);
    }

    public function testLoadDedupesArticlesAcrossNodeAndOrgScope(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        // Org-wide article
        $this->db->insert('articles', [
            'title' => 'Org News', 'slug' => 'org-news',
            'node_scope_id' => null, 'is_published' => 1, 'published_at' => $now,
        ]);
        // Node-scoped article
        $this->db->insert('articles', [
            'title' => 'Troop News', 'slug' => 'troop-news',
            'node_scope_id' => $this->nodeId, 'is_published' => 1, 'published_at' => $now,
        ]);

        $svc = new MemberDashboardService($this->db);
        $data = $svc->load($this->memberId, $this->userId);

        // Org-wide article must not appear twice even though it's returned by
        // both the per-node query (which includes NULL-scoped) and the org
        // query (NULL-scoped only).
        $titles = array_column($data['recent_articles'], 'title');
        $this->assertSame(1, array_count_values($titles)['Org News'] ?? 0);
        $this->assertContains('Troop News', $titles);
    }

    public function testLoadSurfacesUnacknowledgedNotices(): void
    {
        $noticeId = $this->db->insert('notices', [
            'title' => 'Important', 'content' => 'Read this',
            'type' => 'must_acknowledge', 'is_active' => 1,
        ]);

        $svc = new MemberDashboardService($this->db);
        $data = $svc->load($this->memberId, $this->userId);
        $this->assertCount(1, $data['notices']);
        $this->assertSame('Important', $data['notices'][0]['title']);

        // After the user acknowledges, it should disappear.
        $this->db->insert('notice_acknowledgements', [
            'notice_id' => $noticeId, 'user_id' => $this->userId,
        ]);
        $data = $svc->load($this->memberId, $this->userId);
        $this->assertSame([], $data['notices']);
    }
}
