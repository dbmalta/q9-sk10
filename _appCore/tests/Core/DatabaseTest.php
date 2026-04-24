<?php

declare(strict_types=1);

namespace Tests\Core;

use AppCore\Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration test demonstrating the conventions:
 *
 * - Skip cleanly when no test-DB env vars are set.
 * - Hit a real database rather than mocking PDO.
 * - Tear down test data in tearDown().
 */
class DatabaseTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        $config = appcore_test_db_config();
        if ($config === null) {
            $this->markTestSkipped('Test DB env vars not set.');
        }
        $this->db = new Database($config);
        $this->db->query("CREATE TABLE IF NOT EXISTS `_test_fixture` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("TRUNCATE `_test_fixture`");
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query("DROP TABLE IF EXISTS `_test_fixture`");
        }
    }

    public function testInsertAndFetchRoundtrip(): void
    {
        $id = $this->db->insert('_test_fixture', ['name' => 'Alice']);
        $this->assertGreaterThan(0, $id);

        $row = $this->db->fetchOne("SELECT name FROM _test_fixture WHERE id = :id", ['id' => $id]);
        $this->assertSame('Alice', $row['name']);
    }

    public function testUpdateReturnsAffectedRowCount(): void
    {
        $this->db->insert('_test_fixture', ['name' => 'Bob']);
        $count = $this->db->update('_test_fixture', ['name' => 'Robert'], ['name' => 'Bob']);
        $this->assertSame(1, $count);
    }

    public function testTransactionRollbackLeavesNoTrace(): void
    {
        $this->db->beginTransaction();
        $this->db->insert('_test_fixture', ['name' => 'Carol']);
        $this->db->rollback();

        $count = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM _test_fixture");
        $this->assertSame(0, $count);
    }
}
