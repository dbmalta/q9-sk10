<?php

declare(strict_types=1);

namespace Tests\Modules\Communications;

use App\Core\Database;
use App\Modules\Communications\Services\ArticleService;
use PHPUnit\Framework\TestCase;

class ArticleScopingTest extends TestCase
{
    private Database $db;
    private int $nodeA;
    private int $nodeB;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['articles', 'org_nodes', 'users'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("CREATE TABLE `org_nodes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(200) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(200) NOT NULL
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

        $this->nodeA = $this->db->insert('org_nodes', ['name' => 'A']);
        $this->nodeB = $this->db->insert('org_nodes', ['name' => 'B']);

        $this->db->insert('articles', ['title' => 'Org-wide',  'slug' => 'o', 'node_scope_id' => null]);
        $this->db->insert('articles', ['title' => 'Node A',    'slug' => 'a', 'node_scope_id' => $this->nodeA]);
        $this->db->insert('articles', ['title' => 'Node B',    'slug' => 'b', 'node_scope_id' => $this->nodeB]);
    }

    public function testGetAllUnscopedReturnsEverything(): void
    {
        $svc = new ArticleService($this->db);
        $titles = array_column($svc->getAll(1, 20)['items'], 'title');
        sort($titles);
        $this->assertSame(['Node A', 'Node B', 'Org-wide'], $titles);
    }

    public function testGetAllScopedToNodeAIncludesOrgWide(): void
    {
        $svc = new ArticleService($this->db);
        $titles = array_column($svc->getAll(1, 20, [$this->nodeA])['items'], 'title');
        sort($titles);
        $this->assertSame(['Node A', 'Org-wide'], $titles);
    }

    public function testGetAllScopedExcludesOtherNodes(): void
    {
        $svc = new ArticleService($this->db);
        $titles = array_column($svc->getAll(1, 20, [$this->nodeA])['items'], 'title');
        $this->assertNotContains('Node B', $titles);
    }
}
