<?php

declare(strict_types=1);

namespace Tests\Modules\Directory;

use App\Core\Database;
use App\Modules\Directory\Services\DirectoryService;
use PHPUnit\Framework\TestCase;

class DirectoryScopingTest extends TestCase
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
        foreach (['role_assignments', 'roles', 'members', 'users', 'org_nodes'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("CREATE TABLE `org_nodes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(200) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `members` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `first_name` VARCHAR(100) NOT NULL,
            `surname` VARCHAR(100) NOT NULL,
            `email` VARCHAR(255) NULL,
            `phone` VARCHAR(50) NULL,
            `user_id` INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `roles` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `is_directory_visible` TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `role_assignments` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `role_id` INT UNSIGNED NOT NULL,
            `context_type` ENUM('node','team') NOT NULL DEFAULT 'node',
            `context_id` INT UNSIGNED NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->nodeA = $this->db->insert('org_nodes', ['name' => 'Alpha']);
        $this->nodeB = $this->db->insert('org_nodes', ['name' => 'Beta']);
        $roleId = $this->db->insert('roles', ['name' => 'Leader', 'is_directory_visible' => 1]);

        $u1 = $this->db->insert('users', ['email' => 'a@t']);
        $u2 = $this->db->insert('users', ['email' => 'b@t']);
        $this->db->insert('members', ['first_name' => 'Alice', 'surname' => 'A', 'email' => 'a@t', 'user_id' => $u1]);
        $this->db->insert('members', ['first_name' => 'Bob',   'surname' => 'B', 'email' => 'b@t', 'user_id' => $u2]);

        $this->db->insert('role_assignments', [
            'user_id' => $u1, 'role_id' => $roleId,
            'context_type' => 'node', 'context_id' => $this->nodeA, 'start_date' => '2020-01-01',
        ]);
        $this->db->insert('role_assignments', [
            'user_id' => $u2, 'role_id' => $roleId,
            'context_type' => 'node', 'context_id' => $this->nodeB, 'start_date' => '2020-01-01',
        ]);
    }

    public function testContactDirectoryScopedToNodeAReturnsOnlyAlice(): void
    {
        $svc = new DirectoryService($this->db);
        $contacts = $svc->getContactDirectory(null, null, [$this->nodeA]);
        $names = array_column($contacts, 'member_name');
        $this->assertSame(['Alice A'], $names);
    }

    public function testContactDirectoryUnscopedReturnsAll(): void
    {
        $svc = new DirectoryService($this->db);
        $contacts = $svc->getContactDirectory();
        $this->assertCount(2, $contacts);
    }
}
