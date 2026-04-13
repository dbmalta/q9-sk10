<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Database tests — require a running MySQL test database.
 *
 * These tests are skipped if the test database is not available.
 * Set up the test database with:
 *   CREATE DATABASE scoutkeeper_test;
 *   CREATE USER 'sk_test'@'localhost' IDENTIFIED BY 'sk_test_pass';
 *   GRANT ALL PRIVILEGES ON scoutkeeper_test.* TO 'sk_test'@'localhost';
 */
class DatabaseTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
            $this->db->query("CREATE TABLE IF NOT EXISTS `_test_table` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `value` INT DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $this->db->query("TRUNCATE TABLE `_test_table`");
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->query("DROP TABLE IF EXISTS `_test_table`");
        }
    }

    public function testInsertReturnsId(): void
    {
        $id = $this->db->insert('_test_table', ['name' => 'test', 'value' => 42]);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testFetchOneReturnsRow(): void
    {
        $this->db->insert('_test_table', ['name' => 'find_me', 'value' => 99]);

        $row = $this->db->fetchOne("SELECT * FROM _test_table WHERE name = :name", ['name' => 'find_me']);
        $this->assertNotNull($row);
        $this->assertSame('find_me', $row['name']);
        $this->assertSame(99, (int) $row['value']);
    }

    public function testFetchOneReturnsNullWhenNotFound(): void
    {
        $row = $this->db->fetchOne("SELECT * FROM _test_table WHERE name = :name", ['name' => 'nonexistent']);
        $this->assertNull($row);
    }

    public function testFetchAll(): void
    {
        $this->db->insert('_test_table', ['name' => 'a', 'value' => 1]);
        $this->db->insert('_test_table', ['name' => 'b', 'value' => 2]);
        $this->db->insert('_test_table', ['name' => 'c', 'value' => 3]);

        $rows = $this->db->fetchAll("SELECT * FROM _test_table ORDER BY name");
        $this->assertCount(3, $rows);
        $this->assertSame('a', $rows[0]['name']);
    }

    public function testFetchColumn(): void
    {
        $this->db->insert('_test_table', ['name' => 'count_test', 'value' => 1]);
        $this->db->insert('_test_table', ['name' => 'count_test2', 'value' => 2]);

        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM _test_table");
        $this->assertSame(2, (int) $count);
    }

    public function testUpdate(): void
    {
        $id = $this->db->insert('_test_table', ['name' => 'update_me', 'value' => 1]);

        $affected = $this->db->update('_test_table', ['value' => 100], ['id' => $id]);
        $this->assertSame(1, $affected);

        $row = $this->db->fetchOne("SELECT * FROM _test_table WHERE id = :id", ['id' => $id]);
        $this->assertSame(100, (int) $row['value']);
    }

    public function testDelete(): void
    {
        $id = $this->db->insert('_test_table', ['name' => 'delete_me']);

        $affected = $this->db->delete('_test_table', ['id' => $id]);
        $this->assertSame(1, $affected);

        $row = $this->db->fetchOne("SELECT * FROM _test_table WHERE id = :id", ['id' => $id]);
        $this->assertNull($row);
    }

    public function testTransactionCommit(): void
    {
        $this->db->beginTransaction();
        $this->db->insert('_test_table', ['name' => 'tx_test']);
        $this->db->commit();

        $row = $this->db->fetchOne("SELECT * FROM _test_table WHERE name = :n", ['n' => 'tx_test']);
        $this->assertNotNull($row);
    }

    public function testTransactionRollback(): void
    {
        $this->db->beginTransaction();
        $this->db->insert('_test_table', ['name' => 'rollback_test']);
        $this->db->rollback();

        $row = $this->db->fetchOne("SELECT * FROM _test_table WHERE name = :n", ['n' => 'rollback_test']);
        $this->assertNull($row);
    }
}
